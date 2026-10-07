<?php

namespace App\Http\Controllers;

use App\Models\Insight;
use App\Models\MonitorIncident;
use App\Services\DokployRepoResolver;
use App\Services\FixPromptService;
use Inertia\Inertia;

/**
 * Renders the "Fix with Claude" page for a given incident or insight.
 *
 * WHY a separate controller: the fix-prompt workflow is read-only (no state
 * mutation) but requires non-trivial orchestration (repo resolution + prompt
 * assembly). Keeping it isolated makes the route table readable and the
 * controller under test independently.
 *
 * Authorization:
 *   - MonitorIncident: uses `update` on the incident (defined in
 *     MonitorIncidentPolicy — team_id check via monitor relationship).
 *     There is no separate `view` gate; `update` is the correct gate here
 *     because any team member who can edit an incident can also inspect it.
 *   - Insight: uses `update` on the insight (defined in InsightPolicy —
 *     direct team_id check on the insight model).
 */
class FixPromptController extends Controller
{
    /**
     * Show the fix-with-Claude prompt for a monitor incident.
     */
    public function incident(MonitorIncident $incident, FixPromptService $service, DokployRepoResolver $resolver): \Inertia\Response
    {
        // Eager-load the monitor so the policy and service don't fire extra queries.
        $incident->loadMissing('monitor');

        $this->authorize('update', $incident);

        $prompt = $service->forIncident($incident);

        $host = $this->hostFromUrl($incident->monitor->url ?? '');

        return Inertia::render('FixPrompt', [
            'kind' => 'incident',
            'title' => "Fix incident on {$host}",
            'prompt' => $prompt,
            'site' => $host,
            'repo' => $this->repoContext($resolver, $host),
            'causeLabel' => $incident->cause ? ucfirst(str_replace('_', ' ', $incident->cause->value)) : null,
            'severityLabel' => $incident->severity?->label(),
            'startedAt' => $incident->started_at?->toIso8601String(),
            'resolvedAt' => $incident->resolved_at?->toIso8601String(),
        ]);
    }

    /**
     * Show the fix-with-Claude prompt for a copilot insight.
     */
    public function insight(Insight $insight, FixPromptService $service, DokployRepoResolver $resolver): \Inertia\Response
    {
        $insight->loadMissing('monitor');

        $this->authorize('update', $insight);

        $prompt = $service->forInsight($insight);

        $host = $this->hostFromUrl($insight->site ?? '');

        return Inertia::render('FixPrompt', [
            'kind' => 'insight',
            'title' => $insight->title,
            'prompt' => $prompt,
            'site' => $host,
            'repo' => $this->repoContext($resolver, $host),
            'typeLabel' => $insight->type->label(),
            'severityLabel' => $insight->severity->label(),
            'detectedAt' => $insight->detected_at?->toIso8601String(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Structured repo context for the page.
     *
     * The resolver caches per host (misses included), so asking it again after
     * FixPromptService already did costs a cache read — which is why the
     * previous approach of regex-parsing the repo back OUT of the generated
     * prompt text (and discarding branch/app_id in the process) is gone.
     *
     * @return array{url: string, branch: string|null, app_id: string|null, app_name: string|null}|null
     */
    private function repoContext(DokployRepoResolver $resolver, string $host): ?array
    {
        if ($host === '') {
            return null;
        }

        $info = $resolver->resolveRepoForHost($host);

        if ($info === null || empty($info['repo'])) {
            return null;
        }

        return [
            'url' => $info['repo'],
            'branch' => $info['branch'] ?? null,
            'app_id' => $info['app_id'] ?? null,
            'app_name' => $info['app_name'] ?? null,
        ];
    }

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
