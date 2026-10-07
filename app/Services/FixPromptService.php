<?php

namespace App\Services;

use App\Enums\IncidentCause;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\MonitorIncident;

/**
 * Generates a rich, pre-filled Claude Code prompt for fixing a monitoring
 * incident or copilot insight.
 *
 * WHY a dedicated service: the prompt assembly logic is non-trivial (diagnosis
 * heuristics, repo resolution, payload normalisation for each InsightType) and
 * must be testable independently of the HTTP layer.
 *
 * The service is deliberately defensive — every piece of context is optional so
 * that a missing repo, empty checks or unknown insight type always produces a
 * usable (if slightly less detailed) prompt rather than an exception.
 */
class FixPromptService
{
    public function __construct(
        private readonly DokployRepoResolver $resolver,
    ) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Generate a prompt for a MonitorIncident.
     */
    public function forIncident(MonitorIncident $incident): string
    {
        $monitor = $incident->monitor;
        $host = $this->hostFromUrl($monitor->url ?? '');
        $repoInfo = $host ? $this->resolver->resolveRepoForHost($host) : null;

        // Recent checks give Claude concrete evidence of what went wrong.
        $recentChecks = $monitor->checks()
            ->latest('checked_at')
            ->limit(5)
            ->get(['status', 'response_time_ms', 'status_code', 'error_message', 'checked_at']);

        $diagnosis = $this->diagnoseIncident($incident, $recentChecks);

        // Flapping count helps Claude understand whether this is a recurring issue.
        $recentIncidentCount = $monitor->incidents()
            ->where('started_at', '>=', now()->subDays(7))
            ->count();

        $lines = [];

        $lines[] = "Investigate and fix this production incident on {$host}.";
        $lines[] = '';
        $lines[] = 'Context (from Up monitoring):';
        $lines[] = "- Site: {$host}";
        $lines[] = "- Monitored URL: {$monitor->url}";
        $lines[] = '- Repo: '.($repoInfo['repo'] ?? 'unknown — resolve manually');

        if (! empty($repoInfo['branch'])) {
            $lines[] = "- Branch: {$repoInfo['branch']}";
        }

        $causeHuman = $this->humanCause($incident->cause);
        $severityLabel = $incident->severity?->label() ?? 'unknown';
        $lines[] = "- Incident cause: {$causeHuman} ({$severityLabel})";
        $lines[] = '- Started: '.$incident->started_at->format('Y-m-d H:i:s T');
        $lines[] = $incident->resolved_at
            ? '- Resolved: '.$incident->resolved_at->format('Y-m-d H:i:s T')
            : '- Status: still ongoing (not yet resolved)';

        $lines[] = "- Incidents in the last 7 days: {$recentIncidentCount}"
            .($recentIncidentCount >= 3 ? ' (potential flapping — investigate root cause carefully)' : '');

        if ($recentChecks->isNotEmpty()) {
            $lines[] = '- Recent checks (newest first):';
            foreach ($recentChecks as $check) {
                $status = $check->status->value ?? 'unknown';
                $rt = $check->response_time_ms !== null ? "{$check->response_time_ms} ms" : 'n/a';
                $code = $check->status_code ?? '-';
                $err = $check->error_message ? " | {$check->error_message}" : '';
                $ts = $check->checked_at?->format('H:i:s') ?? '';
                $lines[] = "  [{$ts}] {$status} | {$rt} | HTTP {$code}{$err}";
            }
        }

        if ($diagnosis) {
            $lines[] = "- Auto-diagnosis: {$diagnosis}";
        }

        $lines[] = '';
        $repo = $repoInfo['repo'] ?? 'the repository';
        $focusHint = $this->focusHintForCause($incident->cause);
        $lines[] = "Goal: clone/open {$repo}, identify the root cause of the above symptom,";
        $lines[] = "and propose a concrete fix. Focus on {$focusHint}.";

        return implode("\n", $lines);
    }

