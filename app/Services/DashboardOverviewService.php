<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Presentation data for the Overview cockpit: the one-sentence global state at
 * the top of the page, and the per-monitor rows (current state, last check,
 * daily uptime strip) merged into the health portfolio.
 *
 * It never computes a health score or a threshold: scores come from
 * HealthScoreService and day colours from UptimeTimelineService.
 */
class DashboardOverviewService
{
    /** Days shown on the dashboard uptime strip. */
    public const TIMELINE_DAYS = 30;

    /** Down monitors named in the sentence before collapsing into "+N more". */
    private const NAMED_DOWN_LIMIT = 2;

    public function __construct(private readonly UptimeTimelineService $timelines) {}

    /**
     * Current state of every active monitor of the team.
     *
     * "pending" = no check recorded yet. `last_checked_at` prefers the monitor
     * column (written by the scheduler) and falls back to the latest check row.
     *
     * @return array<int, array{id: int, name: string, status: string, last_checked_at: ?string, down_since: ?string}>
     */
    public function monitorStates(Team $team): array
    {
        $monitors = Monitor::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->active()
            ->get(['id', 'name', 'last_checked_at']);

        if ($monitors->isEmpty()) {
            return [];
        }

        $ids = $monitors->pluck('id');

        $latest = MonitorCheck::query()
            ->select('monitor_id', 'status', 'checked_at')
            ->whereIn('id', fn ($q) => $q->select(DB::raw('MAX(id)'))
                ->from('monitor_checks')
                ->whereIn('monitor_id', $ids)
                ->groupBy('monitor_id'))
            ->get()
            ->keyBy('monitor_id');

        $openSince = MonitorIncident::withoutGlobalScopes()
            ->whereIn('monitor_id', $ids)
            ->whereNull('resolved_at')
            ->selectRaw('monitor_id, MIN(started_at) as since')
            ->groupBy('monitor_id')
            ->toBase()
            ->get()
            ->pluck('since', 'monitor_id');

        $states = [];
        foreach ($monitors as $monitor) {
            $check = $latest->get($monitor->id);
            $status = $check ? $check->status->value : 'pending';
            $lastChecked = $monitor->last_checked_at ?? $check?->checked_at;
            $since = $status === 'down' ? ($openSince[$monitor->id] ?? null) : null;

            $states[$monitor->id] = [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'status' => $status,
                'last_checked_at' => $lastChecked?->toIso8601String(),
                'down_since' => $since ? Carbon::parse($since)->toIso8601String() : null,
            ];
        }

        return $states;
    }

    /**
     * Portfolio sites enriched with state, last check and the daily strip,
     * ordered so that what is broken comes first (down, pending, then by score).
     *
     * @param  list<array<string, mixed>>  $sites  HealthScoreService::scoreForTeam()['sites']
     * @param  array<int, array<string, mixed>>  $states  monitorStates()
     * @return list<array<string, mixed>>
     */
    public function siteRows(array $sites, array $states): array
    {
        // Only ids that belong to the team: the timeline service trusts its input.
        $ids = array_values(array_filter(
            array_map(static fn (array $s): int => (int) $s['monitor_id'], $sites),
            static fn (int $id): bool => isset($states[$id]),
        ));

        $timelines = $this->timelines->timeline($ids, self::TIMELINE_DAYS);

        $rows = array_map(function (array $site) use ($states, $timelines): array {
            $id = (int) $site['monitor_id'];
            $state = $states[$id] ?? null;
            $timeline = $timelines[$id] ?? null;

            $site['status'] = $state['status'] ?? 'pending';
            $site['last_checked_at'] = $state['last_checked_at'] ?? null;
            $site['down_since'] = $state['down_since'] ?? null;
            $site['uptime_30d'] = $timeline['uptime_30d'] ?? null;
            $site['uptime_90d'] = $timeline['uptime_90d'] ?? null;
            // Incidents are reduced to a count and total downtime: the strip
            // tooltip needs no more, and 90 days × every monitor adds up.
            $site['timeline'] = array_map(static fn (array $day): array => [
                'date' => $day['date'],
                'status' => $day['status'],
                'uptime' => $day['uptime'],
                'incidents' => count($day['incidents']),
            ], $timeline['days'] ?? []);

            return $site;
        }, $sites);

        $rank = ['down' => 0, 'pending' => 1, 'up' => 2];
        usort($rows, static fn (array $a, array $b): int => [$rank[$a['status']] ?? 1, $a['score']] <=> [$rank[$b['status']] ?? 1, $b['score']]);

        return $rows;
    }

