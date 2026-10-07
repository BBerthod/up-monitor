<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Support\LighthouseAuditability;
use Illuminate\Support\Facades\Log;

/**
 * Detects Lighthouse performance regressions: the last consecutive_runs audits
 * (default 2) against the median of the baseline_runs audits (default 5) before them.
 *
 * DESIGN PHILOSOPHY
 * ──────────────────
 * Lighthouse scores fluctuate naturally by a few points between audits.  This
 * detector only fires when a meaningful regression is observed — either a
 * composite performance score drop of ≥ 10 points, or a Core Web Vital crossing
 * a "poor" threshold in the wrong direction.
 *
 * SINGLE INSIGHT PER MONITOR
 * ────────────────────────────
 * All regressions for a monitor are aggregated into ONE insight.  The payload
 * lists every regressed metric so the engineer can act on the worst offender.
 * Severity is the highest severity across all regressions found.
 *
 * IDEMPOTENCE
 * ────────────
 * Before creating a new PERF_REGRESSION insight we delete all existing
 * unacknowledged ones for the same monitor, so each daily run produces a clean,
 * de-duplicated signal rather than piling up duplicates. The outgoing insight's
 * `detected_at` is carried forward to the new one when the regression is still
 * open, so a problem that has been sitting in the inbox for three weeks keeps
 * reading as three weeks old instead of resetting to "today" on every run —
 * severity, title and payload are still fully recomputed from this run's scores.
 *
 * DETECTION THRESHOLDS
 * ─────────────────────
 * - Performance score: chute ≥ 10 pts AND clears the noise floor → WARNING;
 *   chute ≥ 20 pts (always clears it, see below) → CRITICAL.
 * - LCP (Largest Contentful Paint): current > previous AND current > 4000 ms
 *   (Google "poor" threshold) AND the regression clears the noise floor → WARNING.
 * - CLS (Cumulative Layout Shift): current > previous AND current > 0.25
 *   (Google "poor" threshold) AND the regression clears the noise floor → WARNING.
 *
 * NOISE FLOOR ON CORE WEB VITALS — AND ON THE COMPOSITE SCORE
 * ──────────────────────────────────────────────────────────
 * "current > previous" alone is far too sensitive on a page that already sits above
 * the poor threshold: it fires on every upward tick, forever. Production generated
 * insights titled "LCP worsened (4953 → 4953)" (0 ms delta, floating-point noise)
 * and "(4080 → 4082)" (2 ms), burying the handful of real regressions.
 *
 * A vital must now worsen by BOTH a relative and an absolute amount
 * (config monitoring.perf_regression). Requiring both is deliberate: the relative
 * test alone flags 2 ms moves on a fast page, the absolute test alone flags an
 * irrelevant 300 ms move on a 12 s page.
 *
 * The composite PSI mobile score is exactly the same class of noise: it is a lab
 * measurement that moves a handful of points between two audits of an unchanged
 * page, purely from shared-infrastructure jitter. Gating WARNING on the raw delta
 * alone (the original behaviour) fired on that jitter across the fleet. The same
 * clearsNoiseFloor() mechanism now applies here too — delta ≥ 10 (absolute) AND
 * ≥ min_delta_score_pct of the previous score (relative, default 15%). Unlike LCP
 * (ms) or CLS (unitless), the score is bounded to [0, 100], so a relative floor
 * expressed in the same percentage as the absolute one would be mathematically
 * inert (10% of a ≤100 score can never exceed 10 points) — hence a distinct,
 * higher default. CRITICAL (≥ 20 pts) is left unguarded: at 20+ points on a
 * ≤100 scale the relative floor is always cleared anyway, so this only documents
 * that a genuine collapse is never the one filtered out.
 *
 * PSI MOBILE LCP LEVEL VS OBSERVED LCP
 * ────────────────────────────────────
 * PageSpeed Insights reports the regular LCP as a simulated Lantern lab value.
 * On weak PSI workers that simulation can inflate a normal page into a 12 s+
 * "critical" LCP while the run's observedLargestContentfulPaint stays fast. The
 * absolute lcp_absolute alert therefore trusts old rows when no observed value
 * exists, but suppresses that CRITICAL when the current observed LCP says the
 * page actually painted below the configured observed threshold.
 *
 * The same weakness breaks every DELTA: two runs on workers of very different
 * power (environment.benchmarkIndex 1352 vs 402 on the same unchanged page gave
 * LCP 4.1 s vs 12.2 s and a "score 85 → 56" CRITICAL) compare hardware, not the
 * page. hostsAreComparable() skips the score and LCP delta rules in that case;
 * CLS is layout, not CPU-bound, and keeps its rule.
 */
