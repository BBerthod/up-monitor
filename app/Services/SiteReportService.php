<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\KpiSource;
use App\Enums\ReportFrequency;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorLighthouseScore;
use App\Models\Site;
use App\Support\InsightReportText;
use App\Support\ReportBenchmarks;
use App\Support\ReportFormatter;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the data payload for a single Site's periodic performance report.
 *
 * Returns a plain array (no Eloquent models) so it can be handed to both the
 * mail Blade view and the PDF Blade view unchanged. Every section is null or
 * empty when the underlying data source is not configured for the site —
 * templates must hide the section rather than render fake zeros.
 *
 * PERIOD SEMANTICS
 * ─────────────────
 * weekly  → the previous full Monday–Sunday week relative to $referenceDate.
 * monthly → the previous full calendar month relative to $referenceDate.
 * Each is compared against the immediately preceding period of equal length
 * (a shifted-back copy of the same window), not to "now".
 *
 * Periods are computed as absolute [start, end) instants rather than
 * "N days ago from now" so the report is reproducible for any reference date
 * (manual re-runs via `sites:report` included), independent of when the
 * report actually gets generated.
 *
 * DELTA / TONE — SINGLE SOURCE OF TRUTH
 * ───────────────────────────────────────
 * Every metric that is compared to the previous period gets a "{metric}_delta"
 * sub-array: ['previous' => float, 'diff' => float, 'tone' => 'good'|'bad'|'neutral'].
 * Tone is coloured by MEANING, not by the sign of the diff: delta() takes a
 * $lowerIsBetter flag per metric (e.g. response time, GSC position, incident
 * count, downtime) so a falling value can be "good" and a rising one "bad".
 * This is computed once, here — templates only read `tone`, they never
 * re-derive it from the sign of a number.
 *
 * KPI WINDOW — ROLLING 28-DAY SNAPSHOTS, NOT PERIOD AVERAGES
 * ─────────────────────────────────────────────────────────
 * GSC/GA4 values are captured daily by KpiCollector as rolling 28-day totals
 * (e.g. "clicks_28d" = clicks summed over the 28 days ending the day before
 * capture). Averaging several of those snapshots over a week double-counts
 * the overlapping days and does not mean "the week's clicks". latestMetric()
 * instead reads the single most recent snapshot within the half-open period
 * window, so "current" and "previous" are each one rolling-28d reading.
 *
 * COPY
 * ────
 * All French section explanations, the PDF lexicon and the "En bref" summary
 * fragments live in lang/fr/reports.php, requested with an explicit 'fr'
 * locale regardless of config('app.locale') — same convention as the rest of
 * this report (subject line, labels) which is French unconditionally.
 */
class SiteReportService
{
    public function __construct(
        private readonly HealthScoreService $healthScore,
    ) {}

