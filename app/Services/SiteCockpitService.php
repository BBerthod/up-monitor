<?php

namespace App\Services;

use App\Enums\CheckStatus;
use App\Enums\KpiSource;
use App\Http\Presenters\InsightTriagePresenter;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorLighthouseScore;
use App\Models\Site;
use App\Models\WarmSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the cross-domain cockpit payload for a single Site.
 *
 * DESIGN NOTES
 * ─────────────────────────────────────────────────────────────────────────────
 * • No auth() dependency — team is always passed explicitly.
 *
 * • cockpit() is cached for 60s under "cockpit:site:{id}".  insights() and
 *   timeline() are NOT cached — they must be fresh for the triage UX.
 *
 * • Uptime per monitor is computed via a single GROUP BY query (no N+1).
 *   Latest check per monitor uses the MAX(id) subquery pattern established
 *   in DashboardController.
 *
 * • KPI keys (siteKey) follow KpiCollector::siteNameFromUrl() convention
 *   (www-stripped hostname).  SiteResource already resolves them via
 *   KpiCollector; here we replicate the same one-liner to avoid injecting
 *   KpiCollector (which is HTTP-heavy and unneeded at read time).
 */
class SiteCockpitService
{
    // ──────────────────────────────────────────────────────────
    // Cache
    // ──────────────────────────────────────────────────────────

    public static function cacheKey(int $siteId): string
    {
        return "cockpit:site:{$siteId}";
    }

    // ──────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────

    /**
     * The cached cockpit block (availability + SEO + infrastructure).
     * TTL 60 s — short enough to reflect recent state, long enough to absorb
     * parallel page loads without hammering the DB.
     */
    public function cockpit(Site $site): array
    {
        return Cache::remember(self::cacheKey($site->id), 60, function () use ($site) {
            // Eager-load all monitors once.
            $monitors = $site->monitors()->with(['warmSite.latestCompletedRun'])->get();

            return [
                'availability' => $this->availability($site, $monitors),
                'seo' => $this->seo($site),
                'infrastructure' => $this->infrastructure($site),
            ];
        });
    }

    /**
     * Open insights scoped to this site — always fresh (no cache).
     * Max 5, sorted by impact_score desc, passed through the canonical presenter.
     *
     * @return list<array>
     */
    public function insights(Site $site, TriageService $triage): array
    {
        $query = $triage->forSite($triage->open($site->team), $site);

        return $query
            ->limit(5)
            ->with(['linkedSite', 'server', 'monitor'])
            ->get()
            ->map(fn (Insight $i) => InsightTriagePresenter::present($i))
            ->values()
            ->all();
    }

