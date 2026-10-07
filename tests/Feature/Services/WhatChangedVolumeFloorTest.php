<?php

namespace Tests\Feature\Services;

use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\WhatChangedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for WhatChangedService::meetsVolumeFloor() — Fix 1: volume floor noise filter.
 *
 * The floor prevents near-zero-traffic sites from generating spurious insights.
 * Default floors (from config monitoring.what_changed.min_volume):
 *   clicks_28d      => 10
 *   impressions_28d => 100
 *   users_28d       => 50
 *   position_28d    => 30  (proxied through GSC impressions_28d)
 *   ctr_28d         => 100 (proxied through GSC impressions_28d)
 *
 * Approach: same as WhatChangedServiceTest — seed KpiSnapshot rows with
 * controlled captured_at values (subDays(2) = this week, subDays(10) = last week).
 * Monitor URL host must match the site key used in KpiSnapshot rows.
 */
class WhatChangedVolumeFloorTest extends TestCase
{
    use RefreshDatabase;

    private WhatChangedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WhatChangedService::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function makeTeamWithMonitor(string $host): array
    {
        $team = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://'.$host,
            'team_id' => $team->id,
        ]);
        $team->load('monitors');

        return [$team];
    }

    private function seedWeekSnapshots(
        string $site,
        KpiSource $source,
        string $metric,
        float $currentValue,
        float $previousValue,
    ): void {
        // "This week" window: within the last 7 days.
        KpiSnapshot::create([
            'site' => $site,
            'source' => $source->value,
            'metric' => $metric,
            'value' => $currentValue,
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        // "Last week" window: between 14 and 7 days ago.
        KpiSnapshot::create([
            'site' => $site,
            'source' => $source->value,
            'metric' => $metric,
            'value' => $previousValue,
            'period_days' => 28,
            'captured_at' => now()->subDays(10),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fix 1a — clicks below floor → no insight (3→2, -33%, max=3 < floor 10)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 3→2 clicks = -33% change, above the 10% significance threshold.
     * But max(3, 2) = 3 which is BELOW the floor of 10.
     * No TRAFFIC_CHANGE insight must be created.
     *
     * NOTE: a GA4 users snapshot (value > 0) is seeded deliberately so that
     * detectBrokenGa4() stays silent. Without it, the broken-GA4 detector fires
     * (GSC clicks present, GA4 absent → TRAFFIC_CHANGE insight) and the
     * assertDatabaseMissing assertion finds that row instead of the suppressed
     * clicks delta. The volume floor logic is correct; the isolation needs the
     * GA4 fixture to avoid a false-positive from an unrelated detector.
     */
    public function test_clicks_below_volume_floor_suppressed_even_if_large_delta(): void
    {
        [$team] = $this->makeTeamWithMonitor('low-clicks.example.com');

        $this->seedWeekSnapshots('low-clicks.example.com', KpiSource::GSC, 'clicks_28d', 2.0, 3.0);

        // Seed GA4 users so detectBrokenGa4() does not fire for this site.
        KpiSnapshot::create([
            'site' => 'low-clicks.example.com',
            'source' => KpiSource::GA4->value,
            'metric' => 'users_28d',
            'value' => 5.0,
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        $result = $this->service->forTeam($team);

        $clickChanges = array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === 'low-clicks.example.com' && $c['metric'] === 'clicks_28d'
        );

        $this->assertEmpty($clickChanges, 'A 3→2 click move should be suppressed by the volume floor.');

        $this->assertDatabaseMissing('insights', [
            'team_id' => $team->id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fix 1b — clicks above floor, large decline → insight created
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 200→120 clicks = -40% change. max(200, 120) = 200 >= floor of 10.
     * A TRAFFIC_CHANGE insight must be created.
     */
    public function test_clicks_above_volume_floor_with_significant_decline_creates_insight(): void
    {
        [$team] = $this->makeTeamWithMonitor('high-clicks.example.com');

        $this->seedWeekSnapshots('high-clicks.example.com', KpiSource::GSC, 'clicks_28d', 120.0, 200.0);

        $result = $this->service->forTeam($team);

        $clickChanges = array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === 'high-clicks.example.com' && $c['metric'] === 'clicks_28d'
        );

        $this->assertNotEmpty($clickChanges, 'A 200→120 click decline should generate a change entry.');

        $change = array_values($clickChanges)[0];
        $this->assertEquals('decline', $change['direction']);
        $this->assertLessThan(-10, $change['delta_pct']); // must exceed significance threshold

        $this->assertDatabaseHas('insights', [
            'team_id' => $team->id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fix 1c — recovery case: max(current, previous) must use the larger value
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 2→15 clicks = +650% recovery. current = 15, previous = 2.
     * max(15, 2) = 15 >= floor of 10 → passes.
     *
     * If the implementation checked only previous (= 2), the floor would fail and
     * the improvement would be silently discarded. This test verifies the max() semantics
     * described in the meetsVolumeFloor() docblock.
     */
    public function test_recovery_from_below_to_above_floor_is_not_suppressed(): void
    {
        [$team] = $this->makeTeamWithMonitor('recovering.example.com');

        // previous = 2 (below floor of 10), current = 15 (above floor).
        $this->seedWeekSnapshots('recovering.example.com', KpiSource::GSC, 'clicks_28d', 15.0, 2.0);

        $result = $this->service->forTeam($team);

        $clickChanges = array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === 'recovering.example.com' && $c['metric'] === 'clicks_28d'
        );

        $this->assertNotEmpty(
            $clickChanges,
            'A 2→15 recovery must not be suppressed: max(current,previous) = 15 >= floor 10.'
        );

        $change = array_values($clickChanges)[0];
        $this->assertEquals('improvement', $change['direction']);
        $this->assertGreaterThan(0, $change['delta_pct']);

        // Improvement → INFO severity (not WARNING). DB has the row regardless of severity.
        $this->assertDatabaseHas('insights', [
            'team_id' => $team->id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fix 1d — position floor proxied through GSC impressions (1 impression)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Site has a significant position move (5 → 9 = +80%) but only 1 GSC impression
     * in the last 7 days (the webcompare.de scenario: 1 impression per week). The
     * position floor (default 30) is checked against GSC impressions_28d; 1 < 30, so
     * no POSITION_CHANGE insight must be created.
     */
    public function test_position_suppressed_when_gsc_impressions_below_floor(): void
    {
        [$team] = $this->makeTeamWithMonitor('low-impr.example.com');

        // Very low GSC impressions (below the proxy floor of 30).
        KpiSnapshot::create([
            'site' => 'low-impr.example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'impressions_28d',
            'value' => 1.0,
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        // Significant position move: position 5 → 9 (+80% > 10% significance threshold).
        $this->seedWeekSnapshots('low-impr.example.com', KpiSource::GSC, 'position_28d', 9.0, 5.0);

        $result = $this->service->forTeam($team);

        $posChanges = array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === 'low-impr.example.com' && $c['metric'] === 'position_28d'
        );

        $this->assertEmpty($posChanges, 'Position change on a site with 1 GSC impression must be suppressed.');

        $this->assertDatabaseMissing('insights', [
            'team_id' => $team->id,
            'type' => InsightType::POSITION_CHANGE->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fix 1e — position insight created when impressions meet the floor
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Same position move (5 → 9 = +80%) but this time the site has 500 GSC
     * impressions in the last 7 days (>= floor 30). A POSITION_CHANGE insight
     * must be created.
     */
    public function test_position_insight_created_when_impressions_meet_floor(): void
    {
        [$team] = $this->makeTeamWithMonitor('good-impr.example.com');

        // Sufficient GSC impressions this week (above the proxy floor of 30).
        KpiSnapshot::create([
            'site' => 'good-impr.example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'impressions_28d',
            'value' => 500.0,
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        // Position decline: position 5 → 9 (+80%; higher rank number = worse).
        $this->seedWeekSnapshots('good-impr.example.com', KpiSource::GSC, 'position_28d', 9.0, 5.0);

        $result = $this->service->forTeam($team);

        $posChanges = array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === 'good-impr.example.com' && $c['metric'] === 'position_28d'
        );

        $this->assertNotEmpty($posChanges, 'Position change on a site with 500 impressions must generate an entry.');

        $change = array_values($posChanges)[0];
        $this->assertEquals('decline', $change['direction']); // higher rank number = worse

        $this->assertDatabaseHas('insights', [
            'team_id' => $team->id,
            'type' => InsightType::POSITION_CHANGE->value,
        ]);
    }
}