class PerfRegressionDetector
{
    private const PERF_THRESHOLD_WARNING = 10;

    private const PERF_THRESHOLD_CRITICAL = 20;

    /** LCP "poor" threshold in milliseconds. */
    private const LCP_POOR_MS = 4000.0;

    /** CLS "poor" threshold. */
    private const CLS_POOR = 0.25;

    /**
     * Does a metric's regression clear the configured noise floor?
     *
     * Both tests must pass — see the class docblock for why requiring only one
     * produces either tiny false positives or misses real large-scale slowdowns.
     * Shared by the Core Web Vitals and the composite performance score, each
     * passing their own relative-percentage floor (the absolute unit differs
     * per metric; the mechanism does not).
     *
     * $delta must already be signed so that a POSITIVE value means "worse": for
     * LCP/CLS that is current − previous (higher is worse), for the composite
     * score it is previous − current (lower is worse). Computing that sign
     * inside this helper would silently invert one of the two callers.
     *
     * @param  float  $previous  Previous value (> 0 enforced by the caller).
     * @param  float  $delta  Signed worsening amount, in the metric's own unit.
     * @param  float  $minAbsoluteDelta  Minimum absolute worsening, in the metric's own unit.
     * @param  float  $minRelativePct  Minimum relative worsening, as a percentage of $previous.
     */
    private function clearsNoiseFloor(float $previous, float $delta, float $minAbsoluteDelta, float $minRelativePct): bool
    {
        if ($delta < $minAbsoluteDelta) {
            return false;
        }

        // Guard against division by zero: a previous value of 0 cannot be expressed
        // as a relative change, so the absolute test above is the only gate.
        if ($previous <= 0.0) {
            return true;
        }

        return ($delta / $previous) * 100 >= $minRelativePct;
    }

    /**
     * Were both audits run on PSI workers of comparable CPU power?
     *
     * The simulated metrics scale with the worker's benchmarkIndex, so a "drop"
     * between a fast and a much slower worker says nothing about the page. Rows
     * captured before benchmark_index existed are treated as comparable, which
     * keeps the historical behaviour.
     */
    private function hostsAreComparable(Monitor $monitor, mixed $previousIndex, mixed $currentIndex): bool
    {
        $minRatio = (float) config('monitoring.perf_regression.min_benchmark_ratio', 0.7);

        if ($minRatio <= 0.0 || $previousIndex === null || $currentIndex === null || (float) $previousIndex <= 0.0) {
            return true;
        }

        if ((float) $currentIndex / (float) $previousIndex >= $minRatio) {
            return true;
        }

        Log::info('PerfRegressionDetector: delta rules skipped, current PSI worker much slower', [
            'monitor_id' => $monitor->id,
            'benchmark_index_previous' => (float) $previousIndex,
            'benchmark_index_current' => (float) $currentIndex,
        ]);

        return false;
    }