    /**
     * Mixed timeline of incidents and insights for this site over the last 30
     * days, sorted by event time descending, capped at 20 items.
     * Always fresh — same reason as insights().
     *
     * @return list<array>
     */
    public function timeline(Site $site): array
    {
        $since = now()->subDays(30);

        $monitorIds = $site->monitors()->pluck('id');

        // ── Incidents from this site's monitors ────────────────
        $incidents = [];
        if ($monitorIds->isNotEmpty()) {
            $incidents = MonitorIncident::withoutGlobalScopes()
                ->whereIn('monitor_id', $monitorIds)
                ->where('started_at', '>=', $since)
                ->with('monitor:id,name')
                ->orderByDesc('started_at')
                ->get()
                ->map(fn (MonitorIncident $inc) => [
                    'kind' => 'incident',
                    'at' => $inc->started_at->toIso8601String(),
                    'title' => ($inc->monitor?->name ?? 'Monitor').' — '.$inc->cause->value,
                    'resolved_at' => $inc->resolved_at?->toIso8601String(),
                    'url' => route('monitors.show', $inc->monitor_id),
                ])
                ->all();
        }

        // ── All insights (incl. acknowledged) for this site ────
        // We surface even acknowledged ones so operators can see the history.
        $siteInsights = Insight::withoutGlobalScopes()
            ->where('team_id', $site->team_id)
            ->where('site_id', $site->id)
            ->where('detected_at', '>=', $since)
            ->orderByDesc('detected_at')
            ->get()
            ->map(fn (Insight $ins) => [
                'kind' => 'insight',
                'at' => $ins->detected_at->toIso8601String(),
                'title' => $ins->title,
                'severity' => $ins->severity->value,
                'url' => route('insights.fix', $ins->id),
            ])
            ->all();

        // Merge + sort by 'at' desc + cap at 20
        $merged = array_merge($incidents, $siteInsights);

        usort($merged, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($merged, 0, 20);
    }

    // ──────────────────────────────────────────────────────────
    // Availability
    // ──────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Monitor>  $monitors
     */
    private function availability(Site $site, \Illuminate\Database\Eloquent\Collection $monitors): array
    {
        if ($monitors->isEmpty()) {
            return [
                'uptime_30d' => null,
                'incidents_30d' => 0,
                'incidents_open' => 0,
                'mttr_minutes' => null,
                'monitors' => [],
                'warming' => null,
            ];
        }

        $monitorIds = $monitors->pluck('id');

        // ── Uptime per monitor in ONE GROUP BY query ───────────
        $uptimeRows = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', now()->subDays(30))
            ->selectRaw('monitor_id, '.MonitorCheck::uptimeRaw(2).' as uptime')
            ->groupBy('monitor_id')
            ->pluck('uptime', 'monitor_id');

        // ── Latest check per monitor (status + response_time_ms) ─
        $latestChecks = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->whereIn('id', fn ($q) => $q
                ->select(DB::raw('MAX(id)'))
                ->from('monitor_checks')
                ->whereIn('monitor_id', $monitorIds)
                ->groupBy('monitor_id')
            )
            ->get(['monitor_id', 'status', 'response_time_ms'])
            ->keyBy('monitor_id');

        // ── Monitors list ──────────────────────────────────────
        $monitorList = $monitors->map(function (Monitor $m) use ($uptimeRows, $latestChecks): array {
            $latest = $latestChecks->get($m->id);
            $uptime = isset($uptimeRows[$m->id]) ? (float) $uptimeRows[$m->id] : null;

            if ($latest === null) {
                $status = $m->is_active ? 'paused' : 'paused';
            } elseif (! $m->is_active) {
                $status = 'paused';
            } else {
                $status = $latest->status === CheckStatus::UP ? 'up' : 'down';
            }

            return [
                'id' => $m->id,
                'name' => $m->name,
                'type' => $m->type->value,
                'status' => $status,
                'uptime_30d' => $uptime,
                'response_ms' => $latest?->response_time_ms,
            ];
        })->values()->all();

        // ── Global uptime (30d across ALL active monitors of this site) ──
        $activeIds = $monitors->where('is_active', true)->pluck('id');
        $uptime30d = null;
        if ($activeIds->isNotEmpty()) {
            $uptime30d = (float) (MonitorCheck::whereIn('monitor_id', $activeIds)
                ->where('checked_at', '>=', now()->subDays(30))
                ->selectRaw('COALESCE('.MonitorCheck::uptimeRaw(2).', 100) as uptime')
                ->value('uptime') ?? 100.0);
        }

        // ── Incidents ─────────────────────────────────────────
        $incidents30d = (int) MonitorIncident::withoutGlobalScopes()
            ->whereIn('monitor_id', $monitorIds)
            ->where('started_at', '>=', now()->subDays(30))
            ->count();

        $incidentsOpen = (int) MonitorIncident::withoutGlobalScopes()
            ->whereIn('monitor_id', $monitorIds)
            ->whereNull('resolved_at')
            ->count();

        // ── MTTR (mean time to resolve, last 30d, resolved incidents only) ─
        $mttrMinutes = null;
        $resolvedRows = MonitorIncident::withoutGlobalScopes()
            ->whereIn('monitor_id', $monitorIds)
            ->where('started_at', '>=', now()->subDays(30))
            ->whereNotNull('resolved_at')
            ->selectRaw('EXTRACT(EPOCH FROM (resolved_at - started_at)) / 60 AS minutes')
            ->pluck('minutes');

        if ($resolvedRows->isNotEmpty()) {
            $mttrMinutes = (float) round($resolvedRows->avg(), 1);
        }

        // ── Cache warming (via WarmSite of any monitor of this site) ───
        $warmSite = $monitors->map->warmSite->filter()->first();
        $warming = null;
        if ($warmSite !== null) {
            $lastRun = $warmSite->latestCompletedRun;
            $warming = [
                'hit_ratio' => $lastRun?->hit_ratio,
                'last_run_at' => $lastRun?->completed_at?->toIso8601String(),
            ];
        }

        return [
            'uptime_30d' => $uptime30d,
            'incidents_30d' => $incidents30d,
            'incidents_open' => $incidentsOpen,
            'mttr_minutes' => $mttrMinutes,
            'monitors' => $monitorList,
            'warming' => $warming,
        ];
    }

    // ──────────────────────────────────────────────────────────
    // SEO
    // ──────────────────────────────────────────────────────────

    private function seo(Site $site): array
    {
        // KPI site key — same derivation as KpiCollector::siteNameFromUrl().
        $siteKey = preg_replace('/^www\./i', '', $site->resolvedPrimaryDomain());

        $val = function (KpiSource $source, string $metric) use ($siteKey): ?float {
            $snapshot = KpiSnapshot::latestFor($siteKey, $source, $metric);

            return $snapshot?->value !== null ? (float) $snapshot->value : null;
        };

        // ── GSC ───────────────────────────────────────────────
        $gscClicks = $val(KpiSource::GSC, 'clicks_28d');
        $gscImpressions = $val(KpiSource::GSC, 'impressions_28d');
        $gscPosition = $val(KpiSource::GSC, 'position_28d');
        $gsc = ($gscClicks !== null || $gscImpressions !== null)
            ? ['clicks_28d' => $gscClicks, 'impressions_28d' => $gscImpressions]
            : null;

        // ── Bing ──────────────────────────────────────────────
        $bingClicks = $val(KpiSource::BING, 'bing_clicks_28d');
        $bing = $bingClicks !== null ? ['clicks_28d' => $bingClicks] : null;

        // ── GA4 ───────────────────────────────────────────────
        $ga4Users = $val(KpiSource::GA4, 'users_28d');
        $ga4 = $ga4Users !== null ? ['users_28d' => $ga4Users] : null;

        // ── TTFB p75 ──────────────────────────────────────────
        $ttfbP75 = $val(KpiSource::TTFB, 'ttfb_p95_ms'); // stored as p95; expose as-is

        // ── Lighthouse (latest score from any monitor of this site) ──
        $monitorIds = $site->monitors()->pluck('id');
        $lighthouse = null;
        if ($monitorIds->isNotEmpty()) {
            /** @var MonitorLighthouseScore|null $score */
            $score = MonitorLighthouseScore::withoutGlobalScopes()
                ->whereIn('monitor_id', $monitorIds)
                ->latest('scored_at')
                ->first(['performance', 'seo', 'accessibility', 'best_practices', 'scored_at']);

            if ($score !== null) {
                $lighthouse = [
                    'performance' => $score->performance,
                    'seo' => $score->seo,
                    'accessibility' => $score->accessibility,
                    'best_practices' => $score->best_practices,
                    'scored_at' => $score->scored_at?->toIso8601String(),
                ];
            }
        }

        return [
            'gsc' => $gsc,
            'bing' => $bing,
            'ga4' => $ga4,
            'position_avg' => $gscPosition,
            'ttfb_p75' => $ttfbP75,
            'lighthouse' => $lighthouse,
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Infrastructure
    // ──────────────────────────────────────────────────────────

    private function infrastructure(Site $site): array
    {
        $server = $site->server;

        if ($server === null) {
            return [
                'server_metric' => null,
                'cohosted_sites' => [],
            ];
        }

        // Latest server metric
        $metric = $server->latestMetric();
        $serverMetric = null;
        if ($metric !== null) {
            $serverMetric = [
                'cpu' => (float) $metric->cpu_percent,
                'ram' => (float) $metric->ram_percent,
                'disk' => (float) $metric->disk_percent,
                'load_1' => (float) $metric->load_avg_1,
                'captured_at' => $metric->captured_at?->toIso8601String(),
            ];
        }

        // Co-hosted sites (same server, different site)
        $cohosted = $server->sites()
            ->where('id', '!=', $site->id)
            ->get(['id', 'alias'])
            ->map(fn (Site $s) => ['id' => $s->id, 'name' => $s->alias])
            ->values()
            ->all();

        return [
            'server_metric' => $serverMetric,
            'cohosted_sites' => $cohosted,
        ];
    }
}
