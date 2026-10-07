<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Support\Facades\Cache;

/**
 * Detects resource-pressure thresholds on a server and persists SERVER_HEALTH
 * Insight rows when a metric is in warning or critical territory.
 *
 * Alerting strategy
 * ─────────────────
 * • Disk / RAM: alert immediately on the first breaching sample.
 * • CPU: alert only when the last N consecutive metrics all breach the threshold
 *   (configurable via server_health.cpu_sustained_points). This filters out short
 *   spikes — a build job or cron burst should not page the team.
 *
 * Anti-spam
 * ─────────
 * The detector keeps one unacknowledged SERVER_HEALTH Insight per server/metric.
 * A continuing breach refreshes that row in place so severity, title, payload
 * and impact_score reflect the current metric while detected_at remains the
 * first time the breach was seen. SeoAlertService handles notified_at cooldowns
 * on its own — this detector only prevents row accumulation.
 *
 * The created Insight payload includes the list of sites hosted on the server so
 * the alert immediately tells the operator which properties are affected.
 */
class ServerHealthDetector
{
    /**
     * Evaluate a freshly-collected metric against the configured thresholds and
     * create SERVER_HEALTH Insight rows as needed.
     *
     * Up to three Insights may be created per call (one per metric dimension).
     * Returns the number of new Insights created.
     */
    public function evaluate(Server $server, ServerMetric $metric): int
    {
        $cfg = $server->thresholds();
        $created = 0;

        // Receiving any metric means the agent is alive again — clear a heartbeat
        // alert that the dead-man switch may have raised while it was silent.
        $this->resolveAlerts($server, 'heartbeat');

        $created += $this->evaluateDisk($server, $metric, $cfg);
        $created += $this->evaluateRam($server, $metric, $cfg);
        $created += $this->evaluateCpu($server, $metric, $cfg);
        $created += $this->evaluateLoad($server, $metric, $cfg);

        return $created;
    }

    // -------------------------------------------------------------------------
    // Per-metric evaluators
    // -------------------------------------------------------------------------

    private function evaluateDisk(Server $server, ServerMetric $metric, array $cfg): int
    {
        $value = (float) $metric->disk_percent;
        $severity = $this->thresholdSeverity($value, $cfg['disk_warning'], $cfg['disk_critical']);

        if ($severity === null) {
            $this->resolveAlerts($server, 'disk');

            return 0;
        }

        return $this->refreshOrCreateAlert($server, 'disk', [
            'team_id' => $server->team_id,
            'site' => $server->name,
            'server_id' => $server->id,
            'monitor_id' => null,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => $severity->value,
            'title' => sprintf('Server %s: disk at %s%%', $server->name, number_format($value, 1)),
            'payload' => $this->buildPayload($server, $metric, 'disk', $cfg['disk_warning'], $value),
            'impact_score' => $this->impactScore($value, $cfg['disk_warning']),
        ]);
    }

    private function evaluateRam(Server $server, ServerMetric $metric, array $cfg): int
    {
        $value = (float) $metric->ram_percent;
        $severity = $this->thresholdSeverity($value, $cfg['ram_warning'], $cfg['ram_critical']);

        if ($severity === null) {
            $this->resolveAlerts($server, 'ram');

            return 0;
        }

        return $this->refreshOrCreateAlert($server, 'ram', [
            'team_id' => $server->team_id,
            'site' => $server->name,
            'server_id' => $server->id,
            'monitor_id' => null,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => $severity->value,
            'title' => sprintf('Server %s: RAM at %s%%', $server->name, number_format($value, 1)),
            'payload' => $this->buildPayload($server, $metric, 'ram', $cfg['ram_warning'], $value),
            'impact_score' => $this->impactScore($value, $cfg['ram_warning']),
        ]);
    }

    private function evaluateCpu(Server $server, ServerMetric $metric, array $cfg): int
    {
        $points = (int) $cfg['cpu_sustained_points'];

        // Fetch the N most recent metrics for this server (including the current one
        // which is already persisted by the time evaluate() is called).
        $recent = ServerMetric::where('server_id', $server->id)
            ->orderByDesc('captured_at')
            ->limit($points)
            ->pluck('cpu_percent');

        // Not enough history yet — skip alerting during bootstrap. Still resolve any
        // open CPU alert if the current sample is healthy, so an alert never lingers
        // just because the window shrank (e.g. after pruning).
        if ($recent->count() < $points) {
            if ((float) $metric->cpu_percent < (float) $cfg['cpu_warning']) {
                $this->resolveAlerts($server, 'cpu');
            }

            return 0;
        }

        $values = $recent->map(fn ($v): float => (float) $v);

        // Determine whether ALL sustained points breach warning or critical.
        $allCritical = $values->every(fn (float $v): bool => $v >= $cfg['cpu_critical']);
        $allWarning = $values->every(fn (float $v): bool => $v >= $cfg['cpu_warning']);

        $severity = match (true) {
            $allCritical => InsightSeverity::CRITICAL,
            $allWarning => InsightSeverity::WARNING,
            default => null,
        };

        if ($severity === null) {
            $this->resolveAlerts($server, 'cpu');

            return 0;
        }

        $value = (float) $metric->cpu_percent;
        $threshold = $allCritical ? $cfg['cpu_critical'] : $cfg['cpu_warning'];

        return $this->refreshOrCreateAlert($server, 'cpu', [
            'team_id' => $server->team_id,
            'site' => $server->name,
            'server_id' => $server->id,
            'monitor_id' => null,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => $severity->value,
            'title' => sprintf(
                'Server %s: CPU sustained at %s%% (%d consecutive samples)',
                $server->name,
                number_format($value, 1),
                $points
            ),
            'payload' => $this->buildPayload($server, $metric, 'cpu', $threshold, $value),
            'impact_score' => $this->impactScore($value, $threshold),
        ]);
    }

