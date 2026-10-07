<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Models\Site;
use App\Services\HealthScoreService;
use App\Services\SiteCockpitService;
use App\Services\TriageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Site::class);

        $sites = Site::withCount('monitors')
            ->orderBy('alias')
            ->get()
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'alias' => $site->alias,
                'organization' => $site->organization,
                'primary_domain' => $site->primary_domain,
                'type' => $site->type,
                'has_gsc' => $site->gsc_property !== null,
                'has_bing' => $site->bing_url !== null,
                'has_ga4' => $site->ga4_property !== null,
                'is_active' => $site->is_active,
                'monitors_count' => $site->monitors_count,
            ]);

        return Inertia::render('Sites/Index', [
            'sites' => $sites->values(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Site::class);

        return Inertia::render('Sites/Create');
    }

    public function store(StoreSiteRequest $request): RedirectResponse
    {
        $this->authorize('create', Site::class);

        Site::create(array_merge(
            $request->validated(),
            ['team_id' => $request->user()->team_id]
        ));

        return to_route('sites.index')->with('success', 'Site created successfully.');
    }

    public function show(
        Site $site,
        SiteCockpitService $cockpit,
        TriageService $triage,
        HealthScoreService $healthScore,
    ): Response {
        $this->authorize('view', $site);

        // Eager-load relations needed by the cockpit builder.
        $site->load('server', 'team');

        // ── Site header ───────────────────────────────────────
        $server = $site->server;
        $siteProp = [
            'id' => $site->id,
            'name' => $site->alias,
            'domain' => $site->resolvedPrimaryDomain(),
            'locales' => $site->locales,
            'server' => $server
                ? ['id' => $server->id, 'name' => $server->name]
                : null,
            'is_up' => null, // computed below after cockpit()
            'health' => $this->computeSiteHealth($site, $healthScore),
        ];

        // ── Cockpit (cached 60s) ──────────────────────────────
        $cockpitData = $cockpit->cockpit($site);

        // Derive is_up from active monitors status (from the already-computed
        // monitors list — no extra query).
        $activeMonitors = collect($cockpitData['availability']['monitors'])
            ->where('status', '!=', 'paused');

        $siteProp['is_up'] = $activeMonitors->isEmpty()
            ? null
            : $activeMonitors->every(fn ($m) => $m['status'] === 'up');

        return Inertia::render('Sites/Show', [
            'site' => $siteProp,
            'cockpit' => $cockpitData,
            'insights' => $cockpit->insights($site, $triage),
            'timeline' => $cockpit->timeline($site),
        ]);
    }

    /**
     * Compute a {score, trend} health snapshot for a single site.
     *
     * Uses HealthScoreService::scoreForTeam() which is cached 5 minutes per
     * team — the team cache is shared with the dashboard, so this call is
     * almost always a cache hit on a warmed team. Kept outside cockpit:site:{id}
     * because the two caches have different TTLs (60s vs 300s).
     *
     * Returns null when no active monitors belong to the site.
     *
     * @return array{score: int, trend: string}|null
     */
    private function computeSiteHealth(Site $site, HealthScoreService $healthScore): ?array
    {
        $monitorIds = $site->monitors()->active()->pluck('id')->all();

        if (empty($monitorIds)) {
            return null;
        }

        $teamHealth = $healthScore->scoreForTeam($site->team);

        $siteEntries = array_filter(
            $teamHealth['sites'],
            fn (array $entry) => in_array($entry['monitor_id'], $monitorIds, true),
        );

        if (empty($siteEntries)) {
            return null;
        }

        $scores = array_column($siteEntries, 'score');
        $avgScore = (int) round(array_sum($scores) / count($scores));

        // Trend: prefer 'up' if any entry is improving, else 'down' if any is
        // degrading, else 'flat'. This surfaces the most actionable signal.
        $trends = array_column($siteEntries, 'trend');
        $trend = in_array('up', $trends, true)
            ? 'up'
            : (in_array('down', $trends, true) ? 'down' : 'flat');

        return ['score' => $avgScore, 'trend' => $trend];
    }

    public function edit(Site $site): Response
    {
        $this->authorize('update', $site);

        return Inertia::render('Sites/Edit', [
            'site' => $site,
        ]);
    }

    public function update(UpdateSiteRequest $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $site->update($request->validated());

        return to_route('sites.index')->with('success', 'Site updated successfully.');
    }

    public function destroy(Site $site): RedirectResponse
    {
        $this->authorize('delete', $site);

        $site->delete();

        return to_route('sites.index')->with('success', 'Site deleted successfully.');
    }
}
