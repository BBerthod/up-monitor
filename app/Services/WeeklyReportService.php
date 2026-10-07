<?php

namespace App\Services;

use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Team;
use Carbon\Carbon;

class WeeklyReportService
{
    public function generate(Team $team): array
    {
        $periodEnd = Carbon::now();
        $periodStart = Carbon::now()->subDays(7);

        $monitorIds = $team->monitors()->active()->pluck('id');
        $totalMonitors = $monitorIds->count();

        if ($totalMonitors === 0) {
            return $this->emptyReport($periodStart, $periodEnd);
        }

        $overallUptime = (float) (MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $periodStart)
            ->selectRaw('COALESCE('.MonitorCheck::uptimeRaw(1).', 100) as uptime')
            ->value('uptime') ?? 100);

        $avgResponseTime = (int) (MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $periodStart)
            ->avg('response_time_ms') ?? 0);

        $monitors = $team->monitors()->active()
            ->addSelect([
                'uptime_pct' => MonitorCheck::selectRaw('COALESCE('.MonitorCheck::uptimeRaw(1).', 100)')
                    ->whereColumn('monitor_checks.monitor_id', 'monitors.id')
                    ->where('checked_at', '>=', $periodStart),
                'avg_response_ms' => MonitorCheck::selectRaw('COALESCE(ROUND(AVG(response_time_ms)), 0)')
                    ->whereColumn('monitor_checks.monitor_id', 'monitors.id')
                    ->where('checked_at', '>=', $periodStart),
                'worst_response_ms' => MonitorCheck::selectRaw('COALESCE(MAX(response_time_ms), 0)')
                    ->whereColumn('monitor_checks.monitor_id', 'monitors.id')
                    ->where('checked_at', '>=', $periodStart),
                'latest_status' => MonitorCheck::select('status')
                    ->whereColumn('monitor_checks.monitor_id', 'monitors.id')
                    ->latest('checked_at')
                    ->limit(1),
            ])
            ->get()
            ->map(fn ($m) => [
                'name' => $m->name,
                'uptime_pct' => (float) ($m->uptime_pct ?? 100),
                'avg_response_ms' => (int) ($m->avg_response_ms ?? 0),
                'worst_response_ms' => (int) ($m->worst_response_ms ?? 0),
                'status' => $m->latest_status ?? 'unknown',
            ]);

        $incidents = MonitorIncident::whereIn('monitor_id', $monitorIds)
            ->where('started_at', '>=', $periodStart)
            ->with('monitor:id,name')
            ->orderByDesc('started_at')
            ->get()
            ->map(function ($incident) {
                $durationMinutes = $incident->resolved_at
                    ? (int) $incident->started_at->diffInMinutes($incident->resolved_at)
                    : null;

                return [
                    'monitor_name' => $incident->monitor->name,
                    'cause' => $incident->cause->value,
                    'started_at' => $incident->started_at->toIso8601String(),
                    'resolved_at' => $incident->resolved_at?->toIso8601String(),
                    'duration_minutes' => $durationMinutes,
                ];
            });

        $incidentCount = $incidents->count();

        $totalDowntimeMinutes = (int) MonitorIncident::whereIn('monitor_id', $monitorIds)
            ->where('started_at', '>=', $periodStart)
            ->whereNotNull('resolved_at')
            ->selectRaw('COALESCE(SUM(EXTRACT(EPOCH FROM (resolved_at - started_at)) / 60), 0) as total_minutes')
            ->value('total_minutes');

        $bestMonitor = $monitors->sortByDesc('uptime_pct')->first()['name'] ?? null;
        $worstMonitor = $monitors->sortBy('uptime_pct')->first()['name'] ?? null;

        $longestIncident = $incidents
            ->whereNotNull('duration_minutes')
            ->sortByDesc('duration_minutes')
            ->first();

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_monitors' => $totalMonitors,
            'overall_uptime' => $overallUptime,
            'avg_response_time' => $avgResponseTime,
            'monitors' => $monitors->values()->all(),
            'incidents' => $incidents->values()->all(),
            'incident_count' => $incidentCount,
            'total_downtime_minutes' => $totalDowntimeMinutes,
            'best_monitor' => $bestMonitor,
            'worst_monitor' => $worstMonitor,
            'longest_incident' => $longestIncident ? [
                'monitor_name' => $longestIncident['monitor_name'],
                'duration_minutes' => $longestIncident['duration_minutes'],
            ] : null,
        ];
    }

    private function emptyReport(Carbon $periodStart, Carbon $periodEnd): array
    {
        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_monitors' => 0,
            'overall_uptime' => 100.0,
            'avg_response_time' => 0,
            'monitors' => [],
            'incidents' => [],
            'incident_count' => 0,
            'total_downtime_minutes' => 0,
            'best_monitor' => null,
            'worst_monitor' => null,
            'longest_incident' => null,
        ];
    }
}
