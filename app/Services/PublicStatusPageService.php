<?php

namespace App\Services;

use App\Enums\CheckStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\StatusPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the props of a public status page.
 *
 * Everything returned here is shown to anonymous visitors: only the public
 * monitor name, cause labels, timestamps and durations leave this class —
 * never monitor URLs, raw error messages or incident notes.
 *
 * Global team scopes are bypassed on purpose: the page belongs to one team and
 * must render the same for anyone, including a logged-in member of another team
 * (the result is cached and shared).
 */
class PublicStatusPageService
{
    public const TIMELINE_DAYS = 90;

    public const PAST_INCIDENT_DAYS = 14;

    public function __construct(private readonly UptimeTimelineService $timeline) {}

    /** @return array<string, mixed> */
    public function build(StatusPage $statusPage, string $canonicalUrl): array
    {
        $monitors = $statusPage->monitors()
            ->withoutGlobalScopes()
            ->get(['monitors.id', 'monitors.name']);
        $monitorIds = $monitors->pluck('id');
        $names = $monitors->mapWithKeys(fn (Monitor $m) => [$m->id => $this->publicName($m->name)]);

        $latestStatus = MonitorCheck::query()
            ->whereIn('id', fn ($q) => $q->selectRaw('MAX(id)')->from('monitor_checks')->whereIn('monitor_id', $monitorIds)->groupBy('monitor_id'))
            ->get(['monitor_id', 'status', 'response_time_ms'])
            ->keyBy('monitor_id');

        $timelines = $this->timeline->timeline($monitorIds, self::TIMELINE_DAYS);

        $monitorsData = $monitors->map(function (Monitor $monitor) use ($latestStatus, $timelines, $names) {
            $timeline = $timelines[$monitor->id];
            $latest = $latestStatus->get($monitor->id);

            return [
                'id' => $monitor->id,
                'name' => $names[$monitor->id],
                'current_status' => $latest?->status->value ?? 'unknown',
                'response_time_ms' => $latest?->response_time_ms,
                'uptime_30d' => $timeline['uptime_30d'],
                'uptime_90d' => $timeline['uptime_90d'],
                'measured_days' => $timeline['measured_days'],
                'daily_breakdown' => array_map(fn (array $day) => [
                    'date' => $day['date'],
                    'uptime' => $day['uptime'],
                    'status' => $day['status'],
                    'incidents' => array_map(fn (array $i) => $this->publicIncident($i), $day['incidents']),
                ], $timeline['days']),
            ];
        })->values();

        $incidents = MonitorIncident::withoutGlobalScope('team_via_monitor')->whereIn('monitor_id', $monitorIds);

        $activeIncidents = (clone $incidents)
            ->whereNull('resolved_at')
            ->orderByDesc('started_at')
            ->get(['id', 'monitor_id', 'started_at', 'resolved_at', 'cause'])
            ->map(fn (MonitorIncident $i) => $this->incidentRow($i, $names));

        $pastIncidents = (clone $incidents)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', now()->subDays(self::PAST_INCIDENT_DAYS))
            ->orderByDesc('started_at')
            ->limit(50)
            ->get(['id', 'monitor_id', 'started_at', 'resolved_at', 'cause'])
            ->map(fn (MonitorIncident $i) => $this->incidentRow($i, $names));

        $lastResolvedAt = (clone $incidents)->whereNotNull('resolved_at')->max('resolved_at');

        $summary = [
            'total' => $monitorsData->count(),
            'down' => $monitorsData->where('current_status', CheckStatus::DOWN->value)->count(),
        ];
        $overallStatus = $this->overallStatus($summary, $activeIncidents->isNotEmpty());

        return [
            'statusPage' => [
                'name' => $statusPage->name,
                'description' => $statusPage->description,
                'theme' => $statusPage->theme,
            ],
            'monitors' => $monitorsData,
            'activeIncidents' => $activeIncidents->values(),
            'pastIncidents' => $pastIncidents->values(),
            'past_incident_days' => self::PAST_INCIDENT_DAYS,
            'overall_status' => $overallStatus,
            'summary' => $summary,
            'days_since_last_incident' => match (true) {
                $activeIncidents->isNotEmpty() => 0,
                $lastResolvedAt === null => null,
                default => (int) CarbonImmutable::parse($lastResolvedAt)->startOfDay()->diffInDays(now()->startOfDay()),
            },
            'generated_at' => now()->toIso8601String(),
            'meta' => [
                'canonical' => $canonicalUrl,
                'description' => $this->metaDescription($statusPage->name, $overallStatus, $summary),
            ],
        ];
    }

    /**
     * "degraded" is only claimed from a reliable signal: every monitor answers
     * but an incident is still open (functional check, KPI regression, …).
     *
     * @param  array{total: int, down: int}  $summary
     */
    public function overallStatus(array $summary, bool $hasActiveIncident): string
    {
        return match (true) {
            $summary['down'] > 0 && $summary['down'] === $summary['total'] => 'major_outage',
            $summary['down'] > 0 => 'partial_outage',
            $hasActiveIncident => 'degraded',
            default => 'operational',
        };
    }

    /** @param  array{total: int, down: int}  $summary */
    private function metaDescription(string $name, string $status, array $summary): string
    {
        $services = $summary['total'].' '.($summary['total'] === 1 ? 'service' : 'services');

        $state = match ($status) {
            'major_outage' => "Major outage: all {$services} are down.",
            'partial_outage' => "Partial outage: {$summary['down']} of {$services} down.",
            'degraded' => "Degraded performance: all {$services} are up, an incident is being investigated.",
            default => "All {$services} operational.",
        };

        return "Live status of {$name}. {$state} 90-day uptime history and recent incidents.";
    }

    /** A monitor named after its URL is shown by host only: no path, query or credentials. */
    private function publicName(string $name): string
    {
        if (! preg_match('#^https?://#i', $name)) {
            return $name;
        }

        return parse_url($name, PHP_URL_HOST) ?: $name;
    }

    /**
     * @param  Collection<int, string>  $names
     * @return array<string, mixed>
     */
    private function incidentRow(MonitorIncident $incident, Collection $names): array
    {
        $end = $incident->resolved_at ?? now();

        return [
            'id' => $incident->id,
            'monitor_name' => $names[$incident->monitor_id] ?? null,
            'cause' => $incident->cause->value,
            'cause_label' => $incident->cause->label(),
            'started_at' => $incident->started_at->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'duration_seconds' => max(0, $end->getTimestamp() - $incident->started_at->getTimestamp()),
        ];
    }

    /**
     * @param  array<string, mixed>  $incident
     * @return array<string, mixed>
     */
    private function publicIncident(array $incident): array
    {
        return [
            'cause_label' => $incident['cause_label'],
            'started_at' => $incident['started_at'],
            'resolved_at' => $incident['resolved_at'],
            'duration_seconds' => $incident['duration_seconds'],
            'ongoing' => $incident['ongoing'],
        ];
    }
}