    /**
     * @return array{
     *   site: array,
     *   frequency: string,
     *   period: array{start: Carbon, end: Carbon, label: string},
     *   comparison_label: string,
     *   summary: string,
     *   health: ?array,
     *   gauges: list<array>,
     *   uptime: array,
     *   monitors: list<array>,
     *   incidents: list<array>,
     *   gsc: ?array,
     *   ga4: ?array,
     *   lighthouse: ?array,
     *   insights: list<array>,
     * }
     */
    public function generate(Site $site, ReportFrequency $frequency, ?Carbon $referenceDate = null): array
    {
        [$periodStart, $periodEnd, $previousStart, $previousEnd] = $this->resolvePeriod($frequency, $referenceDate ?? now());

        $monitors = $site->monitors()->active()->get();
        $monitorIds = $monitors->pluck('id');

        $uptime = $this->uptimeSection($monitorIds, $periodStart, $periodEnd, $previousStart, $previousEnd);
        $gsc = $this->gscSection($site, $periodStart, $periodEnd, $previousStart, $previousEnd);
        $ga4 = $this->ga4Section($site, $periodStart, $periodEnd, $previousStart, $previousEnd);
        $lighthouse = $this->lighthouseSection($monitorIds);
        $health = $this->healthSection($site, $monitorIds);
        $insights = $this->insightsSection($site);
        $comparisonLabel = $this->comparisonLabel($frequency);

        return [
            'site' => [
                'id' => $site->id,
                'alias' => $site->alias,
                'domain' => $site->resolvedPrimaryDomain(),
            ],
            'frequency' => $frequency->value,
            'period' => [
                'start' => $periodStart,
                'end' => $periodEnd,
                'label' => $this->periodLabel($frequency, $periodStart, $periodEnd),
            ],
            'comparison_label' => $comparisonLabel,
            'summary' => $this->buildSummary($uptime, $gsc, $ga4, $insights, $comparisonLabel),
            'health' => $health,
            'gauges' => $this->gaugesSection($site, $frequency, $health, $uptime, $gsc, $lighthouse, $monitorIds, $periodStart, $periodEnd, $comparisonLabel),
            'uptime' => $uptime,
            'monitors' => $this->monitorsSection($monitors, $periodStart, $periodEnd),
            'incidents' => $this->incidentsSection($monitorIds, $periodStart, $periodEnd),
            'gsc' => $gsc,
            'ga4' => $ga4,
            'lighthouse' => $lighthouse,
            'insights' => $insights,
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Period resolution
    // ──────────────────────────────────────────────────────────

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon} [periodStart, periodEnd, previousStart, previousEnd]
     */
    private function resolvePeriod(ReportFrequency $frequency, Carbon $referenceDate): array
    {
        if ($frequency === ReportFrequency::MONTHLY) {
            $currentMonthStart = $referenceDate->copy()->startOfMonth()->startOfDay();
            $periodStart = $currentMonthStart->copy()->subMonthNoOverflow();
            $periodEnd = $currentMonthStart->copy();
            $previousStart = $periodStart->copy()->subMonthNoOverflow();
            $previousEnd = $periodStart->copy();

            return [$periodStart, $periodEnd, $previousStart, $previousEnd];
        }

        // Weekly (also the fallback for a manual/preview run).
        $currentWeekStart = $referenceDate->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $periodStart = $currentWeekStart->copy()->subWeek();
        $periodEnd = $currentWeekStart->copy();
        $previousStart = $periodStart->copy()->subWeek();
        $previousEnd = $periodStart->copy();

        return [$periodStart, $periodEnd, $previousStart, $previousEnd];
    }

    private function periodLabel(ReportFrequency $frequency, Carbon $start, Carbon $end): string
    {
        if ($frequency === ReportFrequency::MONTHLY) {
            return $start->translatedFormat('F Y');
        }

        return $start->format('d/m').' – '.$end->copy()->subDay()->format('d/m/Y');
    }

    private function comparisonLabel(ReportFrequency $frequency): string
    {
        return $frequency === ReportFrequency::MONTHLY
            ? __('reports.comparison.monthly', [], 'fr')
            : __('reports.comparison.weekly', [], 'fr');
    }

    // ──────────────────────────────────────────────────────────
    // Delta / tone — single source of truth (see class docblock)
    // ──────────────────────────────────────────────────────────

    /**
     * @return array{previous: float, diff: float, tone: string}|null
     */
    private function delta(?float $current, ?float $previous, bool $lowerIsBetter): ?array
    {
        if ($current === null || $previous === null) {
            return null;
        }

        $diff = round($current - $previous, 4);
        $tone = 'neutral';

        if (abs($diff) > 0.0001) {
            $improved = $lowerIsBetter ? $diff < 0 : $diff > 0;
            $tone = $improved ? 'good' : 'bad';
        }

        return ['previous' => $previous, 'diff' => $diff, 'tone' => $tone];
    }

    // ──────────────────────────────────────────────────────────
    // Health score
    // ──────────────────────────────────────────────────────────

    /**
     * Current composite health score for this site, averaged across its active
     * monitors — same computation SiteController uses for the cockpit header.
     * `trend` (up/down/flat) stands in for a "delta": HealthScoreService already
     * compares against the prior week internally, and re-deriving a period-exact
     * delta from the score is not meaningful (the score is not a linear metric).
     *
     * Null when the site has no active monitors.
     */
    private function healthSection(Site $site, Collection $monitorIds): ?array
    {
        if ($monitorIds->isEmpty()) {
            return null;
        }

        $teamHealth = $this->healthScore->scoreForTeam($site->team);

        $siteEntries = array_filter(
            $teamHealth['sites'],
            fn (array $entry) => in_array($entry['monitor_id'], $monitorIds->all(), true),
        );

        if ($siteEntries === []) {
            return null;
        }

        $scores = array_column($siteEntries, 'score');
        $avgScore = (int) round(array_sum($scores) / count($scores));

        $trends = array_column($siteEntries, 'trend');
        $trend = in_array('up', $trends, true)
            ? 'up'
            : (in_array('down', $trends, true) ? 'down' : 'flat');

        return ['score' => $avgScore, 'trend' => $trend];
    }

    // ──────────────────────────────────────────────────────────
    // Uptime / availability
    // ──────────────────────────────────────────────────────────

    private function uptimeSection(
        Collection $monitorIds,
        Carbon $start,
        Carbon $end,
        Carbon $previousStart,
        Carbon $previousEnd,
    ): array {
        if ($monitorIds->isEmpty()) {
            return [
                'uptime_pct' => null,
                'uptime_pct_delta' => null,
                'avg_response_ms' => null,
                'avg_response_ms_delta' => null,
                'incident_count' => 0,
                'incident_count_delta' => null,
                'total_downtime_minutes' => 0,
                'total_downtime_minutes_delta' => null,
            ];
        }

        $uptimePct = $this->uptimeFor($monitorIds, $start, $end);
        $uptimePctPrevious = $this->uptimeFor($monitorIds, $previousStart, $previousEnd);

        $avgResponseRaw = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
            ->avg('response_time_ms');

        $avgResponsePreviousRaw = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $previousStart)->where('checked_at', '<', $previousEnd)
            ->avg('response_time_ms');

        $avgResponse = $avgResponseRaw !== null ? (int) round($avgResponseRaw) : null;
        $avgResponsePrevious = $avgResponsePreviousRaw !== null ? (int) round($avgResponsePreviousRaw) : null;

        $incidentCount = MonitorIncident::whereIn('monitor_id', $monitorIds)
            ->where('started_at', '>=', $start)->where('started_at', '<', $end)
            ->count();

        $incidentCountPrevious = MonitorIncident::whereIn('monitor_id', $monitorIds)
            ->where('started_at', '>=', $previousStart)->where('started_at', '<', $previousEnd)
            ->count();

        $downtimeMinutes = $this->downtimeMinutesFor($monitorIds, $start, $end);
        $downtimeMinutesPrevious = $this->downtimeMinutesFor($monitorIds, $previousStart, $previousEnd);

        return [
            'uptime_pct' => $uptimePct,
            'uptime_pct_delta' => $this->delta($uptimePct, $uptimePctPrevious, lowerIsBetter: false),
            'avg_response_ms' => $avgResponse,
            'avg_response_ms_delta' => $this->delta(
                $avgResponse !== null ? (float) $avgResponse : null,
                $avgResponsePrevious !== null ? (float) $avgResponsePrevious : null,
                lowerIsBetter: true,
            ),
            'incident_count' => $incidentCount,
            'incident_count_delta' => $this->delta((float) $incidentCount, (float) $incidentCountPrevious, lowerIsBetter: true),
            'total_downtime_minutes' => $downtimeMinutes,
            'total_downtime_minutes_delta' => $this->delta((float) $downtimeMinutes, (float) $downtimeMinutesPrevious, lowerIsBetter: true),
        ];
    }

