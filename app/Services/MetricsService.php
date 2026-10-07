<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorLighthouseScore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    public function getDashboardMetrics(): array
    {
        abort_unless(auth()->check(), 403, 'MetricsService requires auth context');

        $teamId = auth()->user()->team_id;

        return Cache::remember("dashboard:metrics:{$teamId}", 60, fn () => $this->computeDashboardMetrics());
    }

    public static function invalidateCache(int $teamId): void
    {
        Cache::forget("dashboard:metrics:{$teamId}");
    }

    private function computeDashboardMetrics(): array
    {
        $teamId = auth()->user()->team_id;
        $team = \App\Models\Team::find($teamId);

        $totalMonitors = Monitor::count();
        $monitorsPaused = Monitor::inactive()->count();

        // All monitor IDs for this team — used to scope MonitorCheck queries
        // (MonitorCheck has no team_id column and no global scope).
        $teamMonitorIds = Monitor::pluck('id');
        $activeMonitorIds = Monitor::active()->pluck('id');

        $latestChecks = MonitorCheck::query()
            ->select('monitor_id', 'status')
            ->whereIn('monitor_id', $activeMonitorIds)
            ->whereIn('id', function ($q) use ($activeMonitorIds) {
                $q->select(DB::raw('MAX(id)'))
                    ->from('monitor_checks')
                    ->whereIn('monitor_id', $activeMonitorIds)
                    ->groupBy('monitor_id');
            })
            ->get();

        $monitorsUp = $latestChecks->filter(fn ($c) => $c->status->value === 'up')->count();
        $monitorsDown = $latestChecks->filter(fn ($c) => $c->status->value === 'down')->count();

        $checksLast24h = MonitorCheck::whereIn('monitor_id', $teamMonitorIds)
            ->where('checked_at', '>=', now()->subDay());

        $avgUptime24h = (float) ($checksLast24h->clone()
            ->uptimePercent(1)
            ->value('uptime') ?? 100);

        $avgResponseTime24h = (int) ($checksLast24h->clone()->avg('response_time_ms') ?? 0);

        $percentiles = MonitorCheck::whereIn('monitor_id', $teamMonitorIds)
            ->where('checked_at', '>=', now()->subDay())
            ->selectRaw('PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time_ms) as p95')
            ->selectRaw('PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY response_time_ms) as p99')
            ->first();

        $p95ResponseTime24h = (int) ($percentiles->p95 ?? 0);
        $p99ResponseTime24h = (int) ($percentiles->p99 ?? 0);

        $slaTarget = (float) ($team->sla_target ?? 99.90);

        $slaCurrentMonth = (float) (MonitorCheck::whereIn('monitor_id', $teamMonitorIds)
            ->where('checked_at', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE('.MonitorCheck::uptimeRaw(2).', 100) as uptime')
            ->value('uptime') ?? 100);

        $totalChecksToday = MonitorCheck::whereIn('monitor_id', $teamMonitorIds)
            ->where('checked_at', '>=', now()->startOfDay())
            ->count();

        // MonitorIncident is scoped via ScopedByMonitorTeam global scope.
        $activeIncidents = MonitorIncident::whereNull('resolved_at')->count();

        $downMonitorIds = $latestChecks
            ->filter(fn ($c) => $activeMonitorIds->contains($c->monitor_id) && $c->status->value === 'down')
            ->pluck('monitor_id');

        $downMonitors = Monitor::whereIn('id', $downMonitorIds)
            ->limit(5)
            ->get(['id', 'name', 'url', 'last_checked_at'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'url' => $m->url,
                'last_checked_at' => $m->last_checked_at?->toIso8601String(),
            ]);

        $recentIncidents = MonitorIncident::with('monitor:id,name')
            ->orderByDesc('started_at')
            ->limit(5)
            ->get(['id', 'monitor_id', 'cause', 'started_at', 'resolved_at'])
            ->map(fn ($i) => [
                'id' => $i->id,
                'monitor_name' => $i->monitor->name,
                'monitor_id' => $i->monitor_id,
                'cause' => $i->cause->value,
                'started_at' => $i->started_at->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
            ]);

        $responseTimeChart = MonitorCheck::whereIn('monitor_id', $teamMonitorIds)
            ->where('checked_at', '>=', now()->subDay())
            ->selectRaw("DATE_TRUNC('hour', checked_at) as hour, ROUND(AVG(response_time_ms)) as avg_ms")
            ->groupByRaw("DATE_TRUNC('hour', checked_at)")
            ->orderBy('hour')
            ->get()
            ->map(fn ($r) => [
                'hour' => $r->hour,
                'avg_ms' => (int) $r->avg_ms,
            ]);

        $monitorsOverview = Monitor::active()
            ->addSelect([
                'uptime_24h' => MonitorCheck::selectRaw('COALESCE('.MonitorCheck::uptimeRaw(1).', 100)')
                    ->whereColumn('monitor_checks.monitor_id', 'monitors.id')
                    ->where('checked_at', '>=', now()->subDay()),
                'uptime_7d' => MonitorCheck::selectRaw('COALESCE('.MonitorCheck::uptimeRaw(1).', 100)')
                    ->whereColumn('monitor_checks.monitor_id', 'monitors.id')
                    ->where('checked_at', '>=', now()->subDays(7)),
                'last_response_ms' => MonitorCheck::selectRaw('COALESCE(response_time_ms, 0)')
                    ->whereColumn('monitor_checks.monitor_id', 'monitors.id')
                    ->latest('checked_at')
                    ->limit(1),
            ])
            ->get()
            ->map(function ($m) use ($latestChecks) {
                $latestCheck = $latestChecks->firstWhere('monitor_id', $m->id);
                $status = $latestCheck ? $latestCheck->status->value : 'unknown';

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'type' => $m->type->value,
                    'status' => $status,
                    'uptime_24h' => (float) $m->uptime_24h,
                    'uptime_7d' => (float) $m->uptime_7d,
                    'last_response_ms' => (int) $m->last_response_ms,
                ];
            });

        $lighthouseOverview = MonitorLighthouseScore::query()
            ->whereIn('id', function ($q) {
                $q->select(DB::raw('MAX(id)'))
                    ->from('monitor_lighthouse_scores')
                    ->groupBy('monitor_id');
            })
            ->with('monitor:id,name')
            ->get(['id', 'monitor_id', 'performance', 'accessibility', 'best_practices', 'seo', 'scored_at'])
            ->map(fn ($s) => [
                'monitor_id' => $s->monitor_id,
                'monitor_name' => $s->monitor->name,
                'performance' => $s->performance,
                'accessibility' => $s->accessibility,
                'best_practices' => $s->best_practices,
                'seo' => $s->seo,
                'scored_at' => $s->scored_at->toIso8601String(),
                '_avg' => ($s->performance + $s->accessibility + $s->best_practices + $s->seo) / 4,
            ])
            ->sortBy('_avg')
            ->values()
            ->map(fn ($item) => collect($item)->except('_avg')->all());

        return [
            'total_monitors' => $totalMonitors,
            'monitors_up' => $monitorsUp,
            'monitors_down' => $monitorsDown,
            'monitors_paused' => $monitorsPaused,
            'avg_uptime_24h' => $avgUptime24h,
            'avg_response_time_24h' => $avgResponseTime24h,
            'p95_response_time_24h' => $p95ResponseTime24h,
            'p99_response_time_24h' => $p99ResponseTime24h,
            'sla_target' => $slaTarget,
            'sla_current_month' => $slaCurrentMonth,
            'total_checks_today' => $totalChecksToday,
            'active_incidents' => $activeIncidents,
            'down_monitors' => $downMonitors,
            'recent_incidents' => $recentIncidents,
            'response_time_chart' => $responseTimeChart,
            'monitors_overview' => $monitorsOverview,
            'lighthouse_overview' => $lighthouseOverview,
        ];
    }
}
