<?php

namespace App\Services;

use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorLighthouseScore;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;

/**
 * Computes a composite health score (0–100) for each monitored site.
 *
 * The score blends four dimensions that describe whether the site WORKS:
 *   - Uptime (40%)  : 7-day uptime percentage from MonitorCheck
 *   - SEO    (30%)  : GSC average position over the last 7 days
 *   - Perf   (20%)  : Latest Lighthouse performance score (0–100)
 *   - TTFB   (10%)  : Latest TTFB p95 in milliseconds
 *
 * A dimension with no data is dropped and its weight renormalised across the
 * dimensions that do have data, so the score always describes the site rather
 * than our measurement coverage. `coverage` in the result reports what backed it.
 *
 * plus a fifth that describes whether it still EARNS:
 *   - Monetization (15%) : revenue trend, ad fill rate, and unresolved
 *     revenue-blocking insights — applied only to sites that declare an ad
 *     network or a merchant, and redistributed across the four above otherwise.
 *
 * The fifth dimension exists because the first four cannot see the failure that
 * matters most here: a site can be up, fast and well ranked while earning
 * nothing, and has repeatedly scored an untroubled A while doing so.
 *
 * Weights are configurable via config('monitoring.health_score.weights') so
 * operators can tune them per-environment without touching code.
 *
 * IMPORTANT — no auth() dependency: this service is called from both web
 * controllers (auth context present) and queue jobs (no auth context). Models
 * and team/monitor objects are always passed explicitly.
 *
 * N+1 note: scoreForTeam() eager-loads monitors once and calls scoreForMonitor()
 * on each. Each scoreForMonitor() does ~4 lightweight queries that are not
 * correlated subqueries (unlike MetricsService::monitorsOverview). For 13
 * monitors cached 5 minutes this is ~52 DB round-trips per cache miss —
 * acceptable. If the portfolio grows past ~50 monitors, batch the uptime
 * computation using correlated addSelect() subqueries like MetricsService does.
 */
class HealthScoreService
{
    public function __construct(
        private readonly KpiCollector $kpiCollector,
    ) {}

    // ──────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────

