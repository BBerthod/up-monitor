<?php

namespace App\Http\Controllers;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Http\Presenters\InsightTriagePresenter;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Services\DashboardOverviewService;
use App\Services\HealthScoreService;
use App\Services\TriageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        TriageService $triage,
        HealthScoreService $healthScoreService,
        DashboardOverviewService $overview,
    ): Response {
        $team = auth()->user()->team;

        if ($team) {
            $topItems = $triage->topItems($team, 3)
                ->map(fn ($i) => InsightTriagePresenter::present($i))
                ->values()
                ->all();
            $counts = $triage->counts($team);
            $breakdown = $this->computeBreakdown($team->id);
        } else {
            $topItems = [];
            $counts = ['total' => 0, 'critical' => 0, 'domains' => []];
            $breakdown = ['warnings' => 0, 'info_opportunity' => 0];
        }

        $domainHealth = $team
            ? $this->computeDomainHealth($team, $healthScoreService)
            : $this->emptyDomainHealth();

        // healthPortfolio feeds HealthScorePortfolio (stage 3) — kept unchanged.
        $rawPortfolio = $team
            ? $healthScoreService->scoreForTeam($team)
            : ['sites' => [], 'computed_at' => now()->toIso8601String()];

        // Merge per-site domain insight counts into each portfolio entry.
        // countsBySite() does a single GROUP BY query; merging is done in PHP.
        $domainCountsBySite = $team ? $triage->countsBySite($team) : [];
        $portfolioSites = array_map(static function (array $site) use ($domainCountsBySite): array {
            $site['domain_counts'] = $domainCountsBySite[$site['monitor_id']] ?? null;

            return $site;
        }, $rawPortfolio['sites']);

        // Current state, last check and daily uptime strip per row, problems first.
        $monitorStates = $team ? $overview->monitorStates($team) : [];
        $healthPortfolio = [
            'sites' => $team ? $overview->siteRows($portfolioSites, $monitorStates) : [],
            'computed_at' => $rawPortfolio['computed_at'],
            'timeline_days' => DashboardOverviewService::TIMELINE_DAYS,
        ];

        // SLA bar — same formula as MetricsService::getDashboardMetrics().
        // slaTarget is always a float (team value or 99.90 default).
        // slaCurrent is null when the team has no monitors (bar is conditional).
        [$slaTarget, $slaCurrent] = $team ? $this->computeSla($team) : [null, null];

        return Inertia::render('Dashboard', [
            // Named triageFeed (not triage) to avoid clobbering the shared
            // middleware prop that feeds the nav badges.
            'triageFeed' => ['items' => $topItems, 'counts' => $counts, 'breakdown' => $breakdown],
            // One-sentence global state that opens the page.
            'statusSummary' => $overview->statusSummary($monitorStates, $counts),
            'generatedAt' => now()->toIso8601String(),
            'domainHealth' => $domainHealth,
            'healthPortfolio' => $healthPortfolio,
            'slaTarget' => $slaTarget,
            'slaCurrent' => $slaCurrent,
        ]);
    }

    // ── Domain health ─────────────────────────────────────────────────────────

    private function computeDomainHealth(Team $team, HealthScoreService $healthScoreService): array
    {
        return [
            'availability' => $this->availabilityHealth($team->id),
            'seo_business' => $this->seoBusinessHealth($team, $healthScoreService),
            'infrastructure' => $this->infrastructureHealth($team->id),
            'alerting' => $this->alertingHealth($team->id),
        ];
    }

    private function availabilityHealth(int $teamId): array
    {
        $activeMonitorIds = Monitor::withoutGlobalScopes()
            ->where('team_id', $teamId)->active()->pluck('id');

        $monitorsTotal = Monitor::withoutGlobalScopes()->where('team_id', $teamId)->count();

        if ($activeMonitorIds->isEmpty()) {
            return ['monitors_total' => $monitorsTotal, 'monitors_up' => 0,
                'incidents_open' => 0, 'uptime_30d' => 100.0, 'worst_monitor' => null];
        }

        // Latest status per active monitor via MAX(id) subquery
        $latestChecks = MonitorCheck::query()
            ->select('monitor_id', 'status')
            ->whereIn('monitor_id', $activeMonitorIds)
            ->whereIn('id', fn ($q) => $q->select(DB::raw('MAX(id)'))
                ->from('monitor_checks')
                ->whereIn('monitor_id', $activeMonitorIds)
                ->groupBy('monitor_id'))
            ->get();

        $monitorsUp = $latestChecks->filter(fn ($c) => $c->status->value === 'up')->count();

        $incidentsOpen = MonitorIncident::withoutGlobalScopes()
            ->whereHas('monitor', fn ($q) => $q->where('team_id', $teamId))
            ->whereNull('resolved_at')
            ->count();

        $uptime30d = (float) (MonitorCheck::whereIn('monitor_id', $activeMonitorIds)
            ->where('checked_at', '>=', now()->subDays(30))
            ->selectRaw('COALESCE('.MonitorCheck::uptimeRaw(2).', 100) as uptime')
            ->value('uptime') ?? 100.0);

        $worstMonitor = null;
        $downId = $latestChecks->filter(fn ($c) => $c->status->value !== 'up')->first()?->monitor_id;
        if ($downId) {
            $m = Monitor::withoutGlobalScopes()->find($downId, ['id', 'name']);
            $worstMonitor = $m ? ['id' => $m->id, 'name' => $m->name, 'status' => 'down'] : null;
        }

        return [
            'monitors_total' => $monitorsTotal,
            'monitors_up' => $monitorsUp,
            'incidents_open' => $incidentsOpen,
            'uptime_30d' => $uptime30d,
            'worst_monitor' => $worstMonitor,
        ];
    }

    private function seoBusinessHealth(Team $team, HealthScoreService $healthScoreService): array
    {
        // Reuse cached scoreForTeam (5 min TTL) — zero extra DB round-trips.
        $sites = $healthScoreService->scoreForTeam($team)['sites'] ?? [];

        if (empty($sites)) {
            return ['health_avg' => null, 'quick_wins' => 0, 'worst_site' => null];
        }

        $healthAvg = (int) round(array_sum(array_column($sites, 'score')) / count($sites));

        $quickWins = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNull('acknowledged_at')
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->count();

        // Worst site is first entry (sorted asc by score in scoreForTeam).
        $worst = $sites[0];
        $worstSite = ['id' => $worst['monitor_id'], 'name' => $worst['name'], 'score' => $worst['score']];

        return [
            'health_avg' => $healthAvg,
            'quick_wins' => $quickWins,
            'worst_site' => $worstSite,
        ];
    }

    private function infrastructureHealth(int $teamId): array
    {
        $serverIds = Server::withoutGlobalScopes()
            ->where('team_id', $teamId)->where('is_active', true)->pluck('id');

        $serversTotal = $serverIds->count();

        if ($serversTotal === 0) {
            return ['servers_total' => 0, 'servers_silent' => 0, 'worst_server' => null];
        }

        $recentIds = ServerMetric::whereIn('server_id', $serverIds)
            ->where('captured_at', '>=', now()->subMinutes(15))
            ->distinct()->pluck('server_id');

        $serversSilent = $serverIds->diff($recentIds)->count();

        // Worst server: highest disk_percent (last metric per server)
        $worst = ServerMetric::query()
            ->select('server_id', 'cpu_percent', 'ram_percent', 'disk_percent', 'load_avg_1')
            ->whereIn('server_id', $serverIds)
            ->whereIn('id', fn ($q) => $q->select(DB::raw('MAX(id)'))
                ->from('server_metrics')
                ->whereIn('server_id', $serverIds)
                ->groupBy('server_id'))
            ->orderByDesc('disk_percent')
            ->first();

        $worstServer = null;
        if ($worst) {
            $srv = Server::withoutGlobalScopes()->find($worst->server_id, ['id', 'name']);
            if ($srv) {
                $worstServer = [
                    'id' => $srv->id,
                    'name' => $srv->name,
                    'cpu' => (float) $worst->cpu_percent,
                    'ram' => (float) $worst->ram_percent,
                    'disk' => (float) $worst->disk_percent,
                    'load_1' => (float) $worst->load_avg_1,
                ];
            }
        }

        return [
            'servers_total' => $serversTotal,
            'servers_silent' => $serversSilent,
            'worst_server' => $worstServer,
        ];
    }

    private function alertingHealth(int $teamId): array
    {
        $channelsActive = NotificationChannel::withoutGlobalScopes()
            ->where('team_id', $teamId)->where('is_active', true)->count();

        // notification_logs has no team_id — join via monitors to scope by team.
        $lastSentAt = NotificationLog::query()
            ->join('monitors', 'notification_logs.monitor_id', '=', 'monitors.id')
            ->where('monitors.team_id', $teamId)
            ->where('notification_logs.status', 'sent')
            ->max('notification_logs.sent_at');

        $failures24h = (int) NotificationLog::query()
            ->join('monitors', 'notification_logs.monitor_id', '=', 'monitors.id')
            ->where('monitors.team_id', $teamId)
            ->where('notification_logs.status', 'failed')
            ->where('notification_logs.sent_at', '>=', now()->subDay())
            ->count();

        return [
            'channels_active' => $channelsActive,
            'last_notification_at' => $lastSentAt
                ? Carbon::parse($lastSentAt)->toIso8601String()
                : null,
            'failures_24h' => $failures24h,
        ];
    }

    private function computeBreakdown(int $teamId): array
    {
        $rows = Insight::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNull('acknowledged_at')
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->selectRaw('severity, COUNT(*) as cnt')
            ->groupBy('severity')
            ->toBase()
            ->get();

        $warnings = 0;
        $infoOpportunity = 0;
        foreach ($rows as $row) {
            if ($row->severity === InsightSeverity::WARNING->value) {
                $warnings += (int) $row->cnt;
            } elseif (in_array($row->severity, [InsightSeverity::INFO->value, InsightSeverity::OPPORTUNITY->value], true)) {
                $infoOpportunity += (int) $row->cnt;
            }
        }

        return ['warnings' => $warnings, 'info_opportunity' => $infoOpportunity];
    }

    /**
     * SLA target and current-month uptime — mirrors MetricsService::getDashboardMetrics().
     *
     * Returns [float $slaTarget, float|null $slaCurrent].
     * slaCurrent is null when the team has no monitors yet (bar is hidden by the frontend).
     *
     * @return array{float, float|null}
     */
    private function computeSla(Team $team): array
    {
        $slaTarget = (float) ($team->sla_target ?? 99.90);

        $allMonitorIds = Monitor::withoutGlobalScopes()
            ->where('team_id', $team->id)->pluck('id');

        if ($allMonitorIds->isEmpty()) {
            return [$slaTarget, null];
        }

        $slaCurrent = (float) (MonitorCheck::whereIn('monitor_id', $allMonitorIds)
            ->where('checked_at', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE('.MonitorCheck::uptimeRaw(2).', 100) as uptime')
            ->value('uptime') ?? 100.0);

        return [$slaTarget, $slaCurrent];
    }

    private function emptyDomainHealth(): array
    {
        return [
            'availability' => ['monitors_total' => 0, 'monitors_up' => 0,
                'incidents_open' => 0, 'uptime_30d' => 100.0, 'worst_monitor' => null],
            'seo_business' => ['health_avg' => null, 'quick_wins' => 0, 'worst_site' => null],
            'infrastructure' => ['servers_total' => 0, 'servers_silent' => 0, 'worst_server' => null],
            'alerting' => ['channels_active' => 0, 'last_notification_at' => null, 'failures_24h' => 0],
        ];
    }
}
