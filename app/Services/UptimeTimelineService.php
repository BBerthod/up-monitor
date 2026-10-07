<?php

namespace App\Services;

use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Day-by-day uptime timeline for a set of monitors (status page, dashboard strip).
 *
 * Cost is fixed regardless of check volume: one aggregated query over checks
 * (GROUP BY monitor_id, day) and one over the incidents overlapping the window.
 * No check row is ever loaded in memory.
 *
 * Authorization is the caller's job: the monitor ids are trusted as given, so the
 * monitor-team global scope is bypassed (it would also hide rows on public pages
 * viewed by a logged-in member of another team).
 */
class UptimeTimelineService
{
    /** Uptime at or above this share of up checks is a clean day. */
    public const UP_THRESHOLD = 99.0;

    /** Below this share of up checks the day counts as down. */
    public const PARTIAL_THRESHOLD = 50.0;

    /**
     * @param  Collection<int, int>|array<int, int>  $monitorIds
     * @return array<int, array{
     *     days: list<array{date: string, uptime: float|null, status: string, incidents: list<array<string, mixed>>}>,
     *     uptime_30d: float|null,
     *     uptime_90d: float|null,
     *     measured_days: int,
     * }> keyed by monitor id; "uptime_90d" covers the whole requested window.
     */
    public function timeline(Collection|array $monitorIds, int $days = 90): array
    {
        $ids = collect($monitorIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $days = max(1, $days);
        $today = CarbonImmutable::now()->startOfDay();
        $windowStart = $today->subDays($days - 1);
        $shortStart = $today->subDays(min(30, $days) - 1)->toDateString();

        $counts = MonitorCheck::query()
            ->whereIn('monitor_id', $ids)
            ->where('checked_at', '>=', $windowStart)
            ->selectRaw("monitor_id, DATE(checked_at) as day, COUNT(*) as total, SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count")
            ->groupBy('monitor_id', 'day')
            ->toBase()
            ->get()
            ->groupBy('monitor_id');

        $incidents = $this->incidentsByMonitorAndDay($ids, $windowStart);

        $result = [];
        foreach ($ids as $id) {
            $rows = collect($counts->get($id, []))->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

            $timeline = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = $today->subDays($i)->toDateString();
                $row = $rows->get($date);
                $uptime = $row ? $this->percent((int) $row->up_count, (int) $row->total, 1) : null;
                $dayIncidents = $incidents[$id][$date] ?? [];

                $timeline[] = [
                    'date' => $date,
                    'uptime' => $uptime,
                    'status' => $this->dayStatus($uptime, $dayIncidents !== []),
                    'incidents' => $dayIncidents,
                ];
            }

            $short = $rows->filter(fn ($row, $date) => $date >= $shortStart);

            $result[$id] = [
                'days' => $timeline,
                'uptime_30d' => $this->percent((int) $short->sum('up_count'), (int) $short->sum('total'), 2),
                'uptime_90d' => $this->percent((int) $rows->sum('up_count'), (int) $rows->sum('total'), 2),
                'measured_days' => $rows->count(),
            ];
        }

        return $result;
    }

    /**
     * A day with an incident is never shown as clean, even when the incident was
     * too short to move the uptime below the threshold.
     */
    private function dayStatus(?float $uptime, bool $hasIncident): string
    {
        if ($uptime === null) {
            return $hasIncident ? 'partial' : 'no_data';
        }

        return match (true) {
            $uptime < self::PARTIAL_THRESHOLD => 'down',
            $uptime < self::UP_THRESHOLD, $hasIncident => 'partial',
            default => 'up',
        };
    }

    private function percent(int $up, int $total, int $decimals): ?float
    {
        return $total > 0 ? round($up * 100 / $total, $decimals) : null;
    }

    /**
     * Incidents overlapping the window, attached to every day they cover.
     *
     * @return array<int, array<string, list<array<string, mixed>>>>
     */
    private function incidentsByMonitorAndDay(Collection $ids, CarbonImmutable $windowStart): array
    {
        $now = CarbonImmutable::now();

        $rows = MonitorIncident::withoutGlobalScope('team_via_monitor')
            ->whereIn('monitor_id', $ids)
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $windowStart))
            ->orderBy('started_at')
            ->get(['id', 'monitor_id', 'started_at', 'resolved_at', 'cause']);

        $byDay = [];
        foreach ($rows as $incident) {
            $start = CarbonImmutable::parse($incident->started_at);
            $end = $incident->resolved_at ? CarbonImmutable::parse($incident->resolved_at) : $now;

            $entry = [
                'id' => $incident->id,
                'cause' => $incident->cause->value,
                'cause_label' => $incident->cause->label(),
                'started_at' => $start->toIso8601String(),
                'resolved_at' => $incident->resolved_at ? $end->toIso8601String() : null,
                'duration_seconds' => max(0, $end->getTimestamp() - $start->getTimestamp()),
                'ongoing' => $incident->resolved_at === null,
            ];

            $cursor = $start->max($windowStart)->startOfDay();
            while ($cursor->lte($end)) {
                $byDay[$incident->monitor_id][$cursor->toDateString()][] = $entry;
                $cursor = $cursor->addDay();
            }
        }

        return $byDay;
    }
}