    /**
     * Generate a prompt for an Insight (copilot layer).
     */
    public function forInsight(Insight $insight): string
    {
        $host = $this->hostFromUrl($insight->site ?? '');
        $repoInfo = $host ? $this->resolver->resolveRepoForHost($host) : null;

        // If the insight is linked to a monitor, include its full URL for precision.
        $monitorUrl = null;
        if ($insight->monitor_id) {
            $monitorUrl = $insight->monitor?->url;
        }

        $typeLabel = $insight->type->label();
        $severityLabel = $insight->severity->label();
        $payload = $insight->payload ?? [];

        $headline = $this->insightHeadline($insight->type, $payload, $insight->title);
        $goal = $this->insightGoal($insight->type, $payload, $host);

        $lines = [];

        $lines[] = $headline;
        $lines[] = '';
        $lines[] = 'Context (from Up copilot):';
        $lines[] = "- Site: {$host}";

        if ($monitorUrl) {
            $lines[] = "- Monitored URL: {$monitorUrl}";
        }

        $lines[] = '- Repo: '.($repoInfo['repo'] ?? 'unknown — resolve manually');

        if (! empty($repoInfo['branch'])) {
            $lines[] = "- Branch: {$repoInfo['branch']}";
        }

        $lines[] = "- Insight type: {$typeLabel} ({$severityLabel})";
        $lines[] = "- Title: {$insight->title}";

        // Include the most relevant payload fields for each insight type.
        $payloadLines = $this->relevantPayloadLines($insight->type, $payload);
        foreach ($payloadLines as $payloadLine) {
            $lines[] = "- {$payloadLine}";
        }

        if ($insight->detected_at) {
            $lines[] = '- Detected: '.$insight->detected_at->format('Y-m-d H:i:s T');
        }

        $lines[] = '';
        $repo = $repoInfo['repo'] ?? 'the repository';
        $lines[] = "Goal: clone/open {$repo} and {$goal}";

        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------------
    // Incident helpers
    // -----------------------------------------------------------------------

    /**
     * Build a concise automatic diagnosis string from recent check data.
     * Returns an empty string when there is not enough data to say anything useful.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\MonitorCheck>  $recentChecks
     */
    private function diagnoseIncident(MonitorIncident $incident, \Illuminate\Support\Collection $recentChecks): string
    {
        // Prefer the incident cause enum as the primary signal — it was already
        // determined by the checker and is more reliable than heuristics.
        $causeHints = match ($incident->cause) {
            IncidentCause::TIMEOUT => $this->diagnoseFromChecks($recentChecks),
            IncidentCause::STATUS_CODE => $this->diagnoseStatusCode($recentChecks),
            IncidentCause::SSL => 'SSL certificate error — check expiry date and chain validity',
            IncidentCause::KEYWORD => 'expected keyword not found in response body — content may have changed',
            IncidentCause::FUNCTIONAL => 'a functional check scenario failed — review the check steps',
            IncidentCause::BUSINESS_REGRESSION => 'a KPI metric regressed beyond its threshold',
            IncidentCause::FAILED_SMOKE_TEST => 'post-deploy smoke test failed — check the last deployment',
            default => $this->diagnoseFromChecks($recentChecks),
        };

        return $causeHints;
    }

    /**
     * Diagnose from raw check metrics when the cause is TIMEOUT or generic ERROR.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\MonitorCheck>  $recentChecks
     */
    private function diagnoseFromChecks(\Illuminate\Support\Collection $recentChecks): string
    {
        if ($recentChecks->isEmpty()) {
            return '';
        }

        // Collect error messages to detect timeout keywords.
        $errors = $recentChecks->pluck('error_message')->filter()->map(fn ($e) => strtolower($e));
        $hasTimeoutError = $errors->contains(fn ($e) => str_contains($e, 'timeout'));

        // Compute average response time from checks that have a measurement.
        $withRt = $recentChecks->whereNotNull('response_time_ms');
        $avgRt = $withRt->isNotEmpty()
            ? (int) round($withRt->avg('response_time_ms'))
            : null;

        if ($hasTimeoutError && $avgRt !== null) {
            return "intermittent timeouts (avg response time {$avgRt} ms) — check server load or upstream dependencies";
        }

        if ($hasTimeoutError) {
            return 'intermittent timeouts — check server load or upstream dependencies';
        }

        if ($avgRt !== null && $avgRt > 2000) {
            return "endpoint is slow (avg {$avgRt} ms, threshold 2000 ms) — check server load, query performance or upstream latency";
        }

        return '';
    }

    /**
     * Diagnose a STATUS_CODE incident from the most common failing HTTP status.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\MonitorCheck>  $recentChecks
     */
    private function diagnoseStatusCode(\Illuminate\Support\Collection $recentChecks): string
    {
        // Find the most frequent non-null status code.
        $codes = $recentChecks->pluck('status_code')->filter();

        if ($codes->isEmpty()) {
            return 'unexpected HTTP status code returned by the server';
        }

        /** @var int $mostCommon */
        $mostCommon = $codes->countBy()->sortDesc()->keys()->first();

        if ($mostCommon >= 500) {
            return "server-side error (HTTP {$mostCommon}) — check application logs and recent deployments";
        }

        if ($mostCommon >= 400) {
            return "client/routing error (HTTP {$mostCommon}) — check URL configuration, proxy rules or authentication";
        }

        return "unexpected HTTP {$mostCommon} — verify expected status code configuration";
    }

    /**
     * Convert an IncidentCause enum to a human-readable string.
     */
    private function humanCause(?IncidentCause $cause): string
    {
        if ($cause === null) {
            return 'unknown';
        }

        return match ($cause) {
            IncidentCause::TIMEOUT => 'request timeout',
            IncidentCause::STATUS_CODE => 'unexpected HTTP status code',
            IncidentCause::KEYWORD => 'expected keyword missing from response',
            IncidentCause::SSL => 'SSL certificate error',
            IncidentCause::ERROR => 'connection/network error',
            IncidentCause::FUNCTIONAL => 'functional check failure',
            IncidentCause::BUSINESS_REGRESSION => 'business KPI regression',
            IncidentCause::FAILED_SMOKE_TEST => 'post-deploy smoke test failure',
        };
    }

    /**
     * Return a specific investigation focus hint based on the incident cause.
     */
    private function focusHintForCause(?IncidentCause $cause): string
    {
        return match ($cause) {
            IncidentCause::TIMEOUT, IncidentCause::ERROR => 'performance, connection handling, and upstream service dependencies',
            IncidentCause::STATUS_CODE => 'HTTP routing, authentication middleware, and recent deploy changes',
            IncidentCause::SSL => 'SSL certificate configuration, renewal pipeline, and TLS settings',
            IncidentCause::KEYWORD => 'page content integrity, CMS changes, and A/B test flags',
            IncidentCause::FUNCTIONAL => 'the failing functional check scenario steps and dependent services',
            IncidentCause::BUSINESS_REGRESSION => 'the regressed KPI metric, recent code changes, and data pipeline',
            IncidentCause::FAILED_SMOKE_TEST => 'the failing smoke test assertions and the last deployment diff',
            default => 'availability, performance, and recent code changes',
        };
    }

    // -----------------------------------------------------------------------
    // Insight helpers
    // -----------------------------------------------------------------------

    /**
     * Build the opening headline sentence for an insight prompt.
     *
     * @param  array<string, mixed>  $payload
     */
    private function insightHeadline(InsightType $type, array $payload, string $fallbackTitle): string
    {
        return match ($type) {
            InsightType::REVENUE_AT_RISK => sprintf(
                'Fix broken page "%s" returning HTTP %s — %s monthly clicks at risk.',
                $payload['page'] ?? 'unknown',
                $payload['status_code'] ?? '???',
                number_format((int) ($payload['clicks'] ?? 0)),
            ),

            InsightType::CONTENT_DECAY => sprintf(
                'Refresh decaying content on "%s" — %s%% of clicks lost recently.',
                $payload['page'] ?? 'unknown',
                $payload['decline_pct'] ?? '?',
            ),

            InsightType::STRIKING_DISTANCE => $this->strikingDistanceHeadline($payload),

            InsightType::PERF_REGRESSION => sprintf(
                'Fix performance regression on "%s" — %s.',
                $payload['page'] ?? 'the site',
                $payload['detail'] ?? 'performance score dropped',
            ),

            InsightType::HEALTH_DROP => sprintf(
                'Investigate health score drop on "%s" — overall score fell to %s.',
                $payload['site'] ?? 'the site',
                $payload['score'] ?? '?',
            ),

            default => "Investigate and fix: {$fallbackTitle}.",
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function strikingDistanceHeadline(array $payload): string
    {
        $page = $payload['page'] ?? 'unknown';
        $query = trim((string) ($payload['query'] ?? ''));

        if ($query === '') {
            return sprintf('Optimize "%s" to rank higher for its near-page-one queries.', $page);
        }

        if (! array_key_exists('position', $payload) || $payload['position'] === null) {
            return sprintf('Optimize "%s" to rank higher for \'%s\'.', $page, $query);
        }

        $position = is_float($payload['position'])
            ? number_format($payload['position'], 1, '.', '')
            : $payload['position'];

        return sprintf(
            'Optimize "%s" to rank higher for \'%s\' (currently position %s).',
            $page,
            $query,
            $position,
        );
    }

    /**
     * Build the "Goal:" line for an insight prompt.
     *
     * @param  array<string, mixed>  $payload
     */
    private function insightGoal(InsightType $type, array $payload, string $host): string
    {
        return match ($type) {
            InsightType::REVENUE_AT_RISK => sprintf(
                'fix the broken page "%s" (returning HTTP %s). Restore it to return 200 and ensure the content is correct.',
                $payload['page'] ?? 'unknown',
                $payload['status_code'] ?? '???',
            ),

            InsightType::CONTENT_DECAY => sprintf(
                'refresh the content on "%s" to recover organic traffic. Update copy, add new information, fix broken links, or improve on-page SEO.',
                $payload['page'] ?? 'unknown',
            ),

            InsightType::STRIKING_DISTANCE => sprintf(
                'optimise "%s" for the query \'%s\' (currently ranked at position %s). Improve title, headings, content depth and internal linking to reach the top 10.',
                $payload['page'] ?? 'unknown',
                $payload['query'] ?? 'unknown',
                $payload['position'] ?? '?',
            ),

            InsightType::PERF_REGRESSION => sprintf(
                'investigate and fix the performance regression on %s. Profile Core Web Vitals, identify the regressed metric, and optimise.',
                $host,
            ),

            default => "investigate the issue described above and propose a concrete fix for {$host}.",
        };
    }

    /**
     * Return key/value lines from the payload that are most useful for each type.
     *
     * @param  array<string, mixed>  $payload
     * @return string[]
     */
    private function relevantPayloadLines(InsightType $type, array $payload): array
    {
        return match ($type) {
            InsightType::REVENUE_AT_RISK => array_filter([
                isset($payload['page']) ? "Broken page: {$payload['page']}" : null,
                isset($payload['status_code']) ? "HTTP status: {$payload['status_code']}" : null,
                isset($payload['clicks']) ? 'Monthly clicks at risk: '.number_format((int) $payload['clicks']) : null,
            ]),

            InsightType::CONTENT_DECAY => array_filter([
                isset($payload['page']) ? "Decaying page: {$payload['page']}" : null,
                isset($payload['decline_pct']) ? "Click decline: {$payload['decline_pct']}%" : null,
            ]),

            InsightType::STRIKING_DISTANCE => array_filter([
                isset($payload['query']) ? "Target query: {$payload['query']}" : null,
                isset($payload['page']) ? "Target page: {$payload['page']}" : null,
                isset($payload['position']) ? "Current position: {$payload['position']}" : null,
                isset($payload['impressions']) ? "Impressions: {$payload['impressions']}" : null,
                isset($payload['clicks']) ? "Clicks: {$payload['clicks']}" : null,
            ]),

            InsightType::PERF_REGRESSION => array_filter([
                isset($payload['metric']) ? "Regressed metric: {$payload['metric']}" : null,
                isset($payload['before']) ? "Before: {$payload['before']}" : null,
                isset($payload['after']) ? "After: {$payload['after']}" : null,
            ]),

            // Every other type: dump the scalar payload pairs rather than
            // nothing. Only 4 of the ~20 insight types have a bespoke branch,
            // so the generic prompt used to ship with zero data from the
            // detector — the assistant received "Investigate and fix: <title>"
            // and none of the numbers the detector had already computed.
            default => $this->scalarPayloadLines($payload),
        };
    }

    /**
     * Fallback context: every scalar key/value pair from the payload,
     * humanised. Detector payloads are flat and small, so this stays short.
     *
     * @param  array<string, mixed>  $payload
     * @return string[]
     */
    private function scalarPayloadLines(array $payload): array
    {
        $lines = [];

        foreach ($payload as $key => $value) {
            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $label = ucfirst(str_replace('_', ' ', (string) $key));
            $lines[] = "{$label}: ".(is_bool($value) ? ($value ? 'yes' : 'no') : $value);
        }

        return $lines;
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * Extract a plain hostname from any URL or bare hostname string.
     */
    private function hostFromUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }

        return strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    }
}
