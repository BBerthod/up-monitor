<?php

namespace App\Http\Controllers;

use App\Enums\KpiSource;
use App\Http\Traits\FiltersBySiteScope;
use App\Models\KpiSnapshot;
use App\Models\MonitorLighthouseScore;
use App\Models\Site;
use App\Services\HealthScoreService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portfolio KPI & Trends page.
 *
 * Renders a per-site summary of the latest search and performance KPIs,
 * together with the most recent Lighthouse score for every scored monitor.
 *
 * DESIGN NOTES
 * ─────────────────────────────────────────────────────────────────────────────
 * • No auth() dependency beyond the standard `auth` middleware on the route.
 *   The team is resolved from auth()->user()->team.
 *
 * • KPI snapshots: all sites and metrics are resolved by one
 *   KpiSnapshot::latestValuesFor() call. Calling latestFor() per (site, metric)
 *   instead cost sites × 6 queries — 28 for four sites — which is the bulk of
 *   what this page used to spend.
 *
 * • Lighthouse: one GROUP BY MAX(id) query across all monitors — no N+1.
 *
 * • Site-scope lens (FiltersBySiteScope) is honoured:
 *   - all        → all sites for the team
 *   - site       → only the scoped site (single row in 'sites')
 *   - unassigned → no sites (no site_id context for KPI snapshots)
 *   Lighthouse is filtered to monitors belonging to the in-scope sites.
 *
 * • Health scores reuse the team-level cache from HealthScoreService (5 min).
 *   We index the cached result by monitor->site (KPI site key) so we can
 *   attach scores without re-computing.  If a site has no active monitor we
 *   return null for health_score.
 */
class KpiTrendsController extends Controller
{
    use FiltersBySiteScope;

    public function __construct(private readonly HealthScoreService $healthScoreService) {}

    public function index(): Response
    {
        $user = auth()->user();
        $team = $user->team;

        if ($team === null) {
            return Inertia::render('KpiTrends', [
                'sites' => [],
                'lighthouse' => [],
            ]);
        }

        // ── Resolve sites subject to the active site-scope lens ───────────────
        $scope = $this->currentSiteScope();
        $sitesQuery = Site::where('team_id', $team->id)->orderBy('alias');

        if ($scope->isSite() && $scope->site !== null) {
            $sitesQuery->where('id', $scope->site->id);
        } elseif ($scope->isUnassigned()) {
            // "Unassigned" mode has no meaningful KPI site context.
            return Inertia::render('KpiTrends', [
                'sites' => [],
                'lighthouse' => [],
            ]);
        }

        $sites = $sitesQuery->with('monitors:id,site_id,url,is_active')->get();

        // ── Health scores (team-level, cached 5 min) ──────────────────────────
        // Index by the KPI site key (www-stripped hostname) for O(1) look-up.
        $healthByKey = [];
        try {
            $healthData = $this->healthScoreService->scoreForTeam($team);
            foreach ($healthData['sites'] as $entry) {
                $healthByKey[$entry['site']] = [
                    'score' => $entry['score'],
                    'grade' => $entry['grade'],
                    'trend' => $entry['trend'],
                ];
            }
        } catch (\Throwable) {
            // Health score computation failure must not break the page.
        }

        // ── Per-site KPI snapshot resolution ─────────────────────────────────
        // Every site needs the same six metrics, so they are fetched in one
        // query rather than one per (site, metric) pair.
        $metrics = [
            'gsc_clicks_28d' => [KpiSource::GSC, 'clicks_28d'],
            'gsc_impressions_28d' => [KpiSource::GSC, 'impressions_28d'],
            'gsc_position_28d' => [KpiSource::GSC, 'position_28d'],
            'bing_clicks_28d' => [KpiSource::BING, 'bing_clicks_28d'],
            'ga4_users_28d' => [KpiSource::GA4, 'users_28d'],
            'ttfb' => [KpiSource::TTFB, 'ttfb_p95_ms'],
        ];

        // KPI key — same derivation as KpiCollector::siteNameFromUrl().
        $siteKeys = $sites->mapWithKeys(fn (Site $site) => [
            $site->id => preg_replace('/^www\./i', '', $site->resolvedPrimaryDomain()),
        ]);

        $latestValues = KpiSnapshot::latestValuesFor(
            $siteKeys->values()->unique()->all(),
            array_values($metrics),
        );

        $sitesPayload = $sites->map(function (Site $site) use ($healthByKey, $metrics, $siteKeys, $latestValues): array {
            $siteKey = $siteKeys[$site->id];
            $forSite = $latestValues[$siteKey] ?? [];

            $kpis = [];

            foreach ($metrics as $label => [$source, $metric]) {
                $kpis[$label] = $forSite[$source->value.'|'.$metric] ?? null;
            }

            return [
                'id' => $site->id,
                'name' => $site->alias ?: $site->resolvedPrimaryDomain(),
                'domain' => $site->resolvedPrimaryDomain(),
                'kpis' => $kpis,
                'health_score' => $healthByKey[$siteKey] ?? null,
            ];
        })->values()->all();

        // ── Lighthouse: latest score per monitor (anti-N+1 via MAX subquery) ──
        // Collect all monitor IDs for the in-scope sites.
        $allMonitorIds = $sites->flatMap(fn (Site $s) => $s->monitors->pluck('id'))->values();

        $lighthousePayload = [];

        if ($allMonitorIds->isNotEmpty()) {
            // MAX(id) per monitor identifies the most recent row (monotonic PK).
            $latestIds = MonitorLighthouseScore::withoutGlobalScopes()
                ->whereIn('monitor_id', $allMonitorIds)
                ->select(DB::raw('MAX(id) as id'))
                ->groupBy('monitor_id')
                ->pluck('id');

            if ($latestIds->isNotEmpty()) {
                // Build a monitor_id → site_id map for denormalisation.
                $monitorSiteMap = $sites
                    ->flatMap(fn (Site $s) => $s->monitors->map(fn ($m) => [$m->id => $s->id]))
                    ->collapse();

                // Build a monitor_id → name map.
                $monitorNameMap = $sites
                    ->flatMap(fn (Site $s) => $s->monitors->map(fn ($m) => [$m->id => $m->name ?? $m->url]))
                    ->collapse();

                $lighthousePayload = MonitorLighthouseScore::withoutGlobalScopes()
                    ->whereIn('id', $latestIds)
                    ->get(['id', 'monitor_id', 'performance', 'seo', 'accessibility', 'best_practices', 'scored_at'])
                    ->map(fn (MonitorLighthouseScore $score) => [
                        'monitor_id' => $score->monitor_id,
                        'monitor_name' => $monitorNameMap[$score->monitor_id] ?? null,
                        'site_id' => $monitorSiteMap[$score->monitor_id] ?? null,
                        'performance' => $score->performance,
                        'seo' => $score->seo,
                        'accessibility' => $score->accessibility,
                        'best_practices' => $score->best_practices,
                        'scored_at' => $score->scored_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all();
            }
        }

        return Inertia::render('KpiTrends', [
            'sites' => $sitesPayload,
            'lighthouse' => $lighthousePayload,
        ]);
    }
}