    /**
     * The sentence that opens the cockpit, plus its parts so the page can
     * colour only the segment that is a problem.
     *
     * @param  array<int, array<string, mixed>>  $states  monitorStates()
     * @param  array{total: int, critical: int}  $counts  TriageService::counts()
     * @return array{level: string, headline: string, segments: list<array{text: string, tone: string}>, monitors_total: int, monitors_up: int, monitors_down: int, monitors_pending: int, critical: int, warnings: int}
     */
    public function statusSummary(array $states, array $counts, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        $down = array_values(array_filter($states, static fn (array $s): bool => $s['status'] === 'down'));
        $pending = count(array_filter($states, static fn (array $s): bool => $s['status'] === 'pending'));
        $total = count($states);
        $up = $total - count($down) - $pending;
        $critical = (int) ($counts['critical'] ?? 0);
        $warnings = max(0, (int) ($counts['total'] ?? 0) - $critical);

        $segments = [];

        if ($total === 0) {
            $segments[] = ['text' => 'No active monitors yet', 'tone' => 'neutral'];
        } elseif ($down !== []) {
            $segments[] = ['text' => $this->downSentence($down, $now), 'tone' => 'critical'];
        } elseif ($pending > 0) {
            $segments[] = ['text' => "{$up} of {$total} monitors up · {$pending} awaiting a first check", 'tone' => 'neutral'];
        } else {
            $segments[] = ['text' => $total === 1 ? 'The only monitor is up' : "All {$total} monitors up", 'tone' => 'ok'];
        }

        if ($critical > 0) {
            $segments[] = ['text' => $critical.' critical '.($critical === 1 ? 'issue needs' : 'issues need').' attention', 'tone' => 'critical'];
        } elseif ($warnings > 0) {
            $segments[] = ['text' => $warnings.' '.($warnings === 1 ? 'warning' : 'warnings').' to review', 'tone' => 'warning'];
        } elseif ($total > 0) {
            $segments[] = ['text' => 'nothing needs attention', 'tone' => 'ok'];
        }

        $level = match (true) {
            $down !== [] || $critical > 0 => 'critical',
            $warnings > 0 => 'warning',
            default => 'ok',
        };

        return [
            'level' => $level,
            'headline' => implode(' · ', array_column($segments, 'text')),
            'segments' => $segments,
            'monitors_total' => $total,
            'monitors_up' => $up,
            'monitors_down' => count($down),
            'monitors_pending' => $pending,
            'critical' => $critical,
            'warnings' => $warnings,
        ];
    }

    /** @param  list<array<string, mixed>>  $down */
    private function downSentence(array $down, CarbonInterface $now): string
    {
        $count = count($down);

        if ($count === 1) {
            $since = $down[0]['down_since'];
            $for = $since ? ' (for '.self::duration(Carbon::parse($since), $now).')' : '';

            return "1 monitor down: {$down[0]['name']}{$for}";
        }

        $names = array_column(array_slice($down, 0, self::NAMED_DOWN_LIMIT), 'name');
        $more = $count - count($names);

        return "{$count} monitors down: ".implode(', ', $names).($more > 0 ? " +{$more} more" : '');
    }

    /** Compact human duration: "12 min", "1 h 25", "3 d 4 h". */
    public static function duration(CarbonInterface $from, CarbonInterface $to): string
    {
        $minutes = max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));

        if ($minutes < 60) {
            return max(1, $minutes).' min';
        }

        if ($minutes < 1440) {
            $rest = $minutes % 60;

            return intdiv($minutes, 60).' h'.($rest > 0 ? ' '.str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
        }

        $hours = intdiv($minutes % 1440, 60);

        return intdiv($minutes, 1440).' d'.($hours > 0 ? " {$hours} h" : '');
    }
}