    /**
     * Compute the health score breakdown for a single monitor.
     *
     * @return array{
     *   monitor_id: int,
     *   site: string,
     *   name: string,
     *   score: int,
     *   grade: string,
     *   trend: string,
     *   breakdown: array
     * }
     */
    public function scoreForMonitor(Monitor $monitor): array
    {
        $weights = config('monitoring.health_score.weights');
        $site = $this->kpiCollector->siteNameFromUrl($monitor->url);

        [$uptimeScore, $uptimeValue] = $this->computeUptimeScore($monitor);
        [$seoScore, $seoAvailable, $position] = $this->computeSeoScore($site);
        [$perfScore, $perfAvailable, $perfValue] = $this->computePerfScore($monitor);
        [$ttfbScore, $ttfbAvailable, $ttfbP95] = $this->computeTtfbScore($site);
        [$moneyScore, $moneyApplies, $moneyDetail] = $this->computeMonetizationScore($monitor, $site);

        // A dimension with no data is EXCLUDED, not scored 50 at full weight.
        //
        // Counting an absent signal as a neutral 50 does not leave the score
        // untouched — it drags it toward 50. A site with 98% uptime and no GSC,
        // Lighthouse or TTFB integration scored 69 ("D") purely because three
        // quarters of the weight came from data that was never collected. The
        // grade then described our coverage, not the site.
        //
        // Instead the core weights are renormalised over the dimensions that
        // actually have data, which is the same treatment monetization already
        // received below. When every dimension is available the result is
        // identical to the previous formula.
        $core = [
            'uptime' => ['score' => $uptimeScore, 'weight' => (float) $weights['uptime'], 'available' => true],
            'seo' => ['score' => $seoScore, 'weight' => (float) $weights['seo'], 'available' => $seoAvailable],
            'perf' => ['score' => $perfScore, 'weight' => (float) $weights['perf'], 'available' => $perfAvailable],
            'ttfb' => ['score' => $ttfbScore, 'weight' => (float) $weights['ttfb'], 'available' => $ttfbAvailable],
        ];

        $covered = array_filter($core, static fn (array $d): bool => $d['available'] && $d['weight'] > 0.0);
        $coveredWeight = array_sum(array_column($covered, 'weight'));

        // Uptime always has data (it falls back to 100), so $coveredWeight is
        // only zero if uptime itself is configured to weight 0.
        $coreScore = $coveredWeight > 0.0
            ? array_sum(array_map(static fn (array $d): float => $d['score'] * $d['weight'], $covered)) / $coveredWeight
            : 100.0;

        // Monetization is likewise REDISTRIBUTED rather than neutralised when it
        // does not apply. The core dimensions answer "is this site working"; this
        // one answers "is it still earning", which is meaningless on a site that
        // was never monetised.
        $moneyWeight = (float) ($weights['monetization'] ?? 0.0);

        $rawScore = $moneyApplies && $moneyWeight > 0.0
            ? $coreScore * (1.0 - $moneyWeight) + $moneyScore * $moneyWeight
            : $coreScore;

        $score = (int) round(max(0, min(100, $rawScore)));

        // Effective weight actually applied, after renormalisation — an
        // unavailable dimension reports 0 so the UI never shows a weight that
        // contributed nothing.
        $effective = function (string $key) use ($core, $covered, $coveredWeight, $moneyApplies, $moneyWeight): float {
            if (! isset($covered[$key]) || $coveredWeight <= 0.0) {
                return 0.0;
            }

            $share = $core[$key]['weight'] / $coveredWeight;

            return round($moneyApplies && $moneyWeight > 0.0 ? $share * (1.0 - $moneyWeight) : $share, 4);
        };

        return [
            'monitor_id' => $monitor->id,
            'site' => $site,
            'name' => $monitor->name,
            'score' => $score,
            'grade' => $this->grade($score),
            'trend' => $this->computeTrend($monitor, $site, $weights),
            // How much of the intended signal actually backs this score, so the
            // UI can distinguish "healthy" from "we only measured uptime".
            'coverage' => [
                'available' => array_keys($covered),
                'missing' => array_keys(array_diff_key($core, $covered)),
                'ratio' => round($coveredWeight, 4),
            ],
            'breakdown' => [
                'uptime' => [
                    'score' => (int) round($uptimeScore),
                    'weight' => $effective('uptime'),
                    'available' => true,
                    'value' => $uptimeValue,              // uptime % (0–100)
                ],
                'seo' => [
                    'score' => (int) round($seoScore),
                    'weight' => $effective('seo'),
                    'available' => $seoAvailable,
                    'position' => $position,              // null when unavailable
                ],
                'perf' => [
                    'score' => (int) round($perfScore),
                    'weight' => $effective('perf'),
                    'available' => $perfAvailable,
                    'performance' => $perfValue,            // Lighthouse 0–100, null when unavailable
                ],
                'ttfb' => [
                    'score' => (int) round($ttfbScore),
                    'weight' => $effective('ttfb'),
                    'available' => $ttfbAvailable,
                    'p95_ms' => $ttfbP95,               // ms, null when unavailable
                ],
                'monetization' => [
                    'score' => (int) round($moneyScore),
                    // Reported as 0 when it does not apply, so the UI shows the
                    // weight actually used rather than a configured value that
                    // was redistributed away.
                    'weight' => $moneyApplies ? $moneyWeight : 0.0,
                    'applies' => $moneyApplies,
                    'detail' => $moneyDetail,
                ],
            ],
        ];
    }

