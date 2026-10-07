<?php

namespace App\Jobs;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatcher job: fans out insight-detection jobs across all active HTTP monitors.
 *
 * Two dispatch strategies coexist here, depending on whether a detector's signal
 * is scoped to a MONITOR (an individual endpoint) or to a SITE (a hostname):
 *
 *  - Per-monitor detectors (DetectSslExpiry, DetectPerfRegression) get one child
 *    job per active monitor. A certificate is bound to a specific host:port and
 *    Lighthouse scores are bound to a specific URL, so a homepage monitor and a
 *    product-page monitor for the same site are genuinely separate signals.
 *
 *  - Per-site detectors (DetectStrikingDistance, DetectHealthDrop, and the
 *    content-decay chain) get exactly ONE dispatch per distinct hostname, using
 *    a single representative monitor. Their underlying signal (GSC position,
 *    health score, page clicks) is keyed by site, not by monitor. Dispatching
 *    them once per monitor instead of once per site used to fan out N identical
 *    child jobs whenever a site had multiple monitors (e.g. "example.com" +
 *    "example.com homepage"), producing N duplicate insights — see MEMORY.md /
 *    HealthDropDetector docblock for the original "triple-email bug".
 *
 * The representative monitor for a hostname is the one with the smallest `id`
 * (deterministic across runs, so re-dispatch always picks the same monitor and
 * a monitor's ownership of the insight doesn't flip-flop between cycles).
 *
 * Deliberately mirrors the structure of DispatchKpiCollection (try/catch isolation
 * per dispatch, same queue). Unlike DispatchKpiCollection, this job loads the
 * active-monitor fleet eagerly with get() rather than streaming via cursor():
 * per-site grouping needs the full set in memory to determine hostname
 * representatives, and the HTTP-monitor fleet size makes this negligible.
 * Extend handle() when additional insight detectors are added — decide up
 * front whether the new detector's signal is per-monitor or per-site and
 * dispatch it from the matching loop below.
 *
 * Scheduling is handled externally (routes/console.php) — do not add it here.
 */
