<?php

namespace App\Services;

use App\Enums\KpiRegressionSeverity;
use App\Enums\KpiSource;
use App\Models\BusinessKpiIncident;
use App\Models\KpiSnapshot;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

/**
 * Compares the latest KPI snapshot against historical baselines and creates
 * BusinessKpiIncident records when thresholds are breached.
 *
 * Thresholds (configurable via config/monitoring.php or .env):
 *   Minor regression:  delta ≤ -10 % over 7 days
 *   Major regression:  delta ≤ -25 % over 7 days  OR  delta ≤ -40 % over 30 days
 *
 * Deduplication:
 *   One open incident per (site, source, metric). A new incident is created only when
 *   there is no open one. An incident is auto-resolved when the metric recovers above
 *   its threshold.
 *
 * Metrics for which a *higher* value is worse (e.g. TTFB, position) are treated as
 * regressions when the value *increases* — configure them via kpiMetricsHigherIsWorse().
 */
class KpiRegressionDetector
{
    /**
     * Metrics where an *increase* is a regression (e.g. response time, average position).
     * All other metrics treat a *decrease* as a regression.
     */
    private const HIGHER_IS_WORSE = [
        'ttfb_p50_ms',
        'ttfb_p95_ms',
        'position_28d',
    ];

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Analyse all snapshots for the given site and fire incidents / resolve existing ones.
     *
     * @param  list<KpiSnapshot>  $snapshots  Fresh snapshots just collected for the site.
     * @param  int|null  $teamId  Owning team, when the caller already knows it.
     *                            Monitors with no Site row are a supported state
     *                            (see OrphanMonitorDetector), and their site key
     *                            matches no Site — without this the notification
     *                            could not be attributed and would be dropped.
     */
    public function analyse(string $site, array $snapshots, ?int $teamId = null): void
    {
        foreach ($snapshots as $snapshot) {
            $this->analyseOne($site, $snapshot, $teamId);
        }
    }

    // ──────────────────────────────────────────────────────────
    // Core analysis
    // ──────────────────────────────────────────────────────────

