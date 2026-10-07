<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Services\HealthDropDetector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for HealthDropDetector::detectForMonitor().
 *
 * Snapshots are created via KpiSnapshot::factory() with:
 *   source = KpiSource::CUSTOM->value, metric = 'health_score'
 *   site   = 'example.com'  (matches monitor url https://example.com)
 *
 * Grade scale: A>=90 | B>=80 | C>=70 | D>=60 | F<60
 * Grade rank:  A=4   | B=3   | C=2   | D=1   | F=0
 *
 * Severity: CRITICAL when drop >= 2 grades or landing on F; WARNING otherwise.
 * Idempotence: unacknowledged HEALTH_DROP for (monitor_id) deleted before each run.
 */
class HealthDropDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeMonitor(Team $team, Site $site): Monitor
    {
        return Monitor::factory()->create([
            'team_id' => $team->id,
            'url' => 'https://example.com',
            'site_id' => $site->id,
        ]);
    }

    private function makeSite(Team $team): Site
    {
        return Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'example.com',
            'is_active' => true,
        ]);
    }

    /** Create a health_score KpiSnapshot for site = 'example.com'. */
    private function snapshot(float $value, string $grade, \DateTimeInterface $capturedAt): KpiSnapshot
    {
        return KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::CUSTOM->value,
            'metric' => 'health_score',
            'value' => $value,
            'period_days' => 1,
            'captured_at' => $capturedAt,
            'meta' => ['grade' => $grade],
        ]);
    }

    // -------------------------------------------------------------------------
    // Guard: fewer than 2 snapshots → 0
    // -------------------------------------------------------------------------

    public function test_returns_zero_with_fewer_than_two_snapshots(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site);

        $this->snapshot(82.0, 'B', now()->subDay());

        $count = (new HealthDropDetector)->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // -------------------------------------------------------------------------
    // B → D (2 grades) → CRITICAL
    // -------------------------------------------------------------------------

    public function test_drop_two_grades_b_to_d_creates_critical_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site);

        $this->snapshot(82.0, 'B', now()->subDay());
        $this->snapshot(64.0, 'D', now());

        $count = (new HealthDropDetector)->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertEquals($team->id, $insight->team_id);
        $this->assertSame('B', $insight->payload['previous_grade']);
        $this->assertSame('D', $insight->payload['current_grade']);
    }

    // -------------------------------------------------------------------------
    // A → B (1 grade) → WARNING
    // -------------------------------------------------------------------------

    public function test_drop_one_grade_a_to_b_creates_warning_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site);

        $this->snapshot(92.0, 'A', now()->subDay());
        $this->snapshot(85.0, 'B', now());

        $count = (new HealthDropDetector)->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    // -------------------------------------------------------------------------
    // C → F → CRITICAL (drop to F, regardless of grade count)
    // -------------------------------------------------------------------------

    public function test_drop_to_f_creates_critical_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site);

        $this->snapshot(75.0, 'C', now()->subDay());
        $this->snapshot(55.0, 'F', now());

        $count = (new HealthDropDetector)->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
    }

    // -------------------------------------------------------------------------
    // Same grade (88 B → 84 B) → 0 insights
    // -------------------------------------------------------------------------

    public function test_same_grade_creates_no_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site);

        $this->snapshot(88.0, 'B', now()->subDay());
        $this->snapshot(84.0, 'B', now());

        $count = (new HealthDropDetector)->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // -------------------------------------------------------------------------
    // Improvement (C → B) → 0 insights
    // -------------------------------------------------------------------------

    public function test_grade_improvement_creates_no_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site);

        $this->snapshot(70.0, 'C', now()->subDay());
        $this->snapshot(85.0, 'B', now());

        $count = (new HealthDropDetector)->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // -------------------------------------------------------------------------
    // Idempotence: 2 runs with same regression → 1 unacknowledged insight
    // -------------------------------------------------------------------------

    public function test_idempotent_two_runs_produce_one_unacknowledged_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site);

        $this->snapshot(82.0, 'B', now()->subDay());
        $this->snapshot(64.0, 'D', now());

        $detector = new HealthDropDetector;
        $detector->detectForMonitor($monitor);
        $detector->detectForMonitor($monitor);

        $count = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(1, $count, 'Expected exactly 1 unacknowledged HEALTH_DROP insight after 2 runs.');
    }

    public function test_detected_at_is_preserved_across_runs_while_severity_and_payload_are_recomputed(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site);
        $detector = new HealthDropDetector;

        Carbon::setTestNow('2026-08-01 08:00:00');

        $this->snapshot(92.0, 'A', now()->subDay());
        $this->snapshot(85.0, 'B', now());

        $detector->detectForMonitor($monitor);

        $firstInsight = Insight::withoutGlobalScopes()
            ->where('site', 'example.com')
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::WARNING, $firstInsight->severity);
        $this->assertSame('A', $firstInsight->payload['previous_grade']);
        $this->assertSame('B', $firstInsight->payload['current_grade']);
        $firstDetectedAt = $firstInsight->detected_at;

        Carbon::setTestNow('2026-08-22 08:00:00');

        $this->snapshot(64.0, 'D', now());

        $detector->detectForMonitor($monitor);

        $refreshed = Insight::withoutGlobalScopes()
            ->where('site', 'example.com')
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->get();

        $this->assertCount(1, $refreshed);

        $refreshedInsight = $refreshed->first();

        $this->assertTrue($refreshedInsight->detected_at->equalTo($firstDetectedAt));
        $this->assertEquals(InsightSeverity::CRITICAL, $refreshedInsight->severity);
        $this->assertSame('B', $refreshedInsight->payload['previous_grade']);
        $this->assertSame('D', $refreshedInsight->payload['current_grade']);
        $this->assertSame(64, $refreshedInsight->payload['current_score']);

        Carbon::setTestNow();
    }

    // -------------------------------------------------------------------------
    // Recovery: regression insight created, then a newer snapshot restores grade → removed
    // -------------------------------------------------------------------------

    public function test_recovery_removes_unacknowledged_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site);

        // Step 1: create a regression (B → D).
        $this->snapshot(82.0, 'B', now()->subDays(2));
        $this->snapshot(64.0, 'D', now()->subDay());

        $detector = new HealthDropDetector;
        $detector->detectForMonitor($monitor);

        $this->assertSame(1, Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')->count());

        // Step 2: add a more recent snapshot that restores grade B.
        // The detector now sees: current=B (today), previous=D (yesterday) → improvement → delete.
        $this->snapshot(85.0, 'B', now());

        $detector->detectForMonitor($monitor);

        $this->assertSame(0, Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')->count(),
            'Unacknowledged HEALTH_DROP insight must be removed after grade recovery.');
    }
}