    private function uptimeFor(Collection $monitorIds, Carbon $start, Carbon $end): ?float
    {
        $hasChecks = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
            ->exists();

        if (! $hasChecks) {
            return null;
        }

        $uptime = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
            ->selectRaw(MonitorCheck::uptimeRaw(2).' as uptime')
            ->value('uptime');

        return $uptime !== null ? (float) $uptime : null;
    }

    private function downtimeMinutesFor(Collection $monitorIds, Carbon $start, Carbon $end): int
    {
        $minutes = MonitorIncident::whereIn('monitor_id', $monitorIds)
            ->where('started_at', '>=', $start)->where('started_at', '<', $end)
            ->whereNotNull('resolved_at')
            ->selectRaw('COALESCE(SUM(EXTRACT(EPOCH FROM (resolved_at - started_at)) / 60), 0) as total_minutes')
            ->value('total_minutes');

        return (int) round((float) $minutes);
    }

    // ──────────────────────────────────────────────────────────
    // Per-monitor list
    // ──────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Monitor>  $monitors
     * @return list<array>
     */
    private function monitorsSection(\Illuminate\Database\Eloquent\Collection $monitors, Carbon $start, Carbon $end): array
    {
        if ($monitors->isEmpty()) {
            return [];
        }

        $monitorIds = $monitors->pluck('id');

        $uptimeRows = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
            ->selectRaw('monitor_id, '.MonitorCheck::uptimeRaw(1).' as uptime')
            ->groupBy('monitor_id')
            ->pluck('uptime', 'monitor_id');

        $responseRows = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
            ->selectRaw('monitor_id, ROUND(AVG(response_time_ms)) as avg_response')
            ->groupBy('monitor_id')
            ->pluck('avg_response', 'monitor_id');

        $latestStatuses = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->whereIn('id', fn ($q) => $q
                ->selectRaw('MAX(id)')
                ->from('monitor_checks')
                ->whereIn('monitor_id', $monitorIds)
                ->groupBy('monitor_id'))
            ->get(['monitor_id', 'status'])
            ->keyBy('monitor_id');

        return $monitors->map(fn (Monitor $m) => [
            'name' => $m->name,
            'type' => $m->type->value,
            'uptime_pct' => isset($uptimeRows[$m->id]) ? (float) $uptimeRows[$m->id] : null,
            'avg_response_ms' => isset($responseRows[$m->id]) ? (int) $responseRows[$m->id] : null,
            'latest_status' => $latestStatuses->get($m->id)?->status?->value,
        ])->values()->all();
    }