    private function evaluateLoad(Server $server, ServerMetric $metric, array $cfg): int
    {
        // load_avg_5 is nullable — older shell agents omit it. Skip silently.
        if ($metric->load_avg_5 === null) {
            return 0;
        }

        // Core count must be known to compute a meaningful ratio. Without it we
        // cannot distinguish a healthy 64-core machine (load 13 = 20%) from a
        // saturated single-core box (load 1.0 = 100%). Default 0 = unknown = no
        // alerting. Operator sets settings.thresholds.load_cores per-server to
        // enable load alerting (e.g. load_cores=64 on prod-server).
        $cores = (int) ($cfg['load_cores'] ?? $cfg['load_cores_default']);

        if ($cores <= 0) {
            return 0;
        }

        $load5 = (float) $metric->load_avg_5;
        $ratio = $load5 / $cores;

        $severity = $this->thresholdSeverity($ratio, $cfg['load_warning'], $cfg['load_critical']);

        if ($severity === null) {
            $this->resolveAlerts($server, 'load');

            return 0;
        }

        return $this->refreshOrCreateAlert($server, 'load', [
            'team_id' => $server->team_id,
            'site' => $server->name,
            'server_id' => $server->id,
            'monitor_id' => null,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => $severity->value,
            'title' => sprintf(
                'High server load: 5-min load %.0f on %d cores (ratio %.2f)',
                $load5,
                $cores,
                $ratio,
            ),
            'payload' => array_merge(
                $this->buildPayload($server, $metric, 'load', $cfg['load_warning'], $ratio),
                ['load_avg_5' => $load5, 'cores' => $cores, 'ratio' => round($ratio, 4)],
            ),
            'impact_score' => $this->impactScore($ratio, $cfg['load_warning']),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns WARNING, CRITICAL, or null (no breach) for a single percentage value.
     */
    private function thresholdSeverity(float $value, float $warning, float $critical): ?InsightSeverity
    {
        if ($value >= $critical) {
            return InsightSeverity::CRITICAL;
        }

        if ($value >= $warning) {
            return InsightSeverity::WARNING;
        }

        return null;
    }

    /**
     * Open SERVER_HEALTH Insights for this server and metric dimension.
     *
     * payload->server_id narrows to the exact server.
     * payload->metric narrows to the exact dimension (disk|ram|cpu|load).
     * withoutGlobalScopes() is required: no auth() in job context (ScopedByTeam).
     */
    private function openAlerts(Server $server, string $metricName)
    {
        return Insight::openUnacknowledgedOfType(InsightType::SERVER_HEALTH, fn ($query) => $query
            ->where('payload->server_id', $server->id)
            ->where('payload->metric', $metricName));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function refreshOrCreateAlert(Server $server, string $metricName, array $attributes): int
    {
        $existing = $this->openAlerts($server, $metricName)
            ->oldest('detected_at')
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return 0;
        }

        Insight::create(array_merge($attributes, ['detected_at' => now()]));

        return 1;
    }

    /**
     * Impact score: percentage points above the warning threshold, rounded to 2dp.
     * Gives the UI a stable numeric signal for sorting/colouring.
     */
    private function impactScore(float $value, float $warningThreshold): float
    {
        return round(max(0.0, $value - $warningThreshold), 2);
    }

    /**
     * Auto-resolve open SERVER_HEALTH alerts for a given server/metric dimension when
     * the metric drops back below the warning threshold.
     *
     * "Resolved" means acknowledged_at is stamped with now(). This unblocks the
     * refresh-or-create path: once resolved, a future breach will create a fresh
     * Insight rather than updating the closed one.
     *
     * For CPU sustained: resolution happens on the first healthy sample (not after N
     * consecutive healthy samples). A single green point is enough to close the alert —
     * this is intentional to keep the auto-resolve logic simple and symmetric with disk/RAM.
     *
     * withoutGlobalScopes() is required: evaluate() is called from a queue job that
     * has no authenticated session.
     */
    private function resolveAlerts(Server $server, string $metricName): void
    {
        // Mass update bypasses Eloquent model events, so we explicitly forget
        // the triage cache here. This ensures the badge updates immediately
        // when an alert auto-resolves, without waiting for the 30 s TTL.
        $affected = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->whereNull('acknowledged_at')
            ->where('payload->server_id', $server->id)
            ->where('payload->metric', $metricName)
            ->update(['acknowledged_at' => now()]);

        if ($affected > 0) {
            Cache::forget(\App\Services\TriageService::cacheKey($server->team_id));
        }
    }

    /**
     * Build the standardised payload array for a SERVER_HEALTH Insight.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(
        Server $server,
        ServerMetric $metric,
        string $metricName,
        float $threshold,
        float $breachedValue,
    ): array {
        return [
            'metric' => $metricName,          // 'disk'|'ram'|'cpu'|'load' — used for refresh/dedup.
            'server_id' => $server->id,
            'server_name' => $server->name,
            'cpu' => (float) $metric->cpu_percent,
            'ram' => (float) $metric->ram_percent,
            'disk' => (float) $metric->disk_percent,
            'threshold' => $threshold,
            'value' => $breachedValue,
            'sites' => $server->sites()->pluck('primary_domain')->all(),
        ];
    }
}
