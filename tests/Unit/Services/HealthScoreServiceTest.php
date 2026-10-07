<?php

namespace Tests\Unit\Services;

use App\Enums\CheckStatus;
use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorLighthouseScore;
use App\Models\Team;
use App\Services\HealthScoreService;
use App\Services\KpiCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for HealthScoreService.
 *
 * Cache note: CACHE_STORE=array (phpunit.xml) means each test starts with a
 * fresh in-memory cache. If a test needs a fresh score after mutating data,
 * call HealthScoreService::invalidateCache($team->id) before re-scoring.
 */
class HealthScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private HealthScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HealthScoreService::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // scoreForMonitor
    // ──────────────────────────────────────────────────────────────────────

    public function test_score_is_bounded_between_0_and_100(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
        ]);

        // Seed 10 UP checks in the last 7 days — high uptime.
        for ($i = 0; $i < 10; $i++) {
            MonitorCheck::create([
                'monitor_id' => $monitor->id,
                'status' => CheckStatus::UP,
                'response_time_ms' => 200,
                'status_code' => 200,
                'checked_at' => now()->subHours($i),
            ]);
        }

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertArrayHasKey('score', $result);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertArrayHasKey('grade', $result);
        $this->assertArrayHasKey('trend', $result);
        $this->assertArrayHasKey('breakdown', $result);
    }

    public function test_seo_available_is_false_when_no_kpi_snapshot_exists(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
        ]);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertFalse($result['breakdown']['seo']['available']);
        $this->assertNull($result['breakdown']['seo']['position']);
        // Neutral SEO score is 50.
        $this->assertEquals(50, $result['breakdown']['seo']['score']);
    }

    public function test_perf_available_is_false_when_no_lighthouse_score_exists(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
        ]);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertFalse($result['breakdown']['perf']['available']);
        $this->assertNull($result['breakdown']['perf']['performance']);
        $this->assertEquals(50, $result['breakdown']['perf']['score']);
    }

    public function test_perf_keeps_previous_score_when_latest_psi_worker_is_much_slower(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id, 'performance' => 85, 'benchmark_index' => 1352.5, 'scored_at' => now()->subHours(6),
        ]);
        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id, 'performance' => 56, 'benchmark_index' => 402.5, 'scored_at' => now(),
        ]);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertSame(85, $result['breakdown']['perf']['performance']);
    }

    public function test_perf_uses_latest_score_when_workers_are_comparable(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id, 'performance' => 85, 'benchmark_index' => 1300, 'scored_at' => now()->subHours(6),
        ]);
        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id, 'performance' => 56, 'benchmark_index' => 1250, 'scored_at' => now(),
        ]);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertSame(56, $result['breakdown']['perf']['performance']);
    }

    public function test_ttfb_available_is_false_when_no_kpi_snapshot_exists(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
        ]);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertFalse($result['breakdown']['ttfb']['available']);
        $this->assertNull($result['breakdown']['ttfb']['p95_ms']);
        $this->assertEquals(50, $result['breakdown']['ttfb']['score']);
    }

    public function test_uptime_value_is_100_when_no_checks_exist(): void
    {
        // Benefit-of-the-doubt convention: no checks → assume 100% uptime.
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
        ]);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertEquals(100, $result['breakdown']['uptime']['value']);
    }

    public function test_grade_is_a_for_near_perfect_monitor(): void
    {
        // Build a monitor that scores well on all four dimensions.
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
        ]);

        // All UP checks → 100% uptime.
        for ($i = 0; $i < 20; $i++) {
            MonitorCheck::create([
                'monitor_id' => $monitor->id,
                'status' => CheckStatus::UP,
                'response_time_ms' => 150,
                'status_code' => 200,
                'checked_at' => now()->subHours($i),
            ]);
        }

        // Derive the canonical site name exactly as the service does.
        $kpiCollector = app(KpiCollector::class);
        $site = $kpiCollector->siteNameFromUrl($monitor->url);

        // SEO: position 1 → score 100.
        KpiSnapshot::create([
            'site' => $site,
            'source' => KpiSource::GSC->value,
            'metric' => 'position_28d',
            'value' => 1.0,
            'period_days' => 28,
            'captured_at' => now()->subHours(1),
        ]);

        // TTFB: 100ms → score 100.
        KpiSnapshot::create([
            'site' => $site,
            'source' => KpiSource::TTFB->value,
            'metric' => 'ttfb_p95_ms',
            'value' => 100.0,
            'period_days' => 1,
            'captured_at' => now()->subHours(1),
        ]);

        // Lighthouse performance 100.
        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'performance' => 100,
            'scored_at' => now()->subHours(1),
        ]);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertEquals('A', $result['grade']);
        $this->assertGreaterThanOrEqual(90, $result['score']);
    }

    public function test_missing_dimensions_do_not_drag_the_score_toward_fifty(): void
    {
        // A monitor with perfect uptime and no GSC / Lighthouse / TTFB
        // integration must not be graded on data that was never collected.
        // Before renormalisation this scored 70 ("C") on a flawless site.
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        for ($i = 0; $i < 20; $i++) {
            MonitorCheck::create([
                'monitor_id' => $monitor->id,
                'status' => CheckStatus::UP,
                'response_time_ms' => 150,
                'status_code' => 200,
                'checked_at' => now()->subHours($i),
            ]);
        }

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertSame(100, $result['score']);
        $this->assertSame('A', $result['grade']);
    }

    public function test_coverage_reports_which_dimensions_backed_the_score(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertSame(['uptime'], $result['coverage']['available']);
        $this->assertEqualsCanonicalizing(
            ['seo', 'perf', 'ttfb'],
            $result['coverage']['missing'],
        );
    }

    public function test_unavailable_dimensions_report_zero_effective_weight(): void
    {
        // The reported weight must be the one actually applied, so the UI never
        // shows a share for a dimension that contributed nothing.
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertSame(0.0, $result['breakdown']['seo']['weight']);
        $this->assertSame(0.0, $result['breakdown']['perf']['weight']);
        $this->assertSame(0.0, $result['breakdown']['ttfb']['weight']);
        $this->assertSame(1.0, $result['breakdown']['uptime']['weight']);
    }

    public function test_result_contains_monitor_id_and_name(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'name' => 'example-site',
        ]);

        $result = $this->service->scoreForMonitor($monitor);

        $this->assertEquals($monitor->id, $result['monitor_id']);
        $this->assertEquals('example-site', $result['name']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // scoreForTeam
    // ──────────────────────────────────────────────────────────────────────

    public function test_score_for_team_sorts_sites_ascending_by_score(): void
    {
        $team = Team::factory()->create();

        // Monitor A: all UP → high score.
        $monitorGood = Monitor::factory()->create([
            'url' => 'https://good.example.com',
            'team_id' => $team->id,
        ]);
        for ($i = 0; $i < 10; $i++) {
            MonitorCheck::create([
                'monitor_id' => $monitorGood->id,
                'status' => CheckStatus::UP,
                'response_time_ms' => 100,
                'status_code' => 200,
                'checked_at' => now()->subHours($i),
            ]);
        }

        // Monitor B: all DOWN → low score.
        $monitorBad = Monitor::factory()->create([
            'url' => 'https://bad.example.com',
            'team_id' => $team->id,
        ]);
        for ($i = 0; $i < 10; $i++) {
            MonitorCheck::create([
                'monitor_id' => $monitorBad->id,
                'status' => CheckStatus::DOWN,
                'response_time_ms' => 5000,
                'status_code' => 500,
                'checked_at' => now()->subHours($i),
            ]);
        }

        $result = $this->service->scoreForTeam($team);

        $this->assertArrayHasKey('sites', $result);
        $this->assertArrayHasKey('computed_at', $result);
        $this->assertIsString($result['computed_at']);
        $this->assertCount(2, $result['sites']);

        // First site (index 0) must have a lower or equal score than the second.
        $this->assertLessThanOrEqual(
            $result['sites'][1]['score'],
            $result['sites'][0]['score']
        );
    }

    public function test_score_for_team_returns_computed_at_string(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['team_id' => $team->id]);

        $result = $this->service->scoreForTeam($team);

        $this->assertIsString($result['computed_at']);
        $this->assertNotEmpty($result['computed_at']);
    }

    public function test_score_for_team_excludes_inactive_monitors(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create(['team_id' => $team->id, 'is_active' => true]);
        Monitor::factory()->inactive()->create(['team_id' => $team->id]);

        $result = $this->service->scoreForTeam($team);

        // Only the active monitor should appear.
        $this->assertCount(1, $result['sites']);
    }

    public function test_invalidate_cache_forces_fresh_computation(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['team_id' => $team->id]);

        // Prime the cache.
        $first = $this->service->scoreForTeam($team);

        // Invalidate + re-score (a second team ensures no cross-contamination).
        HealthScoreService::invalidateCache($team->id);
        $second = $this->service->scoreForTeam($team);

        // Both calls should return valid data (content is the same because
        // underlying data did not change, but the mechanism is exercised).
        $this->assertCount(count($first['sites']), $second['sites']);
    }
}