    // ──────────────────────────────────────────────────────────
    // Incidents
    // ──────────────────────────────────────────────────────────

    /**
     * @return list<array>
     */
    private function incidentsSection(Collection $monitorIds, Carbon $start, Carbon $end): array
    {
        if ($monitorIds->isEmpty()) {
            return [];
        }

        return MonitorIncident::whereIn('monitor_id', $monitorIds)
            ->where('started_at', '>=', $start)->where('started_at', '<', $end)
            ->with('monitor:id,name')
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (MonitorIncident $incident) => [
                'monitor_name' => $incident->monitor?->name,
                'cause' => $incident->cause->value,
                'started_at' => $incident->started_at,
                'duration_minutes' => $incident->resolved_at
                    ? (int) $incident->started_at->diffInMinutes($incident->resolved_at)
                    : null,
            ])
            ->values()
            ->all();
    }

    // ──────────────────────────────────────────────────────────
    // SEO — Google Search Console
    // ──────────────────────────────────────────────────────────

    private function gscSection(Site $site, Carbon $start, Carbon $end, Carbon $previousStart, Carbon $previousEnd): ?array
    {
        $siteKey = $this->siteKey($site);

        $clicks = $this->latestMetric($siteKey, KpiSource::GSC, 'clicks_28d', $start, $end);
        $impressions = $this->latestMetric($siteKey, KpiSource::GSC, 'impressions_28d', $start, $end);
        $position = $this->latestMetric($siteKey, KpiSource::GSC, 'position_28d', $start, $end);
        $ctr = $this->latestMetric($siteKey, KpiSource::GSC, 'ctr_28d', $start, $end);

        if ($clicks === null && $impressions === null && $position === null) {
            return null;
        }

        $clicksPrevious = $this->latestMetric($siteKey, KpiSource::GSC, 'clicks_28d', $previousStart, $previousEnd);
        $impressionsPrevious = $this->latestMetric($siteKey, KpiSource::GSC, 'impressions_28d', $previousStart, $previousEnd);
        $positionPrevious = $this->latestMetric($siteKey, KpiSource::GSC, 'position_28d', $previousStart, $previousEnd);
        $ctrPrevious = $this->latestMetric($siteKey, KpiSource::GSC, 'ctr_28d', $previousStart, $previousEnd);

        $ctr ??= ($clicks !== null && $impressions !== null && $impressions > 0)
            ? round($clicks / $impressions * 100, 2)
            : null;

        return [
            'clicks' => $clicks,
            'clicks_delta' => $this->delta($clicks, $clicksPrevious, lowerIsBetter: false),
            'impressions' => $impressions,
            'impressions_delta' => $this->delta($impressions, $impressionsPrevious, lowerIsBetter: false),
            'ctr' => $ctr,
            'ctr_delta' => $this->delta($ctr, $ctrPrevious, lowerIsBetter: false),
            // Position is a rank: 1 = first result, so a LOWER number is better.
            'position' => $position,
            'position_delta' => $this->delta($position, $positionPrevious, lowerIsBetter: true),
        ];
    }

    // ──────────────────────────────────────────────────────────
    // SEO — Google Analytics 4
    // ──────────────────────────────────────────────────────────

    private function ga4Section(Site $site, Carbon $start, Carbon $end, Carbon $previousStart, Carbon $previousEnd): ?array
    {
        $siteKey = $this->siteKey($site);

        $users = $this->latestMetric($siteKey, KpiSource::GA4, 'users_28d', $start, $end);
        $sessions = $this->latestMetric($siteKey, KpiSource::GA4, 'sessions_28d', $start, $end);

        if ($users === null && $sessions === null) {
            return null;
        }

        $usersPrevious = $this->latestMetric($siteKey, KpiSource::GA4, 'users_28d', $previousStart, $previousEnd);
        $sessionsPrevious = $this->latestMetric($siteKey, KpiSource::GA4, 'sessions_28d', $previousStart, $previousEnd);

        return [
            'users' => $users,
            'users_delta' => $this->delta($users, $usersPrevious, lowerIsBetter: false),
            'sessions' => $sessions,
            'sessions_delta' => $this->delta($sessions, $sessionsPrevious, lowerIsBetter: false),
        ];
    }

    private function siteKey(Site $site): string
    {
        return preg_replace('/^www\./i', '', $site->resolvedPrimaryDomain()) ?? '';
    }

    /**
     * The most recent rolling-28d snapshot within [$start, $end) (see class
     * docblock — these are cumulative totals, not per-period figures, so the
     * "current" value for a period is simply its latest reading in-window).
     * Half-open, same convention as every other period query in this service.
     * Returns null when no snapshot falls in that window at all.
     */
    private function latestMetric(string $site, KpiSource $source, string $metric, Carbon $start, Carbon $end): ?float
    {
        $value = KpiSnapshot::where('site', $site)
            ->where('source', $source->value)
            ->where('metric', $metric)
            ->where('captured_at', '>=', $start)
            ->where('captured_at', '<', $end)
            ->orderByDesc('captured_at')
            ->value('value');

        return $value !== null ? round((float) $value, 2) : null;
    }

    // ──────────────────────────────────────────────────────────
    // Lighthouse
    // ──────────────────────────────────────────────────────────

    private function lighthouseSection(Collection $monitorIds): ?array
    {
        if ($monitorIds->isEmpty()) {
            return null;
        }

        /** @var MonitorLighthouseScore|null $score */
        $score = MonitorLighthouseScore::withoutGlobalScopes()
            ->whereIn('monitor_id', $monitorIds)
            ->latest('scored_at')
            ->first(['performance', 'accessibility', 'best_practices', 'seo', 'scored_at']);

        if ($score === null) {
            return null;
        }

        return [
            'performance' => $score->performance,
            'performance_color' => $this->lighthouseColor('lighthouse_performance', $score->performance),
            'accessibility' => $score->accessibility,
            'accessibility_color' => $this->lighthouseColor('lighthouse_accessibility', $score->accessibility),
            'best_practices' => $score->best_practices,
            'best_practices_color' => $this->lighthouseColor('lighthouse_best_practices', $score->best_practices),
            'seo' => $score->seo,
            'seo_color' => $this->lighthouseColor('lighthouse_seo', $score->seo),
            'scored_at' => $score->scored_at,
        ];
    }

    /**
     * The same green/yellow/orange/red band this score's OWN gauge would
     * show (see ReportBenchmarks + config/report_benchmarks.php) — this
     * summary strip must never drift from the gauges above it by keeping a
     * second, hard-coded threshold (the previous "green >= 90" rule did
     * exactly that, and contradicted the gauges' lenient boundaries).
     */
    private function lighthouseColor(string $metricKey, int $score): string
    {
        return ReportBenchmarks::classify($metricKey, (float) $score)['color'] ?? 'red';
    }

    // ──────────────────────────────────────────────────────────
    // Gauges — "Où se situe votre site"
    // ──────────────────────────────────────────────────────────

    /**
     * Builds the ordered list of gauges that have data:
     * Santé, Disponibilité, Incidents, Temps de réponse, LCP/INP/CLS
     * ("vitesse réelle", CrUX only), Performance mobile, Référencement (SEO),
     * Position Google — plus Accessibilité and Bonnes pratiques, which carry
     * `metric_key`s the mail template deliberately skips to stay short (PDF
     * renders every gauge).
     *
     * A metric with no value (never queried, or not configured in
     * report_benchmarks) is skipped entirely rather than shown at a fake
     * position — see ReportBenchmarks::classify().
     *
     * The mail no longer has a separate "health box" / KPI row to show the
     * previous-period deltas (removed for consistency with the gauges — see
     * class docblock), so the health/uptime/response_time gauges carry their
     * own `delta_text`/`delta_tone` instead (health: trend words, via
     * attachTrend(); the others: a signed diff, via attachDelta()).
     *
     * @return list<array>
     */
    private function gaugesSection(
        Site $site,
        ReportFrequency $frequency,
        ?array $health,
        array $uptime,
        ?array $gsc,
        ?array $lighthouse,
        Collection $monitorIds,
        Carbon $start,
        Carbon $end,
        string $comparisonLabel,
    ): array {
        $gauges = [];

        if ($health !== null) {
            $healthGauge = ReportBenchmarks::classify('health', (float) $health['score']);
            if ($healthGauge !== null) {
                $gauges[] = $this->attachTrend($healthGauge, $health['trend']);
            }
        }

        $uptimeGauge = ReportBenchmarks::classify(
            'uptime',
            $uptime['uptime_pct'] !== null ? (float) $uptime['uptime_pct'] : null,
        );
        if ($uptimeGauge !== null) {
            $gauges[] = $this->attachDelta($uptimeGauge, $uptime['uptime_pct_delta'], 1, ' pt', $comparisonLabel);
        }

        if ($monitorIds->isNotEmpty()) {
            $incidentsGauge = $this->incidentsGauge($frequency, $uptime);
            if ($incidentsGauge !== null) {
                $gauges[] = $incidentsGauge;
            }
        }

        $responseTimeGauge = ReportBenchmarks::classify(
            'response_time',
            $uptime['avg_response_ms'] !== null ? (float) $uptime['avg_response_ms'] : null,
        );
        if ($responseTimeGauge !== null) {
            $gauges[] = $this->attachDelta($responseTimeGauge, $uptime['avg_response_ms_delta'], 0, ' ms', $comparisonLabel);
        }

        // CrUX LCP arrives in milliseconds; the benchmark scale is in seconds.
        $lcpMs = $this->cruxMetric($site, 'lcp_p75', $start, $end);
        $lcpGauge = ReportBenchmarks::classify('lcp', $lcpMs !== null ? $lcpMs / 1000 : null);
        if ($lcpGauge !== null) {
            $gauges[] = $lcpGauge;
        }

        $inpGauge = ReportBenchmarks::classify('inp', $this->cruxMetric($site, 'inp_p75', $start, $end));
        if ($inpGauge !== null) {
            $gauges[] = $inpGauge;
        }

        $clsGauge = ReportBenchmarks::classify('cls', $this->cruxMetric($site, 'cls_p75', $start, $end));
        if ($clsGauge !== null) {
            $gauges[] = $clsGauge;
        }

        if ($lighthouse !== null) {
            $perfGauge = ReportBenchmarks::classify('lighthouse_performance', (float) $lighthouse['performance']);
            if ($perfGauge !== null) {
                $gauges[] = $perfGauge;
            }

            $seoGauge = ReportBenchmarks::classify('lighthouse_seo', (float) $lighthouse['seo']);
            if ($seoGauge !== null) {
                $gauges[] = $seoGauge;
            }
        }

        if ($gsc !== null) {
            $positionGauge = ReportBenchmarks::classify(
                'position',
                $gsc['position'] !== null ? (float) $gsc['position'] : null,
            );
            if ($positionGauge !== null) {
                $gauges[] = $positionGauge;
            }
        }

        // PDF-only gauges (mail skips them by metric_key to stay short).
        if ($lighthouse !== null) {
            $accessibilityGauge = ReportBenchmarks::classify('lighthouse_accessibility', (float) $lighthouse['accessibility']);
            if ($accessibilityGauge !== null) {
                $gauges[] = $accessibilityGauge;
            }

            $bestPracticesGauge = ReportBenchmarks::classify('lighthouse_best_practices', (float) $lighthouse['best_practices']);
            if ($bestPracticesGauge !== null) {
                $gauges[] = $bestPracticesGauge;
            }
        }

        return array_map($this->withWorldSentence(...), $gauges);
    }

    /**
     * A 'good_share' world comparison (see ReportBenchmarks) carries only the
     * raw share + whether this site clears Google's threshold — the actual
     * French sentence is composed here, worded differently depending on
     * `within_good`, so it stays alongside the rest of this report's copy in
     * lang/fr/reports.php rather than living in the (French-agnostic)
     * ReportBenchmarks class.
     */
    private function withWorldSentence(array $gauge): array
    {
        if (($gauge['world']['type'] ?? null) !== 'good_share') {
            return $gauge;
        }

        $key = $gauge['world']['within_good']
            ? 'reports.gauges.world.within_good'
            : 'reports.gauges.world.outside_good';

        $gauge['world']['sentence'] = __($key, ['share' => (int) round($gauge['world']['share'])], 'fr');

        return $gauge;
    }

    /**
     * Attaches a formatted previous-period delta ("+2,7 pt vs semaine
     * précédente") to a gauge, as `delta_text`/`delta_tone` — the mail
     * template renders this instead of the separate KPI-row deltas it used
     * to show (removed; see class docblock). No-op when the delta itself is
     * null (e.g. no data for the previous period).
     */
    private function attachDelta(array $gauge, ?array $delta, int $decimals, string $unit, string $comparisonLabel): array
    {
        if ($delta === null) {
            return $gauge;
        }

        $gauge['delta_text'] = ReportFormatter::signed($delta['diff'], $decimals, $unit).' '.$comparisonLabel;
        $gauge['delta_tone'] = $delta['tone'];

        return $gauge;
    }

    /**
     * Same `delta_text`/`delta_tone` shape as attachDelta(), but for the
     * health gauge, whose "change since last period" is HealthScoreService's
     * trend word (up/down/flat), not a numeric diff — see healthSection().
     */
    private function attachTrend(array $gauge, string $trend): array
    {
        $toneByTrend = ['up' => 'good', 'down' => 'bad', 'flat' => 'neutral'];

        $gauge['delta_text'] = '('.__('reports.trend.'.$trend, [], 'fr').')';
        $gauge['delta_tone'] = $toneByTrend[$trend] ?? 'neutral';

        return $gauge;
    }

    /**
     * The "Incidents" gauge classifies cumulated DOWNTIME (not incident
     * count) scaled to the report's period length — `report_benchmarks.incidents`
     * gives the weekly baseline, monthly multiplies every boundary by
     * `monthly_multiplier` (see ReportBenchmarks::classifyScaled()).
     *
     * The display value is a composite "{n} incident(s) · {m} min d'arrêt"
     * string, not a plain "{value}{unit}" — added as `display_value`, which
     * templates must prefer over the generic value+unit formatting when set.
     */
    private function incidentsGauge(ReportFrequency $frequency, array $uptime): ?array
    {
        $multiplier = $frequency === ReportFrequency::MONTHLY
            ? (float) config('report_benchmarks.incidents.monthly_multiplier', 4)
            : 1.0;

        $gauge = ReportBenchmarks::classifyScaled('incidents', (float) $uptime['total_downtime_minutes'], $multiplier);

        if ($gauge === null) {
            return null;
        }

        $incidentsPhrase = trans_choice(
            'reports.summary.incidents_count',
            $uptime['incident_count'],
            ['count' => $uptime['incident_count']],
            'fr',
        );

        $gauge['display_value'] = "{$incidentsPhrase} · {$uptime['total_downtime_minutes']} min d'arrêt";

        return $gauge;
    }

    /**
     * CrUX field data (LCP/INP/CLS p75), same half-open period window as
     * latestMetric() — CruxCollector persists a rolling 28-day p75 daily, so
     * "current" is its latest reading within the period, not an average.
     *
     * IMPORTANT: CruxCollector keys its snapshots by `$site->primary_domain`
     * (the raw column), NOT the www-stripped resolvedPrimaryDomain() used by
     * siteKey() for GSC/GA4/TTFB. Reading under the wrong key silently
     * returns null for every CrUX lookup.
     */
    private function cruxMetric(Site $site, string $metric, Carbon $start, Carbon $end): ?float
    {
        $value = KpiSnapshot::where('site', $site->primary_domain)
            ->where('source', KpiSource::CRUX->value)
            ->where('metric', $metric)
            ->where('captured_at', '>=', $start)
            ->where('captured_at', '<', $end)
            ->orderByDesc('captured_at')
            ->value('value');

        return $value !== null ? (float) $value : null;
    }

    // ──────────────────────────────────────────────────────────
    // Insights — "to watch"
    // ──────────────────────────────────────────────────────────

    /**
     * Top 5 open WARNING/CRITICAL insights for this site, by impact_score desc.
     *
     * `title` here is the client-facing FRENCH sentence built by
     * InsightReportText from `type` + `payload` — NEVER the Insight model's
     * own `title` column, which detectors write in English for the Up app
     * UI/Vikunja and must stay untouched by this report.
     *
     * @return list<array>
     */
    private function insightsSection(Site $site): array
    {
        return Insight::withoutGlobalScopes()
            ->where('team_id', $site->team_id)
            ->where('site_id', $site->id)
            ->whereNull('acknowledged_at')
            ->whereIn('severity', [InsightSeverity::WARNING->value, InsightSeverity::CRITICAL->value])
            ->orderByDesc('impact_score')
            ->limit(5)
            ->get(['type', 'site', 'payload', 'severity'])
            ->map(fn (Insight $insight) => [
                'title' => InsightReportText::for($insight),
                'severity' => $insight->severity->value,
            ])
            ->values()
            ->all();
    }

    // ──────────────────────────────────────────────────────────
    // "En bref" summary — rule-based, never an LLM
    // ──────────────────────────────────────────────────────────

    /**
     * Assembles a short French summary from fixed rules:
     *   - is uptime OK or not, with the incident count/downtime when not;
     *   - the single biggest positive and biggest negative change, ranked by
     *     relative magnitude (|diff| / previous) across a fixed set of
     *     tracked metrics, so a metric near zero doesn't dominate the summary
     *     just because its absolute diff looks large;
     *   - how many open alerts (insights) need attention.
     *
     * Every fragment comes from lang/fr/reports.php — no text is composed
     * ad hoc here, only which fragment applies and its placeholders.
     */
    private function buildSummary(array $uptime, ?array $gsc, ?array $ga4, array $insights, string $comparisonLabel): string
    {
        $clauses = [];

        if ($uptime['uptime_pct'] !== null) {
            if ($uptime['incident_count'] === 0 && $uptime['uptime_pct'] >= 99.9) {
                $clauses[] = __('reports.summary.uptime_ok', [], 'fr');
            } else {
                $incidentsPhrase = trans_choice(
                    'reports.summary.incidents_count',
                    $uptime['incident_count'],
                    ['count' => $uptime['incident_count']],
                    'fr',
                );

                $clauses[] = __('reports.summary.uptime_incidents', [
                    'incidents' => $incidentsPhrase,
                    'minutes' => $uptime['total_downtime_minutes'],
                ], 'fr');
            }
        }

        $candidates = array_values(array_filter([
            $this->summaryCandidate('Disponibilité', $uptime['uptime_pct_delta'], ' pt', 1),
            $this->summaryCandidate('Temps de réponse', $uptime['avg_response_ms_delta'], ' ms', 0),
            $gsc !== null ? $this->summaryCandidate('Clics Google', $gsc['clicks_delta'], '', 0) : null,
            $gsc !== null ? $this->summaryCandidate('Position moyenne', $gsc['position_delta'], '', 1) : null,
            $ga4 !== null ? $this->summaryCandidate('Visiteurs', $ga4['users_delta'], '', 0) : null,
        ]));

        $positive = collect($candidates)->where('tone', 'good')->sortByDesc('relative')->first();
        $negative = collect($candidates)->where('tone', 'bad')->sortByDesc('relative')->first();

        if ($positive !== null) {
            $clauses[] = __('reports.summary.best_change', [
                'label' => $positive['label'],
                'diff' => $positive['formatted'],
                'comparison' => $comparisonLabel,
            ], 'fr');
        }

        if ($negative !== null) {
            $clauses[] = __('reports.summary.worst_change', [
                'label' => $negative['label'],
                'diff' => $negative['formatted'],
                'comparison' => $comparisonLabel,
            ], 'fr');
        }

        $alertCount = count($insights);
        $clauses[] = $alertCount > 0
            ? trans_choice('reports.summary.alerts_open', $alertCount, ['count' => $alertCount], 'fr')
            : __('reports.summary.alerts_none', [], 'fr');

        return __('reports.summary.intro', [], 'fr').implode(' ; ', $clauses).'.';
    }

    /**
     * @return array{label: string, tone: string, relative: float, formatted: string}|null
     */
    private function summaryCandidate(string $label, ?array $delta, string $unit, int $decimals): ?array
    {
        if ($delta === null || $delta['tone'] === 'neutral' || (float) $delta['previous'] === 0.0) {
            return null;
        }

        return [
            'label' => $label,
            'tone' => $delta['tone'],
            'relative' => abs($delta['diff'] / $delta['previous']),
            'formatted' => $this->formatDiff($delta['diff'], $decimals, $unit),
        ];
    }

    private function formatDiff(float $diff, int $decimals, string $unit): string
    {
        return ReportFormatter::signed($diff, $decimals, $unit);
    }
}
