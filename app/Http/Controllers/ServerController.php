<?php

namespace App\Http\Controllers;

use App\Http\Presenters\InsightTriagePresenter;
use App\Http\Requests\StoreServerRequest;
use App\Http\Requests\UpdateServerRequest;
use App\Http\Traits\FiltersBySiteScope;
use App\Models\Insight;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\TriageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    use FiltersBySiteScope;

    public function __construct(private readonly TriageService $triage) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Server::class);

        $scope = $this->currentSiteScope();

        // Load servers with their sites (lightweight: id + server_id + primary_domain only).
        // N+1 note: latestMetric() and recentFor() issue per-server queries.
        // Acceptable in practice (1–5 servers per team). If scale grows, consider
        // eager-loading metrics with ->with('metrics') and filtering in-memory,
        // but recentFor (24h window) can be large — keep simple until it matters.
        $query = Server::with('sites:id,server_id,primary_domain')
            ->orderBy('name');

        // Apply site-scope lens: filter by the servers that host the active site.
        if ($scope->isSite() && $scope->site !== null) {
            $siteId = $scope->site->id;
            $query->whereHas('sites', fn ($q) => $q->where('sites.id', $siteId));
        } elseif ($scope->isUnassigned()) {
            // Unassigned: servers that host no sites at all.
            $query->whereDoesntHave('sites');
        }

        $servers = $query->get()
            ->map(function (Server $server) {
                $latest = $server->latestMetric();
                $sparklineMetrics = ServerMetric::recentFor($server, 24);

                return [
                    'id' => $server->id,
                    'name' => $server->name,
                    'is_active' => $server->is_active,
                    'has_monitoring' => $server->hasMonitoringConfigured(),
                    'latest' => $latest ? [
                        'cpu' => (float) $latest->cpu_percent,
                        'ram' => (float) $latest->ram_percent,
                        'disk' => (float) $latest->disk_percent,
                        'ram_used_mb' => $latest->ram_used_mb,
                        'ram_total_mb' => $latest->ram_total_mb,
                        'disk_used_gb' => $latest->disk_used_gb,
                        'disk_total_gb' => $latest->disk_total_gb,
                        'load_avg_1' => $latest->load_avg_1 !== null ? (float) $latest->load_avg_1 : null,
                        'load_avg_5' => $latest->load_avg_5 !== null ? (float) $latest->load_avg_5 : null,
                        'load_avg_15' => $latest->load_avg_15 !== null ? (float) $latest->load_avg_15 : null,
                        'captured_at' => $latest->captured_at?->toIso8601String(),
                    ] : null,
                    'sparkline' => $sparklineMetrics->map(fn (ServerMetric $m) => [
                        't' => $m->captured_at->toIso8601String(),
                        'cpu' => (float) $m->cpu_percent,
                        'ram' => (float) $m->ram_percent,
                        'disk' => (float) $m->disk_percent,
                    ])->values()->all(),
                    'sites' => $server->sites->pluck('primary_domain')->values()->all(),
                ];
            });

        return Inertia::render('Servers/Index', [
            'servers' => $servers->values(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Server::class);

        return Inertia::render('Servers/Create', [
            'defaultThresholds' => config('monitoring.server_health'),
        ]);
    }

    public function store(StoreServerRequest $request): RedirectResponse
    {
        $this->authorize('create', Server::class);

        Server::create(array_merge(
            $request->validated(),
            ['team_id' => $request->user()->team_id]
        ));

        return to_route('servers.index')->with('success', 'Server created.');
    }

    public function show(Server $server): Response
    {
        $this->authorize('view', $server);

        $latest = $server->latestMetric();

        // History resolution is chosen server-side. The agent reports every
        // 5 minutes, so 30 raw days is ~8 640 rows × 5 fields — that entire
        // set used to be serialised into every payload (and re-sent on every
        // realtime refresh) while the front filtered it down client-side.
        //
        // Raw points are kept for the 24h window where individual spikes
        // matter; 7d/30d are averaged per hour / per 6 hours in SQL, which is
        // more detail than the ~300 px charts can draw anyway.
        $period = request()->query('period', '24h');

        [$since, $bucket] = match ($period) {
            '30d' => [now()->subDays(30), '6 hours'],
            '7d' => [now()->subDays(7), '1 hour'],
            default => [now()->subDay(), null],
        };

        $base = ServerMetric::where('server_id', $server->id)
            ->where('captured_at', '>=', $since);

        $history = $bucket === null
            ? $base->orderBy('captured_at')
                ->get()
                ->map(fn (ServerMetric $m) => [
                    't' => $m->captured_at->toIso8601String(),
                    'cpu' => (float) $m->cpu_percent,
                    'ram' => (float) $m->ram_percent,
                    'disk' => (float) $m->disk_percent,
                    'load1' => $m->load_avg_1 !== null ? (float) $m->load_avg_1 : null,
                ])
                ->values()
            : $base->selectRaw(
                "date_bin(?::interval, captured_at, TIMESTAMP '2000-01-01') as bucket,
                 ROUND(AVG(cpu_percent), 2) as cpu,
                 ROUND(AVG(ram_percent), 2) as ram,
                 ROUND(AVG(disk_percent), 2) as disk,
                 ROUND(AVG(load_avg_1), 2) as load1",
                [$bucket],
            )
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get()
                ->map(fn ($row) => [
                    't' => \Illuminate\Support\Carbon::parse($row->bucket)->toIso8601String(),
                    'cpu' => (float) $row->cpu,
                    'ram' => (float) $row->ram,
                    'disk' => (float) $row->disk,
                    'load1' => $row->load1 !== null ? (float) $row->load1 : null,
                ])
                ->values();

        $sites = $server->sites()->get(['id', 'primary_domain'])->map(fn ($s) => [
            'id' => $s->id,
            'primary_domain' => $s->primary_domain,
        ])->values();

        return Inertia::render('Servers/Show', [
            'period' => in_array($period, ['24h', '7d', '30d'], true) ? $period : '24h',
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'dokploy_server_id' => $server->dokploy_server_id,
                'is_active' => $server->is_active,
                'has_monitoring' => $server->hasMonitoringConfigured(),
                'has_ingest_token' => $server->hasIngestToken(),
                'thresholds' => $server->thresholds(),
            ],
            'latest' => $latest ? [
                'cpu' => (float) $latest->cpu_percent,
                'ram' => (float) $latest->ram_percent,
                'disk' => (float) $latest->disk_percent,
                'ram_used_mb' => $latest->ram_used_mb,
                'ram_total_mb' => $latest->ram_total_mb,
                'disk_used_gb' => $latest->disk_used_gb,
                'disk_total_gb' => $latest->disk_total_gb,
                'load_avg_1' => $latest->load_avg_1 !== null ? (float) $latest->load_avg_1 : null,
                'load_avg_5' => $latest->load_avg_5 !== null ? (float) $latest->load_avg_5 : null,
                'load_avg_15' => $latest->load_avg_15 !== null ? (float) $latest->load_avg_15 : null,
                'captured_at' => $latest->captured_at?->toIso8601String(),
            ] : null,
            'history' => $history,
            'sites' => $sites,
            'insights' => $this->buildServerInsights($server),
        ]);
    }

    public function edit(Server $server): Response
    {
        $this->authorize('update', $server);

        return Inertia::render('Servers/Edit', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'dokploy_server_id' => $server->dokploy_server_id,
                'is_active' => $server->is_active,
                'settings' => $server->settings ?? [],
            ],
            'defaultThresholds' => config('monitoring.server_health'),
        ]);
    }

    public function update(UpdateServerRequest $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $server->update($request->validated());

        return to_route('servers.index')->with('success', 'Server updated.');
    }

    public function destroy(Server $server): RedirectResponse
    {
        $this->authorize('delete', $server);

        $server->delete();

        return to_route('servers.index')->with('success', 'Server deleted.');
    }

    /**
     * Open insights scoped to this server (max 5, sorted by impact_score desc).
     *
     * withoutGlobalScopes() is mandatory — ScopedByTeam requires auth(), but
     * here we have it; the constraint is inherited from TriageService::open()
     * which also calls withoutGlobalScopes() for consistency across contexts.
     *
     * @return list<array>
     */
    private function buildServerInsights(Server $server): array
    {
        $team = $server->team;

        if ($team === null) {
            return [];
        }

        return $this->triage->forServer($this->triage->open($team), $server)
            ->limit(5)
            ->with(['linkedSite', 'server', 'monitor'])
            ->get()
            ->map(fn (Insight $i) => InsightTriagePresenter::present($i))
            ->values()
            ->all();
    }

    /**
     * Rotate the push-agent ingest token for this server.
     *
     * The plain token is flashed into the session so the UI can display it once.
     * Only the SHA-256 hash is persisted — the plain value is never stored.
     */
    public function rotateToken(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $plain = Server::generateIngestToken();
        $server->update(['ingest_token_hash' => Server::hashIngestToken($plain)]);

        return back()->with('serverToken', $plain);
    }
}
