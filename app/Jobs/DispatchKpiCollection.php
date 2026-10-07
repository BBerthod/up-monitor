<?php

namespace App\Jobs;

use App\Enums\KpiSource;
use App\Enums\MonitorType;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Site;
use App\Services\HealthScoreService;
use App\Services\KpiCollector;
use App\Services\KpiRegressionDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatcher job: collects business KPIs (TTFB, GSC, GA4) for every active site
 * and runs the regression detector after each collection.
 *
 * Collection strategy
 * ───────────────────
 * PRIMARY PATH — Sites with a Site record:
 *   Iterates Site::active() and calls KpiCollector::collectForSite($site).
 *   This path uses the per-site gsc_property / ga4_property so each site
 *   queries the correct GSC property (e.g. "sc-domain:examplestore.com") and the
 *   correct GA4 property ID — rather than guessing from the monitor URL or
 *   using a single global ga4_property_id config.
 *
 * FALLBACK PATH — Monitors without a Site (site_id IS NULL):
 *   For backwards compatibility, active HTTP monitors that are NOT yet linked
 *   to a Site still get TTFB collected via collectForMonitor(). GSC/GA4 are
 *   not collected on the fallback path — no explicit property is available.
 *
 * Double-counting prevention:
 *   A monitor WITH a site_id is covered by its Site in the primary path, so
 *   the fallback path filters to site_id IS NULL only.
 *
 * Health score persistence:
 *   After each successful KPI collection, the composite health score
 *   (HealthScoreService::scoreForMonitor) is stored as a KpiSnapshot with
 *   source=CUSTOM, metric=health_score. updateOrCreate on captured_at=startOfDay
 *   ensures at most one snapshot per site per calendar day — safe to re-run.
 *
 * Scheduled daily at 03:00 UTC (see routes/console.php).
 */
class DispatchKpiCollection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(KpiCollector $collector, KpiRegressionDetector $detector, HealthScoreService $healthScoreService): void
    {
        // ── PRIMARY PATH: Sites ────────────────────────────────────────────────
        // Iterate every active Site and collect TTFB + GSC + GA4 using the
        // per-site property configuration.  withoutGlobalScopes() is required
        // because this job runs outside an authenticated request context where
        // the ScopedByTeam global scope would otherwise return nothing.

        Site::withoutGlobalScopes()
            ->active()
            ->cursor()
            ->each(function (Site $site) use ($collector, $detector, $healthScoreService): void {
                try {
                    // Both of these are dispatched rather than called inline,
                    // so a slow or failing third-party API never delays the
                    // GSC/GA4 collection behind it — and both sit ABOVE the
                    // early return on purpose: a site can earn money, and can
                    // certainly have real users, while having no GSC data at
                    // all. Those are precisely the sites worth recording.
                    CollectAdSenseMetrics::dispatch($site);
                    CollectCruxMetrics::dispatch($site);

                    $snapshots = $collector->collectForSite($site);

                    if (empty($snapshots)) {
                        return;
                    }

                    // The site key must match what HealthScoreService derives
                    // from siteNameFromUrl($monitor->url). collectForSite() stores
                    // snapshots under siteNameFromUrl("https://{resolvedPrimaryDomain}")
                    // — we use the same derivation here for the detector call.
                    $siteKey = $collector->siteNameFromUrl('https://'.$site->resolvedPrimaryDomain());
                    $detector->analyse($siteKey, $snapshots, $site->team_id);
                    $this->persistHealthScore($site, $siteKey, $healthScoreService);

                } catch (Throwable $e) {
                    Log::error('KPI collection failed for site', [
                        'site_id' => $site->id,
                        'primary_domain' => $site->primary_domain,
                        'error' => $e->getMessage(),
                    ]);
                    // Do not re-throw — continue with the next site.
                }
            });

        // ── FALLBACK PATH: Monitors without a Site ────────────────────────────
        // Monitors that have not yet been assigned to a Site entity still get
        // TTFB collected so operators see response-time data even before they
        // complete the site-configuration migration.
        // GSC/GA4 are intentionally omitted here — without a Site record there
        // is no reliable way to know which GSC property / GA4 ID to query.

        Monitor::withoutGlobalScopes()
            ->active()
            ->where('type', MonitorType::HTTP->value)
            ->whereNull('site_id')
            ->cursor()
            ->each(function (Monitor $monitor) use ($collector, $detector, $healthScoreService): void {
                try {
                    $site = $collector->siteNameFromUrl($monitor->url);
                    $snapshots = $collector->collectForMonitor($monitor);

                    if (empty($snapshots)) {
                        return;
                    }

                    $detector->analyse($site, $snapshots, $monitor->team_id);
                    $this->persistHealthScoreForMonitor($monitor, $site, $healthScoreService);

                } catch (Throwable $e) {
                    Log::error('KPI collection failed for monitor (fallback path)', [
                        'monitor_id' => $monitor->id,
                        'url' => $monitor->url,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with next monitor.
                }
            });
    }

    /**
     * Persist the composite health score for a Site (primary path).
     *
     * Finds the first active HTTP monitor linked to the site as the
     * representative input for HealthScoreService::scoreForMonitor().
     * Uses updateOrCreate keyed to startOfDay so a double-run on the same
     * calendar day overwrites rather than duplicates.
     */
    private function persistHealthScore(
        Site $site,
        string $siteKey,
        HealthScoreService $healthScoreService,
    ): void {
        $monitor = $site->monitors()
            ->withoutGlobalScopes()
            ->active()
            ->where('type', MonitorType::HTTP->value)
            ->first();

        if ($monitor === null) {
            return;
        }

        $result = $healthScoreService->scoreForMonitor($monitor);

        KpiSnapshot::updateOrCreate(
            [
                'site' => $siteKey,
                'source' => KpiSource::CUSTOM->value,
                'metric' => 'health_score',
                'captured_at' => now()->startOfDay(),
            ],
            [
                'value' => $result['score'],
                'period_days' => 1,
                'meta' => ['grade' => $result['grade']],
            ],
        );
    }

    /**
     * Persist the composite health score for a standalone Monitor (fallback path).
     *
     * Same updateOrCreate/idempotency guarantee as persistHealthScore().
     */
    private function persistHealthScoreForMonitor(
        Monitor $monitor,
        string $siteKey,
        HealthScoreService $healthScoreService,
    ): void {
        $result = $healthScoreService->scoreForMonitor($monitor);

        KpiSnapshot::updateOrCreate(
            [
                'site' => $siteKey,
                'source' => KpiSource::CUSTOM->value,
                'metric' => 'health_score',
                'captured_at' => now()->startOfDay(),
            ],
            [
                'value' => $result['score'],
                'period_days' => 1,
                'meta' => ['grade' => $result['grade']],
            ],
        );
    }

    public function failed(Throwable $e): void
    {
        Log::error('Dispatcher job failed', [
            'job' => static::class,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