    private function analyseOne(string $site, KpiSnapshot $snapshot, ?int $teamId = null): void
    {
        $source = $snapshot->source;
        $metric = $snapshot->metric;
        $current = (float) $snapshot->value;

        // Compute 7-day and 30-day baselines (averages excluding current snapshot).
        $baseline7d = $this->baseline($site, $source, $metric, days: 7, excludeId: $snapshot->id);
        $baseline30d = $this->baseline($site, $source, $metric, days: 30, excludeId: $snapshot->id);

        if ($baseline7d === null && $baseline30d === null) {
            // No history yet — nothing to compare.
            return;
        }

        $higherIsWorse = in_array($metric, self::HIGHER_IS_WORSE, true);
        $severity = $this->computeSeverity($current, $baseline7d, $baseline30d, $higherIsWorse);

        $existingIncident = BusinessKpiIncident::findOpen($site, $source, $metric);

        if ($severity !== null) {
            // Regression detected.
            if ($existingIncident === null) {
                $deltaPct = $this->deltaPct($current, $baseline7d ?? $baseline30d);
                $incident = BusinessKpiIncident::create([
                    'site' => $site,
                    'source' => $source->value,
                    'metric' => $metric,
                    'severity' => $severity->value,
                    'baseline_value' => $baseline7d ?? $baseline30d,
                    'current_value' => $current,
                    'delta_pct' => $deltaPct,
                    'detected_at' => now(),
                ]);

                $this->sendIncidentNotification($incident, $teamId);

                Log::warning('KPI regression detected', [
                    'site' => $site,
                    'source' => $source->value,
                    'metric' => $metric,
                    'severity' => $severity->value,
                    'delta_pct' => $deltaPct,
                    'current' => $current,
                    'baseline_7d' => $baseline7d,
                    'baseline_30d' => $baseline30d,
                ]);
            }
            // Else: incident already open — do not create duplicate.

        } elseif ($existingIncident !== null) {
            // Metric has recovered — auto-resolve the open incident.
            $existingIncident->resolve();

            Log::info('KPI regression resolved', [
                'incident_id' => $existingIncident->id,
                'site' => $site,
                'metric' => $metric,
                'current' => $current,
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────
    // Severity computation
    // ──────────────────────────────────────────────────────────

    private function computeSeverity(
        float $current,
        ?float $baseline7d,
        ?float $baseline30d,
        bool $higherIsWorse,
    ): ?KpiRegressionSeverity {
        $minorThreshold7d = config('monitoring.kpi_regression_minor_7d', -10.0); // e.g. -10
        $majorThreshold7d = config('monitoring.kpi_regression_major_7d', -25.0); // e.g. -25
        $majorThreshold30d = config('monitoring.kpi_regression_major_30d', -40.0); // e.g. -40

        // For higher-is-worse metrics, flip the sign so the same threshold logic applies.
        if ($higherIsWorse) {
            $minorThreshold7d = abs($minorThreshold7d);
            $majorThreshold7d = abs($majorThreshold7d);
            $majorThreshold30d = abs($majorThreshold30d);
        }

        $delta7d = $baseline7d !== null ? $this->deltaPct($current, $baseline7d, $higherIsWorse) : null;
        $delta30d = $baseline30d !== null ? $this->deltaPct($current, $baseline30d, $higherIsWorse) : null;

        $isMajor7d = $delta7d !== null && $delta7d <= $majorThreshold7d;
        $isMajor30d = $delta30d !== null && $delta30d <= $majorThreshold30d;
        $isMinor7d = $delta7d !== null && $delta7d <= $minorThreshold7d;

        if ($isMajor7d || $isMajor30d) {
            return KpiRegressionSeverity::MAJOR;
        }

        if ($isMinor7d) {
            return KpiRegressionSeverity::MINOR;
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────
    // Baseline
    // ──────────────────────────────────────────────────────────

    private function baseline(
        string $site,
        KpiSource $source,
        string $metric,
        int $days,
        ?int $excludeId = null,
    ): ?float {
        $query = KpiSnapshot::where('site', $site)
            ->where('source', $source->value)
            ->where('metric', $metric)
            ->where('captured_at', '>=', now()->subDays($days));

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $avg = $query->avg('value');

        return $avg !== null ? (float) $avg : null;
    }

    // ──────────────────────────────────────────────────────────
    // Notification
    // ──────────────────────────────────────────────────────────

    private function sendIncidentNotification(BusinessKpiIncident $incident, ?int $teamId = null): void
    {
        // Scope to the team that owns the site. KpiSnapshot is site-keyed, so
        // the owner is recovered by matching the site key against Site domains.
        //
        // This used to broadcast to every active channel of every team "as a
        // first pass" — a cross-tenant leak: one team's KPI regressions were
        // pushed into every other team's Slack/Telegram/webhooks. When no site
        // matches we fail CLOSED (log, no notification) rather than fall back
        // to broadcasting.
        $ownerTeamId = $teamId ?? Site::findOwnerByHostname($incident->site)?->team_id;

        if ($ownerTeamId === null) {
            Log::warning('KpiRegressionDetector: cannot attribute this KPI key to a team; skipping notification', [
                'site' => $incident->site,
                'incident_id' => $incident->id,
            ]);

            return;
        }

        $channels = NotificationChannel::withoutGlobalScopes()
            ->where('team_id', $ownerTeamId)
            ->where('is_active', true)
            ->get();

        if ($channels->isEmpty()) {
            return;
        }

        $deltaPct = number_format(abs((float) $incident->delta_pct), 1);
        $direction = (float) $incident->delta_pct < 0 ? 'dropped' : 'increased';
        $message = "[Business KPI] {$incident->site} — {$incident->metric} {$direction} by {$deltaPct}% ({$incident->severity->label()})";

        foreach ($channels as $channel) {
            $this->notificationService->notifyBusinessKpi($channel, $message, $incident);
        }

        $incident->markNotified();
    }

    // ──────────────────────────────────────────────────────────
    // Delta helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Compute percentage change from baseline to current.
     * For higher-is-worse metrics, returns a positive value when the metric worsened.
     */
    private function deltaPct(float $current, float $baseline, bool $higherIsWorse = false): float
    {
        if ($baseline == 0.0) {
            return 0.0;
        }

        $pct = (($current - $baseline) / abs($baseline)) * 100;

        return $higherIsWorse ? $pct : $pct; // sign is already correct
    }
}