    /**
     * Detect Lighthouse performance regressions for a single monitor.
     *
     * @return int 1 if an insight was created, 0 otherwise.
     */
    public function detectForMonitor(Monitor $monitor): int
    {
        // A monitor that is not (or no longer) Lighthouse-auditable — disabled
        // manually, or structurally excluded by LighthouseAuditability (JSON/
        // health/status endpoint, affiliate redirect guard, 3xx expected status)
        // — must never be compared, even when stale scores exist from before the
        // exclusion was introduced. Production kept re-surfacing a PERF_REGRESSION
        // insight built from a score of 15 captured while PSI followed a 302 to a
        // third-party product page: that pair never said anything about the
        // monitored site, so it is purged rather than merely stopped from growing.
        // This is a structural "cannot ever be right", unlike the fewer-than-two-
        // scores guard below, which deliberately leaves an existing insight alone.
        if ($monitor->lighthouse_enabled === false || ! LighthouseAuditability::isAuditable($monitor)) {
            Insight::withoutGlobalScopes()
                ->where('monitor_id', $monitor->id)
                ->where('type', InsightType::PERF_REGRESSION->value)
                ->whereNull('acknowledged_at')
                ->delete();

            return 0;
        }

        $consecutiveRuns = max(1, (int) config('monitoring.perf_regression.consecutive_runs', 2));
        $baselineRuns = max(1, (int) config('monitoring.perf_regression.baseline_runs', 5));
        $minBaselineRuns = (int) config('monitoring.perf_regression.min_baseline_runs', 3);

        $scores = $monitor->lighthouseScores()
            ->latest('scored_at')
            ->limit($consecutiveRuns + $baselineRuns)
            ->get([
                'performance',
                'seo',
                'lcp',
                'lcp_observed',
                'fcp',
                'cls',
                'tbt',
                'benchmark_index',
                'speed_index',
                'field_lcp_ms',
                'field_lcp_category',
                'field_cls',
                'field_cls_category',
                'field_inp_ms',
                'field_inp_category',
                'field_source',
                'scored_at',
            ]);

        if ($scores->count() < 2) {
            return 0;
        }

        // The last $consecutiveRuns audits are judged against the median of the
        // audits BEFORE them: a single noisy run can neither be the baseline nor
        // the verdict. Without enough baseline the delta rules stay silent; the
        // absolute LCP level alert below needs no baseline and keeps working.
        $recent = $scores->take($consecutiveRuns)->values();
        $baseline = $scores->slice($consecutiveRuns)->values();
        $current = $recent->first();
        $previous = $scores->get(1);

        $canCompare = $recent->count() === $consecutiveRuns && $baseline->count() >= $minBaselineRuns;

        $baselinePerf = $canCompare ? $this->median($baseline->pluck('performance')->all()) : null;
        $baselineLcp = $canCompare ? $this->median($baseline->pluck('lcp')->all()) : null;
        $baselineCls = $canCompare ? $this->median($baseline->pluck('cls')->all()) : null;
        $baselineBenchmark = $canCompare ? $this->median($baseline->pluck('benchmark_index')->all()) : null;

        $regressions = [];
        $worstSeverity = null;

        $comparableHosts = $canCompare && $recent->every(
            fn ($run): bool => $this->hostsAreComparable($monitor, $baselineBenchmark, $run->benchmark_index)
        );

        // Real-user (CrUX) data beats the lab: when the field LCP is good, every
        // alert built on the simulated lab score/LCP is dropped (CLS keeps its rule).
        $fieldIsGood = $current->field_lcp_category === 'FAST';

        if ($fieldIsGood) {
            Log::info('PerfRegressionDetector: lab score/LCP alerts suppressed by good field LCP', [
                'monitor_id' => $monitor->id,
                'field_lcp_ms' => $current->field_lcp_ms,
                'field_source' => $current->field_source,
            ]);
        }

        $currPerf = (float) $current->performance;
        $prevPerf = $baselinePerf ?? (float) $previous->performance;
        $perfDelta = $prevPerf - $currPerf;

        // ── Performance score ──────────────────────────────────────────────────
        if (! $fieldIsGood && $comparableHosts && $baselinePerf !== null) {
            $minScoreDeltaPct = (float) config('monitoring.perf_regression.min_delta_score_pct', 15);

            // Every one of the consecutive runs must be degraded past the threshold.
            $deltas = $recent->map(fn ($run): float => $baselinePerf - (float) $run->performance);
            $worstCaseDelta = (float) $deltas->min();

            if ($deltas->every(fn (float $delta): bool => $delta >= self::PERF_THRESHOLD_WARNING
                && $this->clearsNoiseFloor($baselinePerf, $delta, self::PERF_THRESHOLD_WARNING, $minScoreDeltaPct))) {
                $severity = $worstCaseDelta >= self::PERF_THRESHOLD_CRITICAL
                    ? InsightSeverity::CRITICAL
                    : InsightSeverity::WARNING;

                $regressions[] = [
                    'metric' => 'performance',
                    'from' => round($baselinePerf, 1),
                    'to' => round($currPerf, 1),
                    'delta' => round($perfDelta, 1),
                ];

                $worstSeverity = $this->maxSeverity($worstSeverity, $severity);
            }
        }

        // ── LCP (ms) ───────────────────────────────────────────────────────────
        if (! $fieldIsGood && $previous->lcp !== null && $current->lcp !== null) {
            $prevLcp = $baselineLcp ?? (float) $previous->lcp;
            $currLcp = (float) $current->lcp;

            $minLcpDelta = (float) config('monitoring.perf_regression.min_delta_lcp_ms', 300);
            $minLcpDeltaPct = (float) config('monitoring.perf_regression.min_delta_pct', 10);

            if ($comparableHosts
                && $baselineLcp !== null
                && $recent->every(fn ($run): bool => $run->lcp !== null
                    && (float) $run->lcp > self::LCP_POOR_MS
                    && $this->clearsNoiseFloor($baselineLcp, (float) $run->lcp - $baselineLcp, $minLcpDelta, $minLcpDeltaPct))) {
                $regressions[] = [
                    'metric' => 'lcp',
                    'from' => round($prevLcp, 0),
                    'to' => round($currLcp, 0),
                    'delta' => round($currLcp - $prevLcp, 0),
                ];

                $worstSeverity = $this->maxSeverity($worstSeverity, InsightSeverity::WARNING);
            }

            // Absolute-level alert: a page can be catastrophically slow AND stable, which
            // every delta-based rule above is blind to by construction. Reported even when
            // the LCP improved slightly, because the level itself is the problem.
            $criticalLcp = (float) config('monitoring.perf_regression.critical_lcp_ms', 8000);

            if ($criticalLcp > 0.0 && $currLcp >= $criticalLcp) {
                $currObservedLcp = $current->lcp_observed !== null ? (float) $current->lcp_observed : null;
                $criticalObservedLcp = (float) config('monitoring.perf_regression.critical_lcp_observed_ms', 4000);

                if ($currObservedLcp !== null && $currObservedLcp < $criticalObservedLcp) {
                    Log::info('PerfRegressionDetector: simulated LCP critical suppressed by observed LCP', [
                        'monitor_id' => $monitor->id,
                        'lcp' => $currLcp,
                        'lcp_observed' => $currObservedLcp,
                        'benchmark_index' => $current->benchmark_index !== null
                            ? (float) $current->benchmark_index
                            : null,
                    ]);
                } else {
                    $regressions[] = [
                        'metric' => 'lcp_absolute',
                        'from' => round($prevLcp, 0),
                        'to' => round($currLcp, 0),
                        'delta' => round($currLcp - $prevLcp, 0),
                        'threshold' => $criticalLcp,
                        'lcp_observed' => $currObservedLcp !== null ? round($currObservedLcp, 1) : null,
                        'benchmark_index' => $current->benchmark_index !== null
                            ? round((float) $current->benchmark_index, 1)
                            : null,
                    ];

                    $worstSeverity = $this->maxSeverity($worstSeverity, InsightSeverity::CRITICAL);
                }
            }
        }

        // ── CLS ────────────────────────────────────────────────────────────────
        if ($baselineCls !== null && $current->cls !== null) {
            $currCls = (float) $current->cls;

            $minClsDelta = (float) config('monitoring.perf_regression.min_delta_cls', 0.02);
            $minClsDeltaPct = (float) config('monitoring.perf_regression.min_delta_pct', 10);

            if ($recent->every(fn ($run): bool => $run->cls !== null
                && (float) $run->cls > self::CLS_POOR
                && $this->clearsNoiseFloor($baselineCls, (float) $run->cls - $baselineCls, $minClsDelta, $minClsDeltaPct))) {
                $regressions[] = [
                    'metric' => 'cls',
                    'from' => round($baselineCls, 3),
                    'to' => round($currCls, 3),
                    'delta' => round($currCls - $baselineCls, 3),
                ];

                $worstSeverity = $this->maxSeverity($worstSeverity, InsightSeverity::WARNING);
            }
        }

        // Idempotence: wipe previous unacknowledged PERF_REGRESSION insights for
        // this monitor.  withoutGlobalScopes() is required in job/CLI context.
        //
        // This runs BEFORE the empty-regressions early return, and that ordering is
        // the whole point: "no regression this run" must clear the previous run's
        // insight, otherwise a resolved regression stays in the inbox forever. The
        // purge used to sit after the return, so the only way an insight disappeared
        // was being replaced by a newer one — a monitor that recovered kept its stale
        // row indefinitely. Introducing the Core Web Vitals noise floor made this
        // visible at scale: filtering more regressions means far more "nothing to
        // report" runs, and production accumulated 14 such ghost rows (several at
        // impact 0, e.g. "LCP worsened (4379 -> 4414)") that no longer matched any
        // real signal. HealthDropDetector already purges before its own early
        // returns; this aligns with it.
        //
        // FIRST-DETECTION DATE: capture the outgoing insight's detected_at BEFORE
        // deleting it, so a regression that is still open on this run keeps reading
        // as "detected 3 weeks ago" instead of "detected today" every single time
        // the detector re-runs. Severity, title and payload are deliberately NOT
        // carried over below — they must reflect this run's numbers; only the age
        // is inherited.
        $existingUnacknowledged = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->whereNull('acknowledged_at')
            ->first();

        $firstDetectedAt = $existingUnacknowledged?->detected_at;

        Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->whereNull('acknowledged_at')
            ->delete();

        if (empty($regressions)) {
            return 0;
        }

        $site = $this->siteFromUrl($monitor->url);

        // Build a human-readable title from the worst regression.
        $title = $this->buildTitle($regressions, $prevPerf, $currPerf);

        // impact_score = performance score drop (float, higher = worse). The inbox sorts
        // by it and ActionPlanService derives ROI from it, so it must never be 0 on a
        // CRITICAL row: an absolute-level breach can occur with a flat or even improving
        // score, which would otherwise rank a 16 s page below a 10-point score wobble.
        $impactScore = max(0, round($perfDelta, 2));

        $absoluteBreach = collect($regressions)->firstWhere('metric', 'lcp_absolute');

        if ($absoluteBreach !== null) {
            // Score the overshoot in seconds beyond the threshold (×10 to stay on the
            // same order of magnitude as score-point deltas), floored so it always
            // outranks a silent row.
            $overshootSeconds = max(0.0, ((float) $absoluteBreach['to'] - (float) $absoluteBreach['threshold']) / 1000);
            $impactScore = max($impactScore, round(10 + $overshootSeconds * 10, 2));
        }

        Insight::create([
            'team_id' => $monitor->team_id,
            'site' => $site,
            'site_id' => $monitor->site_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::PERF_REGRESSION->value,
            'severity' => $worstSeverity->value,
            'title' => $title,
            'payload' => [
                'current' => [
                    'performance' => round($currPerf, 1),
                    'lcp' => $current->lcp !== null ? round((float) $current->lcp, 0) : null,
                    'cls' => $current->cls !== null ? round((float) $current->cls, 3) : null,
                    'scored_at' => $current->scored_at?->toIso8601String(),
                ],
                // Median of the audits preceding the consecutive runs (the previous audit
                // itself when there was not enough history for a baseline).
                'previous' => [
                    'performance' => round($prevPerf, 1),
                    'lcp' => $baselineLcp !== null
                        ? round($baselineLcp, 0)
                        : ($previous->lcp !== null ? round((float) $previous->lcp, 0) : null),
                    'cls' => $baselineCls !== null
                        ? round($baselineCls, 3)
                        : ($previous->cls !== null ? round((float) $previous->cls, 3) : null),
                    'scored_at' => $previous->scored_at?->toIso8601String(),
                ],
                'regressions' => $regressions,
                // Real-user data, when CrUX has any for this URL/origin.
                ...($current->field_lcp_category !== null || $current->field_cls_category !== null || $current->field_inp_category !== null
                    ? ['field' => [
                        'lcp_ms' => $current->field_lcp_ms,
                        'lcp_category' => $current->field_lcp_category,
                        'cls' => $current->field_cls,
                        'cls_category' => $current->field_cls_category,
                        'inp_ms' => $current->field_inp_ms,
                        'inp_category' => $current->field_inp_category,
                        'source' => $current->field_source,
                    ]]
                    : []),
            ],
            'impact_score' => $impactScore,
            'detected_at' => $firstDetectedAt ?? now(),
        ]);

        Log::info('PerfRegressionDetector: regression detected', [
            'monitor_id' => $monitor->id,
            'url' => $monitor->url,
            'site' => $site,
            'severity' => $worstSeverity->value,
            'regressions' => array_column($regressions, 'metric'),
        ]);

        return 1;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Median of the non-null values, or null when there are none.
     *
     * @param  array<int, mixed>  $values
     */
    private function median(array $values): ?float
    {
        $values = array_map(
            static fn ($value): float => (float) $value,
            array_values(array_filter($values, static fn ($value): bool => $value !== null)),
        );

        if ($values === []) {
            return null;
        }

        sort($values);

        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Return the more severe of two severities (null treated as least severe).
     */
    private function maxSeverity(?InsightSeverity $current, InsightSeverity $candidate): InsightSeverity
    {
        if ($current === null) {
            return $candidate;
        }

        $order = [
            InsightSeverity::INFO->value => 0,
            InsightSeverity::OPPORTUNITY->value => 1,
            InsightSeverity::WARNING->value => 2,
            InsightSeverity::CRITICAL->value => 3,
        ];

        return $order[$candidate->value] > $order[$current->value] ? $candidate : $current;
    }

    /**
     * Build a concise, human-readable insight title.
     */
    private function buildTitle(array $regressions, float $prevPerf, float $currPerf): string
    {
        $perfRegression = collect($regressions)->firstWhere('metric', 'performance');

        if ($perfRegression !== null) {
            return sprintf(
                'Performance regression: score %s → %s (-%s)',
                (int) $prevPerf,
                (int) $currPerf,
                (int) ($prevPerf - $currPerf),
            );
        }

        // An absolute-level breach outranks a mere delta: it states a standing fact
        // ("this page takes 16 s to render") rather than a movement, so it leads.
        $absolute = collect($regressions)->firstWhere('metric', 'lcp_absolute');

        if ($absolute !== null) {
            return sprintf(
                'LCP critically slow: %ss (threshold %ss)',
                round($absolute['to'] / 1000, 1),
                round(((float) $absolute['threshold']) / 1000, 1),
            );
        }

        // Lead with the first non-performance regression.
        $first = $regressions[0];

        return sprintf(
            'Performance regression: %s worsened (%s → %s)',
            strtoupper($first['metric']),
            $first['from'],
            $first['to'],
        );
    }

    /**
     * Derive a clean site hostname from a URL (strips "www.").
     */
    private function siteFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        // NB: use str_starts_with + substr, NOT ltrim($host, 'www.') — ltrim strips
        // a *character set* (w, .) and would corrupt hosts like "webcompare.com" → "ebcompare.com".
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
