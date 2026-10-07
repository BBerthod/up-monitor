<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\PageAuditState;
use App\Models\PageMetric;
use App\Support\UrlSafetyValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Detects high-traffic pages that return HTTP errors (4xx/5xx) or are
 * unreachable — the "pages that cost money when broken" signal.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Up monitors track the root URL of a site, not individual pages.  Now that
 * page_metrics accumulates per-page GSC click data, we can cross-reference
 * traffic with real HTTP reachability to surface the highest-ROI fix: a page
 * that earns organic clicks but currently returns an error.
 *
 * DESIGN DECISIONS
 * ────────────────
 * • Only pages with clicks >= min_clicks are tested to avoid wasting HTTP
 *   requests on pages that have no real traffic impact.
 * • HTTP requests follow redirects by default (Laravel Http facade behaviour).
 *   We flag only the FINAL status — a temporary redirect (301→200) is fine;
 *   a redirect chain ending in 404 is a broken page.
 * • Per-page try/catch: one unreachable URL must never abort the full loop.
 * • SSRF guard via UrlSafetyValidator before every outbound request.
 * • Idempotence: unacknowledged REVENUE_AT_RISK insights are replaced on each
 *   run so the insight list always reflects the current broken-page state.
 * • impact_score = monthly clicks at risk (raw number, not capped here —
 *   ActionPlanService caps it at 100 for the ROI formula).
 *
 * SEVERITY HEURISTIC
 * ──────────────────
 * CRITICAL when the monitor is marked is_priority OR the page has >= high_traffic_clicks
 * clicks per month (default 50).  Everything else is WARNING.
 */
class BrokenPageService
{
    /** User-Agent string for page probe requests. */
    private const USER_AGENT = 'Up-Monitor/1.0 (+https://github.com/BBerthod/up-monitor)';

    public function __construct(private readonly KpiCollector $kpiCollector) {}

    /**
     * Probe high-traffic pages for this monitor and create REVENUE_AT_RISK
     * insights for any that return HTTP >= 400 or fail to connect.
     *
     * @return int Number of REVENUE_AT_RISK insights created.
     */
    public function detectForMonitor(Monitor $monitor): int
    {
        $site = $this->kpiCollector->siteNameFromUrl($monitor->url);

        $minClicks = (int) config('monitoring.broken_pages.min_clicks', 5);
        $maxPages = (int) config('monitoring.broken_pages.max_pages_per_monitor', 30);
        $highTrafficClicks = (int) config('monitoring.broken_pages.high_traffic_clicks', 50);

        // Retrieve the most recent snapshot per page for this site.
        //
        // We use a subquery to find the latest captured_at per page, then join
        // back to get the full row — the same "latest snapshot" pattern used by
        // ContentDecayService but inlined here to apply the click filter in a
        // single query rather than N latestFor() calls.
        //
        // NOTE: no LIMIT here. Traffic decides which pages QUALIFY; rotation
        // decides which of them are DUE this run (see below).
        $candidates = PageMetric::where('site', $site)
            ->whereIn('id', function ($sub) use ($site): void {
                $sub->selectRaw('MAX(id)')
                    ->from('page_metrics')
                    ->where('site', $site)
                    ->groupBy('page');
            })
            ->where('clicks', '>=', $minClicks)
            ->orderByDesc('clicks')
            ->get();

        // Rotation: ordering by clicks and truncating at the cap meant the same
        // head pages were probed on every run and the tail was never looked at
        // — silently, since the cap is reported nowhere. Selecting the least
        // recently audited pages instead gives full catalogue coverage in
        // ceil(pages / cap) runs.
        $duePages = PageAuditState::selectDue(
            $site,
            PageAuditState::TYPE_BROKEN_PAGE,
            $candidates->pluck('page'),
            $maxPages,
        );

        $pages = $candidates->whereIn('page', $duePages)->values();

        if ($candidates->count() > $pages->count()) {
            // Never let a cap truncate silently: without this line "30 pages
            // checked" reads as "the site is covered".
            Log::info('BrokenPageService: rotation applied', [
                'monitor_id' => $monitor->id,
                'site' => $site,
                'qualifying_pages' => $candidates->count(),
                'audited_this_run' => $pages->count(),
                'deferred' => $candidates->count() - $pages->count(),
            ]);
        }

        if ($pages->isEmpty()) {
            Log::info('BrokenPageService: no qualifying pages', [
                'monitor_id' => $monitor->id,
                'site' => $site,
                'min_clicks' => $minClicks,
            ]);

            return 0;
        }

        // Idempotence: delete all previous unacknowledged REVENUE_AT_RISK insights
        // for this monitor so each run produces a fresh picture.
        // withoutGlobalScopes() is mandatory in job context where auth() is null
        // and the ScopedByTeam global scope is inactive.
        Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::REVENUE_AT_RISK->value)
            ->whereNull('acknowledged_at')
            ->delete();