class DispatchInsights implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(): void
    {
        $dispatched = 0;
        $failed = 0;

        $monitors = Monitor::withoutGlobalScopes()
            ->active()
            ->where('type', MonitorType::HTTP->value)
            ->orderBy('id')
            ->get();

        // Per-monitor detectors: SSL expiry (per host:port certificate) and perf
        // regression (per-URL Lighthouse scores) are genuinely distinct signals
        // even when several monitors share a hostname, so every active monitor
        // gets its own child job.
        foreach ($monitors as $monitor) {
            try {
                DetectSslExpiry::dispatch($monitor);
                DetectPerfRegression::dispatch($monitor);

                $dispatched++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('DispatchInsights: failed to dispatch per-monitor detectors', [
                    'monitor_id' => $monitor->id,
                    'url' => $monitor->url,
                    'error' => $e->getMessage(),
                ]);
                // Continue — one bad dispatch must not abort the whole fleet.
            }
        }

        // Per-site detectors: pick one representative monitor per SITE (lowest
        // id) so a site with several monitors only fans out one child job per
        // detector, matching the per-site nature of the underlying signal.
        //
        // Grouped by site_id rather than hostname when the monitor is attached
        // to a Site: two sub-domains of the same Site (e.g. "fr.example.com"
        // and "us.example.com") share ONE Insight record per detector (see the
        // site_id-keyed dedup in ContentDecayService / StrikingDistanceService /
        // KeywordTrendService), so they must also share one dispatch — grouping
        // by hostname alone would fan out one run per sub-domain and each run
        // would purge the other's insights. Monitors with no Site fall back to
        // the normalized hostname, exactly as before.
        $representatives = $monitors
            ->groupBy(fn (Monitor $monitor) => $monitor->site_id !== null
                ? 'site:'.$monitor->site_id
                : 'host:'.$this->normalizedHostname($monitor->url))
            ->map(fn ($group) => $group->sortBy('id')->first());

        foreach ($representatives as $monitor) {
            try {
                // Striking-distance: independent, no ordering dependency.
                DetectStrikingDistance::dispatch($monitor);

                // Health-drop: independent, reads kpi_snapshots only.
                DetectHealthDrop::dispatch($monitor);

                // Content-decay + broken-page + affiliate-leak pipeline: collect page
                // snapshots first, then run all detectors in sequence so they always
                // see the freshest click data from the current collection cycle.
                //
                // Chain order:
                //   1. CollectPageMetrics    — refreshes page_metrics rows
                //   2. DetectContentDecay    — needs fresh clicks baseline
                //   3. DetectBrokenPages     — reads clicks to decide which pages to probe;
                //                             also benefits from the freshest snapshot so
                //                             newly high-traffic pages are probed immediately.
                //   4. DetectAffiliateLeaks  — crawls high-traffic pages for Amazon link
                //                             anomalies (foreign tags / untagged links);
                //                             placed after the HTTP-bound steps because it
                //                             issues its own GETs and benefits from stable
                //                             click data.
                //   5. DetectKeywordTrends   — reads the keyword rows that step 1 persists,
                //                             so it MUST follow it; runs at the end because
                //                             it is database-only and should not delay the
                //                             HTTP-bound detectors above.
                Bus::chain([
                    new CollectPageMetrics($monitor),
                    new DetectContentDecay($monitor),
                    new DetectBrokenPages($monitor),
                    new DetectAffiliateLeaks($monitor),
                    new DetectKeywordTrends($monitor),
                ])->onQueue('monitors')->dispatch();

                $dispatched++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('DispatchInsights: failed to dispatch per-site detectors', [
                    'monitor_id' => $monitor->id,
                    'url' => $monitor->url,
                    'error' => $e->getMessage(),
                ]);
                // Continue — one bad dispatch must not abort the whole fleet.
            }
        }

        // ── Per-SITE detectors ────────────────────────────────────────────────
        //
        // These are keyed by Site rather than by Monitor: their subject is the
        // site's own configuration (its sitemap, its ad networks, its locales),
        // not any single endpoint. They are dispatched from the Site table so a
        // site still gets audited when nobody happened to create a monitor for
        // it.
        //
        // ONE loop over Site, not one per detector. Each detector deciding to
        // iterate Site itself would re-run the same query N times, and — as this
        // merge showed — every new detector would then collide with its
        // neighbours in this file. Adding one now means appending a single entry
        // to the table below.
        //
        // Each entry: a guard deciding whether the detector applies to a site,
        // and the dispatch itself. A guard returning false costs nothing.
        $siteDetectors = [
            'sitemap' => [
                'applies' => static fn (Site $site): bool => $site->resolvedSitemapUrl() !== null,
                'dispatch' => static fn (Site $site) => DetectSitemapHealth::dispatch($site),
            ],
            'cmp' => [
                'applies' => static fn (Site $site): bool => is_array($site->ad_networks)
                    && $site->ad_networks !== [],
                'dispatch' => static fn (Site $site) => DetectMissingCmp::dispatch($site),
            ],
            'hreflang' => [
                // Only a multi-locale site can have a reciprocity problem.
                'applies' => static fn (Site $site): bool => is_array($site->locales)
                    && count($site->locales) >= 2,
                'dispatch' => static fn (Site $site) => DetectHreflangIssues::dispatch($site),
            ],
            'cms' => [
                'applies' => static fn (Site $site): bool => $site->type === 'wordpress',
                'dispatch' => static fn (Site $site) => DetectOutdatedCms::dispatch($site),
            ],
            'zombie' => [
                'applies' => static fn (Site $site): bool => is_string($site->sitemap_path) && trim($site->sitemap_path) !== '',
                'dispatch' => static fn (Site $site) => DetectZombiePages::dispatch($site),
            ],
        ];

        $siteDispatched = array_fill_keys(array_keys($siteDetectors), 0);

        foreach (Site::withoutGlobalScopes()->where('is_active', true)->get() as $site) {
            foreach ($siteDetectors as $name => $detector) {
                if (! $detector['applies']($site)) {
                    continue;
                }

                try {
                    $detector['dispatch']($site);
                    $siteDispatched[$name]++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('DispatchInsights: failed to dispatch site detector', [
                        'detector' => $name,
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue — one bad dispatch must not abort the others.
                }
            }
        }

        Log::info('DispatchInsights: fan-out complete', [
            'monitors' => $monitors->count(),
            'sites' => $representatives->count(),
            'dispatched' => $dispatched,
            'site_detectors' => $siteDispatched,
            'failed' => $failed,
        ]);

        // Team-wide detector: runs once regardless of the number of monitors.
        DetectOrphanMonitors::dispatch();
    }

    /**
     * Normalize a monitor's URL to a bare hostname for per-site grouping.
     *
     * IMPORTANT: never use ltrim($host, 'www.') here — ltrim strips a *character
     * set*, not a prefix, and would corrupt a hostname like "webcompare.com" into
     * "ebcompare.com" (every leading character present in the set "w.mco" gets
     * trimmed). Always match the literal "www." prefix with str_starts_with()
     * and remove it with substr(). This exact trap is already documented in
     * PerfRegressionDetector.
     */
    private function normalizedHostname(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? $url);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    public function failed(Throwable $e): void
    {
        Log::error('DispatchInsights dispatcher job failed', [
            'job' => static::class,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
