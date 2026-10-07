<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\MonitorType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorLighthouseScore;
use App\Models\Team;
use App\Services\PerfRegressionDetector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for PerfRegressionDetector::detectForMonitor().
 *
 * The detector judges the 2 most recent MonitorLighthouseScore rows (ordered by
 * scored_at desc) against the MEDIAN of the up-to-5 audits before them (at least
 * 3); the thresholds below must be crossed in BOTH recent runs.
 * seedScores() lays that out: 3 baseline audits ($previousAttrs) then 2 recent
 * audits ($currentAttrs).
 *
 * Detection thresholds:
 *   Performance score drop ≥10 pts AND ≥15% relative (noise floor) → WARNING
 *   Performance score drop ≥20 pts (always clears the floor)       → CRITICAL
 *   LCP: current > baseline AND current > 4000 ms → WARNING
 *   CLS: current > baseline AND current > 0.25    → WARNING
 *
 * One aggregated insight per monitor; severity = highest across all regressions.
 */
class PerfRegressionDetectorTest extends TestCase
{
    use RefreshDatabase;

    private PerfRegressionDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(PerfRegressionDetector::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function monitor(?Team $team = null, array $attrs = []): Monitor
    {
        $team ??= Team::factory()->create();

        return Monitor::factory()->create(array_merge([
            'team_id' => $team->id,
            'type' => MonitorType::HTTP->value,
            'url' => 'https://example.com',
        ], $attrs));
    }

    /**
     * Seed a baseline of three audits ($previousAttrs, oldest) followed by the two
     * consecutive recent audits ($currentAttrs).
     */
    private function seedScores(
        Monitor $monitor,
        array $previousAttrs,
        array $currentAttrs
    ): void {
        foreach ([5, 4, 3] as $daysAgo) {
            MonitorLighthouseScore::factory()->create(array_merge([
                'monitor_id' => $monitor->id,
                'scored_at' => now()->subDays($daysAgo),
            ], $previousAttrs));
        }

        MonitorLighthouseScore::factory()->create(array_merge([
            'monitor_id' => $monitor->id,
            'scored_at' => now()->subDay(),
        ], $currentAttrs));

        MonitorLighthouseScore::factory()->create(array_merge([
            'monitor_id' => $monitor->id,
            'scored_at' => now(),
        ], $currentAttrs));
    }

    /**
     * Seed one audit per entry, oldest first, one day apart, ending now.
     *
     * @param  array<int, array<string, mixed>>  $runs
     */
    private function seedSequence(Monitor $monitor, array $runs): void
    {
        $count = count($runs);

        foreach (array_values($runs) as $i => $attrs) {
            MonitorLighthouseScore::factory()->create(array_merge([
                'monitor_id' => $monitor->id,
                'performance' => 90,
                'lcp' => 2000,
                'cls' => 0.05,
                'scored_at' => now()->subDays($count - 1 - $i),
            ], $attrs));
        }
    }

    // ── Baseline median and consecutive runs ─────────────────────────────

    public function test_single_degraded_run_does_not_fire(): void
    {
        $monitor = $this->monitor();

        // Five healthy audits then ONE noisy dip: the previous run is healthy.
        $this->seedSequence($monitor, [
            ['performance' => 90], ['performance' => 91], ['performance' => 90],
            ['performance' => 92], ['performance' => 90], ['performance' => 60],
        ]);

        $this->assertSame(0, $this->detector->detectForMonitor($monitor));
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_two_consecutive_degraded_runs_fire(): void
    {
        $monitor = $this->monitor();

        $this->seedSequence($monitor, [
            ['performance' => 90], ['performance' => 91], ['performance' => 90],
            ['performance' => 92], ['performance' => 60], ['performance' => 58],
        ]);

        $this->assertSame(1, $this->detector->detectForMonitor($monitor));

        $insight = Insight::withoutGlobalScopes()->where('monitor_id', $monitor->id)->first();
        $performance = collect($insight->payload['regressions'])->firstWhere('metric', 'performance');

        $this->assertEquals(90.5, $performance['from']);
        $this->assertEquals(58, $performance['to']);
    }

    public function test_fewer_than_three_baseline_audits_produce_no_delta_insight(): void
    {
        $monitor = $this->monitor();

        // Two baseline + two recent audits: the drop is real but unjudgeable.
        $this->seedSequence($monitor, [
            ['performance' => 90], ['performance' => 91],
            ['performance' => 40], ['performance' => 38],
        ]);

        $this->assertSame(0, $this->detector->detectForMonitor($monitor));
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_baseline_is_a_median_not_an_average(): void
    {
        $monitor = $this->monitor();

        // Median of the baseline is 61; the mean (76.6) is dragged up by two
        // earlier 100s. Recent runs at 58 are only 4 below the median.
        $this->seedSequence($monitor, [
            ['performance' => 100], ['performance' => 100], ['performance' => 60],
            ['performance' => 62], ['performance' => 61],
            ['performance' => 58], ['performance' => 58],
        ]);

        $this->assertSame(0, $this->detector->detectForMonitor($monitor));
    }

    public function test_lcp_regression_needs_both_consecutive_runs(): void
    {
        $monitor = $this->monitor();

        $this->seedSequence($monitor, [
            ['lcp' => 3000], ['lcp' => 3100], ['lcp' => 3000],
            ['lcp' => 3000], ['lcp' => 3000], ['lcp' => 5200],
        ]);

        $this->assertSame(0, $this->detector->detectForMonitor($monitor));

        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'performance' => 90,
            'lcp' => 5300,
            'cls' => 0.05,
            'scored_at' => now()->addMinute(),
        ]);

        $this->assertSame(1, $this->detector->detectForMonitor($monitor));
    }

    public function test_absolute_lcp_level_alert_still_fires_without_a_baseline(): void
    {
        $monitor = $this->monitor();

        $this->seedSequence($monitor, [
            ['lcp' => 11800, 'lcp_observed' => null],
            ['lcp' => 12000, 'lcp_observed' => null],
        ]);

        $this->assertSame(1, $this->detector->detectForMonitor($monitor));
    }

    // ── Guard — fewer than 2 scores ───────────────────────────────────────

    public function test_returns_zero_when_fewer_than_two_scores_exist(): void
    {
        $monitor = $this->monitor();

        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'performance' => 90,
            'scored_at' => now(),
        ]);

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ── WARNING — performance drop ≥10 ───────────────────────────────────