        $httpTimeout = 15;
        $connectTimeout = 10;

        $created = 0;

        // Pages we actually probed. An unsafe URL is skipped without being
        // examined, so it must not advance the rotation cursor.
        $examined = [];

        foreach ($pages as $pageMetric) {
            $page = $pageMetric->page;

            try {
                // SSRF guard — skip URLs that resolve to private/reserved IPs.
                // Page URLs come from GSC so they should always be public, but
                // we must not assume that (e.g. a user could have misconfigured
                // a property with an intranet domain).
                if (! UrlSafetyValidator::isSafe($page)) {
                    Log::info('BrokenPageService: skipping unsafe URL', [
                        'monitor_id' => $monitor->id,
                        'page' => $page,
                    ]);

                    continue;
                }

                $examined[] = $page;

                $statusCode = null;
                $isBroken = false;
                $error = null;

                try {
                    // Laravel Http facade follows redirects by default.
                    // We check the FINAL status — a redirect chain ending in 4xx/5xx
                    // is a broken page; one ending in 2xx is healthy.
                    $response = Http::timeout($httpTimeout)
                        ->connectTimeout($connectTimeout)
                        ->withHeaders(['User-Agent' => self::USER_AGENT])
                        ->get($page);

                    $statusCode = $response->status();
                    $isBroken = $statusCode >= 400;
                } catch (ConnectionException $e) {
                    // Connection failure (DNS, TCP refused, timeout) — treat as broken.
                    $isBroken = true;
                    $error = 'unreachable';
                    Log::debug('BrokenPageService: connection failed', [
                        'monitor_id' => $monitor->id,
                        'page' => $page,
                        'exception' => $e->getMessage(),
                    ]);
                }

                if (! $isBroken) {
                    continue;
                }

                $clicks = (float) $pageMetric->clicks;
                $impressions = (float) $pageMetric->impressions;

                // CRITICAL when monitor is priority OR page has heavy traffic.
                // The is_priority flag lets teams manually escalate a monitor whose
                // pages should always page immediately when broken (e.g. checkout).
                $severity = ($monitor->is_priority || $clicks >= $highTrafficClicks)
                    ? InsightSeverity::CRITICAL
                    : InsightSeverity::WARNING;

                $title = $statusCode !== null
                    ? sprintf(
                        'Broken page: %s returns HTTP %d — %d monthly clicks at risk',
                        $page,
                        $statusCode,
                        (int) $clicks,
                    )
                    : sprintf(
                        'Broken page: %s is unreachable — %d monthly clicks at risk',
                        $page,
                        (int) $clicks,
                    );

                Insight::create([
                    'team_id' => $monitor->team_id,
                    'site' => $site,
                    'site_id' => $monitor->site_id,
                    'monitor_id' => $monitor->id,
                    'type' => InsightType::REVENUE_AT_RISK->value,
                    'severity' => $severity->value,
                    'title' => $title,
                    'payload' => [
                        'page' => $page,
                        'status_code' => $statusCode,
                        'clicks' => (int) $clicks,
                        'impressions' => (int) $impressions,
                        'error' => $error,
                    ],
                    // Raw monthly clicks = revenue at risk proxy for prioritisation.
                    // ActionPlanService caps this at 100 for the ROI formula;
                    // we store the raw number here for display purposes.
                    'impact_score' => max(0, round($clicks, 2)),
                    'detected_at' => now(),
                ]);

                $created++;
            } catch (\Throwable $e) {
                // Per-page isolation: a crash on one URL must not abort the whole loop.
                Log::error('BrokenPageService: unexpected error while probing page', [
                    'monitor_id' => $monitor->id,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Stamp every page we looked at, healthy or not — the point of the
        // cursor is "has been examined", not "was found broken". Done after the
        // loop so a mid-run crash leaves the pages due again rather than
        // skipping them for a full rotation.
        PageAuditState::markAudited(
            $site,
            PageAuditState::TYPE_BROKEN_PAGE,
            $examined,
        );

        Log::info('BrokenPageService: detection complete', [
            'monitor_id' => $monitor->id,
            'site' => $site,
            'pages_checked' => $pages->count(),
            'broken' => $created,
        ]);

        return $created;
    }
}
