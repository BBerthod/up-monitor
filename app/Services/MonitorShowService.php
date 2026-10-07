<?php

namespace App\Services;

use App\Enums\InsightType;
use App\Http\Presenters\InsightTriagePresenter;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MonitorShowService
{
    public function __construct(
        private readonly TriageService $triage,
        private readonly UptimeTimelineService $uptimeTimeline,
    ) {}

    public function buildPayload(Monitor $monitor, Request $request): array
    {
        $period = $request->query('period', '24h');
        if (! in_array($period, ['6mo', '3mo', '1mo', '7d', '24h', '1h'])) {
            $period = '24h';
        }

        $checks = $monitor->checks()
            ->latest('checked_at')
            ->limit(50)
            ->get(['id', 'status', 'response_time_ms', 'status_code', 'checked_at']);

        $incidentSort = in_array($request->query('incident_sort'), ['started_at', 'resolved_at', 'cause'])
            ? $request->query('incident_sort')
            : 'started_at';
        $incidentDir = $request->query('incident_dir') === 'asc' ? 'asc' : 'desc';

        $incidents = $monitor->incidents()
            ->orderBy($incidentSort, $incidentDir)
            // severity and notes are rendered by the incident list; leaving them
            // out of the select made those columns permanently empty.
            ->paginate(15, ['id', 'started_at', 'resolved_at', 'cause', 'severity', 'notes'], 'incident_page');

        $incidentStats = $this->buildIncidentStats($monitor);

        $incidentTimeline = $monitor->incidents()
            ->where('started_at', '>=', now()->subDays(90))
            ->orderBy('started_at')
            ->get(['id', 'started_at', 'resolved_at', 'cause'])
            ->map(fn ($i) => [
                'id' => $i->id,
                'started_at' => $i->started_at->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
                'cause' => $i->cause->value,
            ]);

        $uptimeData = Cache::remember("monitor:{$monitor->id}:uptime", 300, function () use ($monitor) {
            $calc = fn ($days) => (float) ($monitor->checks()
                ->where('checked_at', '>=', now()->subDays($days))
                ->uptimePercent(1)
                ->value('uptime') ?? 100);

            return [
                'day' => $calc(1),
                'week' => $calc(7),
                'month' => $calc(30),
            ];
        });

        // Checks are pruned past the retention window, so a longer range would
        // only draw empty cells (the heatmap used to claim 12 months).
        $heatmapDays = $this->checksRetentionDays();

        $heatmapData = Cache::remember("monitor:{$monitor->id}:heatmap", 300, function () use ($monitor, $heatmapDays) {
            return $monitor->checks()
                ->where('checked_at', '>=', now()->subDays($heatmapDays - 1)->startOfDay())
                ->selectRaw('DATE(checked_at) as date, ROUND(AVG(response_time_ms)) as avg_ms')
                ->groupByRaw('DATE(checked_at)')
                ->orderBy('date')
                ->pluck('avg_ms', 'date')
                ->map(fn ($v) => (int) $v);
        });

        $lighthouseScore = $monitor->lighthouseScores()
            ->latest('scored_at')
            ->first(['performance', 'accessibility', 'best_practices', 'seo', 'lcp', 'fcp', 'cls', 'tbt', 'speed_index', 'scored_at']);

        $strikingDistance = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->whereNull('acknowledged_at')
            ->orderByDesc('impact_score')
            ->limit(20)
            ->get()
            ->map(fn (Insight $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'impact_score' => (float) $i->impact_score,
                'query' => $i->payload['query'] ?? null,
                'page' => $i->payload['page'] ?? null,
                'position' => $i->payload['position'] ?? null,
                'impressions' => $i->payload['impressions'] ?? null,
                'ctr' => $i->payload['ctr'] ?? null,
                'estimated_gain' => $i->payload['estimated_gain'] ?? null,
            ])
            ->all();

        $badgeSecret = $monitor->badge_secret;

        $chartData = $this->getChartData($monitor, $period);

        $functionalChecks = $monitor->functionalChecks()
            ->with(['results' => fn ($q) => $q->latest('checked_at')->limit(1)])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($fc) => [
                'id' => $fc->id,
                'name' => $fc->name,
                'url' => $fc->url,
                'resolved_url' => $fc->resolveUrl(),
                'type' => $fc->type->value,
                'rules' => $fc->rules,
                'check_interval' => $fc->check_interval,
                'is_enabled' => $fc->is_enabled,
                'last_status' => $fc->last_status->value,
                'last_checked_at' => $fc->last_checked_at?->toIso8601String(),
                'last_result' => $fc->results->first() ? [
                    'status' => $fc->results->first()->status->value,
                    'duration_ms' => $fc->results->first()->duration_ms,
                    'details' => $fc->results->first()->details,
                    'checked_at' => $fc->results->first()->checked_at->toIso8601String(),
                ] : null,
            ]);

        // The freshest of the last 50 checks — no extra query, `$checks` is
        // already ordered newest-first for the response-time-at-a-glance card.
        $lastCheckedAt = $checks->first()?->checked_at?->toIso8601String();

        $timeline = $this->uptimeTimeline->timeline([$monitor->id], 90)[$monitor->id] ?? null;

        return [
            // Explicit field list rather than toArray(): the model serialised
            // every column (dns_*, thresholds, timestamps…) into the page
            // payload, coupling the frontend to the schema and shipping data
            // the component never reads.
            'monitor' => array_merge($monitor->only([
                'id', 'name', 'url', 'type', 'method', 'expected_status_code',
                'keyword', 'port', 'dns_record_type', 'dns_expected_value',
                'interval', 'is_active', 'is_priority',
                'warning_threshold_ms', 'critical_threshold_ms',
                'alert_after_failures', 'site_id',
            ]), [
                'notification_channels' => $monitor->notificationChannels()
                    ->get(['notification_channels.id', 'name', 'type']),
                'badge_secret' => $badgeSecret,
            ]),
            'checks' => $checks,
            'lastCheckedAt' => $lastCheckedAt,
            'incidents' => $incidents,
            'incidentStats' => $incidentStats,
            'incidentTimeline' => $incidentTimeline,
            'incidentSort' => $incidentSort,
            'incidentDir' => $incidentDir,
            'uptime' => $uptimeData,
            'timeline' => $timeline,
            'heatmapData' => $heatmapData,
            'heatmapDays' => $heatmapDays,
            'lighthouseScore' => $lighthouseScore,
            'lighthouseHistory' => $this->getLighthouseHistory($request, $monitor),
            'strikingDistance' => $strikingDistance,
            'chartData' => $chartData,
            'currentPeriod' => $period,
            'functionalChecks' => $functionalChecks,
            'insights' => $this->buildMonitorInsights($monitor),
        ];
    }

    /** Same setting and default as PruneMonitorChecks. */
    private function checksRetentionDays(): int
    {
        return max(1, (int) config('monitoring.checks_retention_days', 90));
    }

    private function buildIncidentStats(Monitor $monitor): array
    {
        return Cache::remember("monitor:{$monitor->id}:incident_stats", 300, function () use ($monitor) {
            $stats = $monitor->incidents()
                ->selectRaw("
                    COUNT(*) as total,
                    COUNT(CASE WHEN resolved_at IS NULL THEN 1 END) as active,
                    AVG(EXTRACT(EPOCH FROM (resolved_at - started_at))/60) FILTER (WHERE resolved_at IS NOT NULL) as mttr_minutes,
                    COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(resolved_at, NOW()) - started_at))/60) FILTER (WHERE started_at >= NOW() - interval '30 days'), 0) as downtime_30d_minutes
                ")
                ->first();

            $activeIncident = $monitor->incidents()
                ->whereNull('resolved_at')
                ->latest('started_at')
                ->first(['id', 'started_at', 'cause']);

            return [
                'total' => (int) ($stats->total ?? 0),
                'active' => (int) ($stats->active ?? 0),
                'active_incident' => $activeIncident ? [
                    'id' => $activeIncident->id,
                    'started_at' => $activeIncident->started_at->toIso8601String(),
                    'cause' => $activeIncident->cause->value,
                    'cause_label' => $activeIncident->cause->label(),
                ] : null,
                'mttr_minutes' => (int) round((float) ($stats->mttr_minutes ?? 0)),
                'downtime_30d_minutes' => (int) round((float) ($stats->downtime_30d_minutes ?? 0)),
            ];
        });
    }

    public function getLighthouseHistory(Request $request, Monitor $monitor): array
    {
        $period = $request->query('lh_period', '30d');
        $days = match ($period) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        return $monitor->lighthouseScores()
            ->where('scored_at', '>=', now()->subDays($days))
            ->orderBy('scored_at')
            ->get(['performance', 'accessibility', 'best_practices', 'seo', 'lcp', 'fcp', 'cls', 'tbt', 'speed_index', 'scored_at'])
            ->toArray();
    }

    private function getChartData(Monitor $monitor, string $period): array
    {
        if (in_array($period, ['6mo', '3mo', '1mo'])) {
            $from = match ($period) {
                '6mo' => now()->subMonths(6),
                '3mo' => now()->subMonths(3),
                '1mo' => now()->subMonth(),
            };

            return $this->getAggregatedChartData($monitor, $from);
        }

        [$from, $limit] = match ($period) {
            '7d' => [now()->subDays(7), 2000],
            '24h' => [now()->subDay(), 1500],
            '1h' => [now()->subHour(), 500],
        };

        return $this->getRawChartData($monitor, $from, $limit);
    }

    private function getAggregatedChartData(Monitor $monitor, Carbon $from): array
    {
        return $monitor->checks()
            ->selectRaw('
                DATE(checked_at) as date,
                ROUND(AVG(response_time_ms)) as avg_ms,
                MIN(response_time_ms) as min_ms,
                MAX(response_time_ms) as max_ms,
                '.MonitorCheck::uptimeRaw(1).' as uptime_percent
            ')
            ->where('checked_at', '>=', $from)
            ->groupByRaw('DATE(checked_at)')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function getRawChartData(Monitor $monitor, Carbon $from, int $limit): array
    {
        return $monitor->checks()
            ->select(['id', 'status', 'response_time_ms', 'status_code', 'checked_at'])
            ->where('checked_at', '>=', $from)
            ->oldest('checked_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Open insights scoped to this monitor (max 5, sorted by impact_score desc).
     *
     * We resolve the team from the monitor relation so this method does not
     * depend on auth() — consistent with the no-auth-dependency design of
     * the triage stack.
     *
     * @return list<array>
     */
    private function buildMonitorInsights(Monitor $monitor): array
    {
        $team = $monitor->team;

        if ($team === null) {
            return [];
        }

        return $this->triage->forMonitor($this->triage->open($team), $monitor)
            ->limit(5)
            ->with(['linkedSite', 'server', 'monitor'])
            ->get()
            ->map(fn (Insight $i) => InsightTriagePresenter::present($i))
            ->values()
            ->all();
    }
}