    public function test_creates_warning_insight_for_14_point_performance_drop(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        $this->seedScores($monitor,
            ['performance' => 92, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 78, 'lcp' => 2000, 'cls' => 0.05]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
        $this->assertEquals(InsightType::PERF_REGRESSION, $insight->type);
        $this->assertEquals($team->id, $insight->team_id);

        $metrics = array_column($insight->payload['regressions'], 'metric');
        $this->assertContains('performance', $metrics);
    }

    // ── Noise floor — performance drop ≥10 pts but below the relative floor ──

    public function test_no_insight_for_a_10_point_drop_that_is_a_small_share_of_a_high_score(): void
    {
        $monitor = $this->monitor();

        // 92 → 82: an 10-point absolute drop clears the old raw threshold, but
        // it is only 10.9% of the previous score — below the 15% relative floor
        // (config monitoring.perf_regression.min_delta_score_pct) that filters
        // the PSI mobile score's run-to-run lab jitter on an already-good site.
        $this->seedScores($monitor,
            ['performance' => 92, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 82, 'lcp' => 2000, 'cls' => 0.05]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_creates_warning_insight_for_a_10_point_drop_that_clears_the_relative_floor(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        // 50 → 40: same 10-point absolute drop as above, but 20% relative —
        // clears both floors, so the regression is real, not jitter.
        $this->seedScores($monitor,
            ['performance' => 50, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 40, 'lcp' => 2000, 'cls' => 0.05]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    // ── CRITICAL — performance drop ≥20 ──────────────────────────────────

    public function test_creates_critical_insight_for_25_point_performance_drop(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        $this->seedScores($monitor,
            ['performance' => 95, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 70, 'lcp' => 2000, 'cls' => 0.05]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
    }

    // ── WARNING — LCP regression (perf stable) ───────────────────────────

    public function test_creates_warning_insight_for_lcp_regression_when_perf_stable(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        // Performance only drops 2 pts (below threshold); LCP crosses 4000 ms.
        $this->seedScores($monitor,
            ['performance' => 90, 'lcp' => 3000, 'cls' => 0.05],
            ['performance' => 88, 'lcp' => 4500, 'cls' => 0.05]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);

        $metrics = array_column($insight->payload['regressions'], 'metric');
        $this->assertContains('lcp', $metrics);
    }

    // ── CRITICAL — absolute LCP level confirmed by observed LCP ───────────

    public function test_suppresses_lcp_absolute_when_observed_lcp_is_fast(): void
    {
        $monitor = $this->monitor();

        $this->seedScores($monitor,
            ['performance' => 90, 'lcp' => 11800, 'cls' => 0.05],
            [
                'performance' => 90,
                'lcp' => 12000,
                'lcp_observed' => 1600,
                'benchmark_index' => 402,
                'cls' => 0.05,
            ]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_creates_lcp_absolute_when_observed_lcp_confirms_slow_paint(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        $this->seedScores($monitor,
            ['performance' => 90, 'lcp' => 11800, 'cls' => 0.05],
            [
                'performance' => 90,
                'lcp' => 12000,
                'lcp_observed' => 5000,
                'benchmark_index' => 402,
                'cls' => 0.05,
            ]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);

        $absolute = collect($insight->payload['regressions'])->firstWhere('metric', 'lcp_absolute');

        $this->assertNotNull($absolute);
        $this->assertEquals(5000, $absolute['lcp_observed']);
        $this->assertEquals(402, $absolute['benchmark_index']);
    }

    public function test_creates_lcp_absolute_for_old_runs_without_observed_lcp(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        $this->seedScores($monitor,
            ['performance' => 90, 'lcp' => 11800, 'cls' => 0.05],
            [
                'performance' => 90,
                'lcp' => 12000,
                'lcp_observed' => null,
                'benchmark_index' => 402,
                'cls' => 0.05,
            ]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);

        $absolute = collect($insight->payload['regressions'])->firstWhere('metric', 'lcp_absolute');

        $this->assertNotNull($absolute);
        $this->assertNull($absolute['lcp_observed']);
        $this->assertEquals(402, $absolute['benchmark_index']);
    }

    // ── Host comparability — deltas between PSI workers of different power ─

    public function test_skips_score_and_lcp_deltas_when_current_worker_is_much_slower(): void
    {
        $monitor = $this->monitor();

        // Same unchanged page: fast worker, then a worker 3x slower.
        $this->seedScores($monitor,
            ['performance' => 85, 'lcp' => 4100, 'lcp_observed' => 300, 'benchmark_index' => 1352.5, 'cls' => 0.05],
            ['performance' => 56, 'lcp' => 7900, 'lcp_observed' => 2300, 'benchmark_index' => 402.5, 'cls' => 0.05]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_keeps_score_delta_when_workers_are_comparable(): void
    {
        $monitor = $this->monitor();

        $this->seedScores($monitor,
            ['performance' => 85, 'lcp' => 2500, 'benchmark_index' => 1300, 'cls' => 0.05],
            ['performance' => 56, 'lcp' => 2600, 'benchmark_index' => 1250, 'cls' => 0.05]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()->where('monitor_id', $monitor->id)->first();

        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
    }

    public function test_host_guard_does_not_hide_a_cls_regression(): void
    {
        $monitor = $this->monitor();

        $this->seedScores($monitor,
            ['performance' => 85, 'lcp' => 2500, 'benchmark_index' => 1352.5, 'cls' => 0.05],
            ['performance' => 85, 'lcp' => 2500, 'benchmark_index' => 402.5, 'cls' => 0.40]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()->where('monitor_id', $monitor->id)->first();

        $this->assertContains('cls', collect($insight->payload['regressions'])->pluck('metric')->all());
    }

    // ── WARNING — CLS regression (perf stable) ───────────────────────────

    public function test_creates_warning_insight_for_cls_regression_when_perf_stable(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        // Performance only drops 2 pts; CLS crosses 0.25 poor threshold.
        $this->seedScores($monitor,
            ['performance' => 90, 'lcp' => 2000, 'cls' => 0.10],
            ['performance' => 88, 'lcp' => 2000, 'cls' => 0.30]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);

        $metrics = array_column($insight->payload['regressions'], 'metric');
        $this->assertContains('cls', $metrics);
    }

    // ── Silent — all stable or improved ──────────────────────────────────

    public function test_creates_no_insight_when_all_metrics_stable_or_improved(): void
    {
        $monitor = $this->monitor();

        // Performance improves slightly; LCP and CLS both well within thresholds.
        $this->seedScores($monitor,
            ['performance' => 85, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 88, 'lcp' => 1800, 'cls' => 0.03]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ── Idempotence — second run → exactly 1 unacknowledged insight ──────

    public function test_two_consecutive_runs_produce_exactly_one_unacknowledged_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        $this->seedScores($monitor,
            ['performance' => 92, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 78, 'lcp' => 2000, 'cls' => 0.05]
        );

        $this->detector->detectForMonitor($monitor);
        $this->detector->detectForMonitor($monitor);

        $count = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(1, $count, 'Expected exactly 1 unacknowledged PERF_REGRESSION insight after 2 runs.');
    }

    // ── First-detection date is preserved while severity is recomputed ───

    public function test_detected_at_is_preserved_across_runs_while_severity_is_recomputed(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        Carbon::setTestNow('2026-08-01 08:00:00');

        // Run 1 — a 14-point drop: WARNING.
        $this->seedScores($monitor,
            ['performance' => 92, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 78, 'lcp' => 2000, 'cls' => 0.05]
        );

        $this->detector->detectForMonitor($monitor);

        $firstInsight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->whereNull('acknowledged_at')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::WARNING, $firstInsight->severity);
        $originalDetectedAt = $firstInsight->detected_at;

        // 3 weeks later, the regression is still open and has gotten worse: a
        // fresh, lower score is recorded (two consecutive runs). The
        // median baseline stays at 92, so 92 -> 50 is 42 points: CRITICAL.
        Carbon::setTestNow('2026-08-22 08:00:00');

        foreach ([now()->subHour(), now()] as $scoredAt) {
            MonitorLighthouseScore::factory()->create([
                'monitor_id' => $monitor->id,
                'performance' => 50,
                'lcp' => 2000,
                'cls' => 0.05,
                'scored_at' => $scoredAt,
            ]);
        }

        $this->detector->detectForMonitor($monitor);

        $refreshed = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->whereNull('acknowledged_at')
            ->get();

        $this->assertCount(1, $refreshed, 'Still exactly one open insight, not a second one.');

        $refreshedInsight = $refreshed->first();

        // The age must reflect the FIRST time this problem was seen (3 weeks
        // ago), not the run that just happened.
        $this->assertTrue($originalDetectedAt->equalTo($refreshedInsight->detected_at),
            'detected_at must be inherited from the still-open insight, not reset to now().');

        // Severity must be recomputed from this run's numbers — a worsening
        // regression must not stay frozen at its first-seen severity.
        $this->assertEquals(InsightSeverity::CRITICAL, $refreshedInsight->severity);

        Carbon::setTestNow();
    }

    // ── Idempotence — previously acknowledged insight preserved ──────────

    public function test_acknowledged_insight_from_previous_run_is_preserved(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        $acknowledged = Insight::factory()
            ->acknowledged()
            ->ofType(InsightType::PERF_REGRESSION)
            ->create(['team_id' => $team->id, 'monitor_id' => $monitor->id]);

        $this->seedScores($monitor,
            ['performance' => 92, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 78, 'lcp' => 2000, 'cls' => 0.05]
        );

        $this->detector->detectForMonitor($monitor);
        $this->detector->detectForMonitor($monitor);

        $this->assertDatabaseHas('insights', ['id' => $acknowledged->id]);
    }

    // ── Recovery clears the previous insight ─────────────────────────────

    public function test_recovered_monitor_has_its_stale_insight_purged(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        // Run 1 — a real regression, so an insight is created.
        $this->seedScores($monitor,
            ['performance' => 92, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 70, 'lcp' => 2000, 'cls' => 0.05]
        );

        $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->whereNull('acknowledged_at')
            ->count());

        // Run 2 — the site has recovered: two fresh, healthy scores. The detector
        // returns 0, and the run-1 insight must NOT survive. Before the purge was
        // moved ahead of the empty-regressions early return, the only way a row
        // disappeared was being overwritten by a newer one, so a recovered monitor
        // kept its stale insight in the inbox indefinitely.
        MonitorLighthouseScore::query()->where('monitor_id', $monitor->id)->delete();

        $this->seedScores($monitor,
            ['performance' => 95, 'lcp' => 1500, 'cls' => 0.02],
            ['performance' => 96, 'lcp' => 1400, 'cls' => 0.01]
        );

        $created = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $created, 'A healthy monitor must not create an insight.');
        $this->assertSame(0, Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->whereNull('acknowledged_at')
            ->count(), 'The stale insight from the regressing run must be purged.');
    }

    public function test_recovery_purge_spares_an_acknowledged_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        // The operator already acted on this one; a later healthy run must not
        // erase that record.
        $acknowledged = Insight::factory()
            ->acknowledged()
            ->ofType(InsightType::PERF_REGRESSION)
            ->create(['team_id' => $team->id, 'monitor_id' => $monitor->id]);

        $this->seedScores($monitor,
            ['performance' => 95, 'lcp' => 1500, 'cls' => 0.02],
            ['performance' => 96, 'lcp' => 1400, 'cls' => 0.01]
        );

        $this->detector->detectForMonitor($monitor);

        $this->assertDatabaseHas('insights', ['id' => $acknowledged->id]);
    }

    public function test_missing_history_does_not_purge_a_valid_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        // Only ONE score exists, so the detector cannot compare and bails out early.
        // That early return means "I cannot judge", not "nothing is wrong", so it must
        // NOT clear an existing insight — otherwise a transient gap in Lighthouse
        // history would silently drop a live regression from the inbox.
        $insight = Insight::factory()
            ->unacknowledged()
            ->ofType(InsightType::PERF_REGRESSION)
            ->create(['team_id' => $team->id, 'monitor_id' => $monitor->id]);

        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'scored_at' => now(),
            'performance' => 90,
        ]);

        $created = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $created);
        $this->assertDatabaseHas('insights', ['id' => $insight->id, 'acknowledged_at' => null]);
    }

    // ── Auditability guard — lighthouse_enabled / structurally excluded URL ──

    public function test_returns_zero_when_lighthouse_is_disabled_on_the_monitor(): void
    {
        $monitor = $this->monitor(null, ['lighthouse_enabled' => false]);

        $this->seedScores($monitor,
            ['performance' => 92, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 60, 'lcp' => 2000, 'cls' => 0.05]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_returns_zero_for_a_structurally_non_auditable_url_even_when_lighthouse_is_enabled(): void
    {
        // Defense-in-depth: an affiliate redirect guard is never auditable,
        // regardless of the lighthouse_enabled flag — same regex as
        // DispatchLighthouseAudits, via LighthouseAuditability.
        $monitor = $this->monitor(null, [
            'url' => 'https://webcompare.fr/go/B0CGWWRBRQ/&keywords=test',
            'lighthouse_enabled' => true,
        ]);

        $this->seedScores($monitor,
            ['performance' => 92, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 15, 'lcp' => 2000, 'cls' => 0.05]
        );

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_a_stale_insight_is_purged_once_the_monitor_becomes_non_auditable(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->monitor($team);

        // Run 1 — genuinely auditable at the time, a real regression fires.
        $this->seedScores($monitor,
            ['performance' => 92, 'lcp' => 2000, 'cls' => 0.05],
            ['performance' => 60, 'lcp' => 2000, 'cls' => 0.05]
        );

        $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->whereNull('acknowledged_at')
            ->count());

        // The monitor is later disabled for Lighthouse (or its URL is recognised
        // as structurally non-auditable). The stale insight — built from a pair
        // of scores that should never have been compared — must be purged, not
        // merely stopped from growing: this is a structural "cannot ever be
        // right", unlike the fewer-than-two-scores guard, which leaves an
        // existing insight untouched because "no data yet" is not "resolved".
        $monitor->forceFill(['lighthouse_enabled' => false])->save();

        $created = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $created);
        $this->assertSame(0, Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::PERF_REGRESSION->value)
            ->whereNull('acknowledged_at')
            ->count());
    }
}