    /**
     * Compute health scores for all active monitors belonging to a team.
     *
     * Results are sorted ascending by score (worst performers first) because
     * the copilot dashboard surfaces sites that need attention.
     *
     * Cached for 5 minutes per team to absorb repeated calls from the
     * dashboard and the weekly digest job without hammering the DB.
     *
     * @return array{sites: list<array>, computed_at: string}
     */
    public function scoreForTeam(Team $team): array
    {
        return Cache::remember("health:team:{$team->id}", 300, function () use ($team) {
            // Eager-load once; apply the active scope so paused monitors are excluded.
            $monitors = $team->monitors()->active()->get();

            $sites = $monitors
                ->map(fn (Monitor $m) => $this->scoreForMonitor($m))
                ->sortBy('score')               // worst first
                ->values()
                ->all();

            return [
                'sites' => $sites,
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Invalidate the team health score cache.
     * Mirror of MetricsService::invalidateCache() — call after any monitor
     * state change (check run, config update) that warrants a fresh score.
     */
    public static function invalidateCache(int $teamId): void
    {
        Cache::forget("health:team:{$teamId}");
    }

    // ──────────────────────────────────────────────────────────
    // Dimension computers
    // ──────────────────────────────────────────────────────────

    /**
     * Uptime score — 7-day uptime percentage.
     *
     * The uptime % is already in [0, 100] so no further normalisation is
     * needed. Null result (no checks yet) defaults to 100 — same "benefit of
     * the doubt" convention as MetricsService.
     *
     * @return array{float, float} [score (0–100), raw uptime %]
     */
    private function computeUptimeScore(Monitor $monitor): array
    {
        $uptime = (float) (MonitorCheck::where('monitor_id', $monitor->id)
            ->where('checked_at', '>=', now()->subDays(7))
            ->selectRaw('COALESCE('.MonitorCheck::uptimeRaw(2).', 100) as uptime')
            ->value('uptime') ?? 100.0);

        return [$uptime, $uptime];
    }

    /**
     * Previous-week uptime (days -7 → -14) for trend computation.
     */
    private function previousUptimeScore(Monitor $monitor): float
    {
        return (float) (MonitorCheck::where('monitor_id', $monitor->id)
            ->whereBetween('checked_at', [now()->subDays(14), now()->subDays(7)])
            ->selectRaw('COALESCE('.MonitorCheck::uptimeRaw(2).', 100) as uptime')
            ->value('uptime') ?? 100.0);
    }

    /**
     * SEO score derived from the 7-day average GSC position.
     *
     * Normalisation: position 1 → 100, position 26+ → 0.
     * Formula: max(0, 100 - (position - 1) * 4)
     * Examples: pos 1→100, pos 6→80, pos 11→60, pos 26→0.
     *
     * Position "lower is better" so we invert to a score where higher is better.
     * When no GSC data exists the returned score is a placeholder and the
     * `available` flag is false — scoreForMonitor() then drops this dimension
     * and renormalises the remaining weights, so a monitor with no GSC
     * integration is not marked down for it.
     *
     * @return array{float, bool, ?float} [score, available, position]
     */
    private function computeSeoScore(string $site): array
    {
        $position = KpiSnapshot::averageOver($site, KpiSource::GSC, 'position_28d', 7);

        if ($position === null) {
            return [50.0, false, null];
        }

        $score = (float) max(0, 100 - ($position - 1) * 4);

        return [$score, true, $position];
    }

    /**
     * Monetization health: is this site still earning what it should?
     *
     * WHY THIS DIMENSION EXISTS
     * ─────────────────────────
     * Every other dimension answers "is the site working". A site can pass all
     * of them — up, fast, well ranked — while earning nothing, because its
     * consent platform vanished, its ad slots stopped filling, or its affiliate
     * redirects broke. That combination has happened here more than once and
     * scored an untroubled A each time.
     *
     * WHAT IT MEASURES
     * ────────────────
     * Two independent signals, whichever are available:
     *
     *  - REVENUE TREND: current earnings against the trailing 30-day average.
     *    A site earning half its usual amount is the clearest possible symptom,
     *    whatever the cause.
     *  - FILL RATE: served impressions over ad requests. Catches inventory that
     *    is requested but never filled — the "one ad slot and it is empty" case
     *    — which revenue alone can hide on a low-traffic day.
     *
     * Unresolved revenue-blocking insights (missing consent, broken affiliate
     * redirects) apply a penalty on top: they are known causes of the very
     * failure this dimension is meant to catch, and waiting for the revenue
     * series to confirm them costs days.
     *
     * APPLIES ONLY TO MONETISED SITES
     * ───────────────────────────────
     * Returns applies=false when the site declares no ad network and no
     * merchant, so its weight is redistributed rather than counted (see
     * scoreForMonitor). A brochure site is not unhealthy for earning nothing.
     *
     * @return array{float, bool, array<string, mixed>} [score, applies, detail]
     */
    private function computeMonetizationScore(Monitor $monitor, string $site): array
    {
        $siteModel = $monitor->site;

        $runsAds = is_array($siteModel?->ad_networks) && $siteModel->ad_networks !== [];
        $runsAffiliate = is_array($siteModel?->merchant_domains) && $siteModel->merchant_domains !== [];

        if (! $runsAds && ! $runsAffiliate) {
            return [50.0, false, ['reason' => 'not_monetised']];
        }

        $detail = [];
        $components = [];

        // ── Revenue trend ────────────────────────────────────────────────────
        $current = KpiSnapshot::averageOver($site, KpiSource::ADSENSE, 'earnings_28d', 7);
        $baseline = KpiSnapshot::averageOver($site, KpiSource::ADSENSE, 'earnings_28d', 30);

        if ($current !== null && $baseline !== null && $baseline > 0.0) {
            $ratio = $current / $baseline;

            // Ratio 1.0 (holding steady) scores 100; half the usual earnings
            // scores 50; anything at or above the baseline is capped at 100 —
            // earning more than usual is good news, not extra health.
            $components[] = max(0.0, min(100.0, $ratio * 100));

            $detail['revenue_ratio'] = round($ratio, 3);
            $detail['earnings_current'] = round($current, 2);
            $detail['earnings_baseline'] = round($baseline, 2);
        }

        // ── Fill rate ────────────────────────────────────────────────────────
        $fillRate = KpiSnapshot::averageOver($site, KpiSource::ADSENSE, 'ad_fill_rate_28d', 7);

        if ($fillRate !== null) {
            // Already a percentage; a healthy site fills most of what it asks for.
            $components[] = max(0.0, min(100.0, $fillRate));
            $detail['fill_rate'] = round($fillRate, 2);
        }

        // ── Known revenue blockers ───────────────────────────────────────────
        // Types are listed as literals rather than enum cases on purpose: two of
        // them ship on sibling branches, and a dimension that fatals when a
        // detector is absent would be worse than one that simply counts fewer
        // blockers. Unknown values match nothing, which degrades cleanly.
        $blockers = Insight::withoutGlobalScopes()
            ->where('site', $site)
            ->whereIn('type', [
                'cmp_missing',
                'affiliate_redirect_broken',
                'affiliate_leak',
            ])
            ->whereNull('acknowledged_at')
            ->count();

        if ($components === []) {
            // Monetised, but no revenue data yet — normal before the AdSense
            // connector is configured. Stay neutral rather than inventing a
            // verdict, but still let a known blocker pull the score down: a
            // missing consent platform is a fact, not an inference.
            $score = 50.0 - min(30.0, $blockers * 15.0);
            $detail['reason'] = 'no_revenue_data';
            $detail['blockers'] = $blockers;

            return [max(0.0, $score), true, $detail];
        }

        $score = array_sum($components) / count($components);

        // Each unresolved blocker costs 15 points, capped at 30 so a single
        // dimension can never zero out an otherwise healthy site on its own.
        $penalty = min(30.0, $blockers * 15.0);
        $detail['blockers'] = $blockers;

        return [max(0.0, $score - $penalty), true, $detail];
    }

    /**
     * Performance score from the most recent Lighthouse run.
     *
     * Lighthouse already returns a 0–100 score so no normalisation is needed.
     * The score is a placeholder when no Lighthouse data exists; the
     * `available` flag false makes scoreForMonitor() drop the dimension.
     *
     * When the latest run landed on a PSI worker much slower than the previous
     * one (benchmark_index ratio below monitoring.perf_regression.min_benchmark_ratio),
     * the previous score is kept: the drop measures the hardware, not the page, and
     * would otherwise surface as a HEALTH_DROP the perf detector already discarded.
     *
     * @return array{float, bool, ?int} [score, available, performance_value]
     */
    private function computePerfScore(Monitor $monitor): array
    {
        $scores = $monitor->lighthouseScores()
            ->latest('scored_at')
            ->limit(2)
            ->get(['performance', 'benchmark_index', 'scored_at']);

        /** @var MonitorLighthouseScore|null $latest */
        $latest = $scores->first();

        if ($latest === null) {
            return [50.0, false, null];
        }

        $previous = $scores->count() === 2 ? $scores->last() : null;
        $minRatio = (float) config('monitoring.perf_regression.min_benchmark_ratio', 0.7);

        if ($previous !== null
            && $minRatio > 0.0
            && $latest->benchmark_index !== null
            && $previous->benchmark_index !== null
            && (float) $previous->benchmark_index > 0.0
            && (float) $latest->benchmark_index / (float) $previous->benchmark_index < $minRatio) {
            $latest = $previous;
        }

        return [(float) $latest->performance, true, $latest->performance];
    }

    /**
     * TTFB score derived from the latest p95 millisecond reading.
     *
     * ≤200ms → 100 (excellent)
     * 200–800ms → linearly interpolated to 20 (poor)
     * ≥800ms → 20 (floor — bad but not zero, as TTFB alone shouldn't kill a site)
     *
     * Formula: max(20, min(100, 100 - (ttfb - 200) / 6))
     * Examples: 200ms→100, 500ms→50, 800ms→20.
     *
     * The score is a placeholder when no TTFB snapshot exists; the
     * `available` flag false makes scoreForMonitor() drop the dimension.
     *
     * @return array{float, bool, ?float} [score, available, p95_ms]
     */
    private function computeTtfbScore(string $site): array
    {
        $snapshot = KpiSnapshot::latestFor($site, KpiSource::TTFB, 'ttfb_p95_ms');

        if ($snapshot === null) {
            return [50.0, false, null];
        }

        $ttfb = (float) $snapshot->value;
        $score = (float) max(20.0, min(100.0, 100.0 - ($ttfb - 200.0) / 6.0));

        return [$score, true, $ttfb];
    }

    // ──────────────────────────────────────────────────────────
    // Trend computation
    // ──────────────────────────────────────────────────────────

    /**
     * Compute a simple week-over-week trend signal.
     *
     * Strategy: compare the current-week composite (uptime + SEO) against the
     * prior-week composite. Perf and TTFB are excluded because they rarely
     * change week-over-week and add noise. If prior-week data is absent the
     * uptime comparison alone is used as a proxy.
     *
     * delta ≥ +3 → 'up'  (measurable improvement)
     * delta ≤ −3 → 'down' (measurable degradation)
     * otherwise  → 'flat'
     *
     * A 3-point dead-band filters out statistical jitter from small check
     * windows so day-to-day noise doesn't flip the trend arrow.
     */
    private function computeTrend(Monitor $monitor, string $site, array $weights): string
    {
        // Current week components (already computed, but cheaply re-fetched to
        // keep this method self-contained and avoid coupling to the caller's
        // local variables).
        [$currentUptime] = $this->computeUptimeScore($monitor);
        [$currentSeo] = $this->computeSeoScore($site);

        // Prior week: uptime window -14d→-7d; GSC position over 14d vs 7d.
        $previousUptime = $this->previousUptimeScore($monitor);

        // Prior-week SEO: average position over the 7-day window ending 7 days
        // ago (i.e. days -7 to -14 relative to now).
        // KpiSnapshot::averageOver() anchors to now(), so we approximate by
        // using the 30-day baseline as a stable prior — an imperfect but
        // practical proxy that avoids a custom date-windowed query.
        $previousPosition = KpiSnapshot::averageOver($site, KpiSource::GSC, 'position_28d', 30);
        $previousSeoScore = $previousPosition !== null
            ? (float) max(0, 100 - ($previousPosition - 1) * 4)
            : $currentSeo; // identical → contributes 0 delta

        // Weighted partial scores (only the two time-sensitive dimensions).
        $uptimeWeight = $weights['uptime'];
        $seoWeight = $weights['seo'];
        $partialDenominator = $uptimeWeight + $seoWeight;

        // Avoid division by zero if someone zeroes both weights in config.
        if ($partialDenominator <= 0) {
            return 'flat';
        }

        $currentPartial = ($currentUptime * $uptimeWeight + $currentSeo * $seoWeight) / $partialDenominator;
        $previousPartial = ($previousUptime * $uptimeWeight + $previousSeoScore * $seoWeight) / $partialDenominator;

        $delta = $currentPartial - $previousPartial;

        return match (true) {
            $delta >= 3.0 => 'up',
            $delta <= -3.0 => 'down',
            default => 'flat',
        };
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }
}
