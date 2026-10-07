<?php

namespace Tests\Feature\Services;

use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\KpiCollector;
use App\Services\WhatChangedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for WhatChangedService::forTeam().
 *
 * Approach: seed KpiSnapshot rows directly with controlled captured_at values
 * to simulate "this week" and "last week" windows. No HTTP mocking required —
 * the service only reads KpiSnapshot table.
 *
 * Site-to-monitor wiring: forTeam() derives the site list from team->monitors
 * using KpiCollector::siteNameFromUrl(). Each test therefore creates a Monitor
 * whose URL host matches the site used in KpiSnapshot rows.
 * Example: Monitor url='https://example.com' → site='example.com'.
 */
class WhatChangedServiceTest extends TestCase
{
    use RefreshDatabase;

    private WhatChangedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WhatChangedService::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Traffic decline detection
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_significant_clicks_decline(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $team->load('monitors');

        // This week: captured 2 days ago (within last 7 days).
        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 800,
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        // Last week: captured 10 days ago (within 14→7 days window).
        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 1200,
            'period_days' => 28,
            'captured_at' => now()->subDays(10),
        ]);

        $result = $this->service->forTeam($team);

        $clickChanges = array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === 'example.com' && $c['metric'] === 'clicks_28d'
        );

        $this->assertNotEmpty($clickChanges, 'Expected a clicks_28d change entry');

        $change = array_values($clickChanges)[0];
        $this->assertEquals('decline', $change['direction']);
        $this->assertLessThan(0, $change['delta_pct']);

        // Verify a TRAFFIC_CHANGE Insight was persisted.
        $this->assertDatabaseHas('insights', [
            'team_id' => $team->id,
            'site' => 'example.com',
            'type' => InsightType::TRAFFIC_CHANGE->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Decline noise filters
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function changesFor(string $site, string $metric, float $current, float $previous, KpiSource $source): array
    {
        $team = Team::factory()->create();
        Monitor::factory()->create(['url' => 'https://'.$site, 'team_id' => $team->id]);
        $team->load('monitors');

        foreach ([[$current, 2], [$previous, 10]] as [$value, $daysAgo]) {
            KpiSnapshot::create([
                'site' => $site,
                'source' => $source->value,
                'metric' => $metric,
                'value' => $value,
                'period_days' => 28,
                'captured_at' => now()->subDays($daysAgo),
            ]);
        }

        // Keep the broken-GA4 detector silent: it is unrelated to these cases.
        if ($source === KpiSource::GSC) {
            KpiSnapshot::create([
                'site' => $site,
                'source' => KpiSource::GA4->value,
                'metric' => 'users_28d',
                'value' => 100,
                'period_days' => 28,
                'captured_at' => now()->subDays(2),
            ]);
        }

        $result = $this->service->forTeam($team);

        return array_values(array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === $site && $c['metric'] === $metric
        ));
    }

    public function test_impressions_decline_alone_no_longer_triggers(): void
    {
        $changes = $this->changesFor('impr-down.example.com', 'impressions_28d', 400, 1000, KpiSource::GSC);

        $this->assertSame([], $changes);
        $this->assertDatabaseMissing('insights', ['site' => 'impr-down.example.com']);
    }

    public function test_impressions_increase_still_reported(): void
    {
        $changes = $this->changesFor('impr-up.example.com', 'impressions_28d', 1500, 1000, KpiSource::GSC);

        $this->assertCount(1, $changes);
        $this->assertSame('improvement', $changes[0]['direction']);
    }

    public function test_clicks_decline_below_thirty_percent_is_ignored(): void
    {
        $changes = $this->changesFor('mild-clicks.example.com', 'clicks_28d', 800, 1000, KpiSource::GSC);

        $this->assertSame([], $changes);
    }

    public function test_users_decline_below_thirty_percent_is_ignored_and_above_is_reported(): void
    {
        $this->assertSame([], $this->changesFor('mild-users.example.com', 'users_28d', 750, 1000, KpiSource::GA4));

        $changes = $this->changesFor('big-users.example.com', 'users_28d', 600, 1000, KpiSource::GA4);
        $this->assertCount(1, $changes);
        $this->assertSame('decline', $changes[0]['direction']);
    }

    public function test_clicks_increase_below_thirty_percent_still_reported(): void
    {
        $changes = $this->changesFor('mild-up.example.com', 'clicks_28d', 1200, 1000, KpiSource::GSC);

        $this->assertCount(1, $changes);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Significance threshold
    // ──────────────────────────────────────────────────────────────────────

    public function test_ignores_change_below_significance_threshold(): void
    {
        // 5% change is below the default 10% threshold → must not produce a change entry.
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $team->load('monitors');

        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 1050,  // +5% vs last week
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 1000,
            'period_days' => 28,
            'captured_at' => now()->subDays(10),
        ]);

        $result = $this->service->forTeam($team);

        $clickChanges = array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === 'example.com' && $c['metric'] === 'clicks_28d'
        );

        $this->assertEmpty($clickChanges);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Position inversion — lower is better
    // ──────────────────────────────────────────────────────────────────────

    public function test_position_improvement_direction_is_correct_when_rank_decreases(): void
    {
        // Position 8 this week vs position 10 last week → numerically lower (8) = better rank.
        // delta_pct will be negative (8 < 10), but direction must be 'improvement'.
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $team->load('monitors');

        KpiSnapshot::create(['site' => 'example.com', 'source' => KpiSource::GSC->value, 'metric' => 'impressions_28d', 'value' => 500, 'period_days' => 28, 'captured_at' => now()->subDays(2)]);

        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'position_28d',
            'value' => 5.0,   // this week: position 5 (better)
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'position_28d',
            'value' => 8.0,   // last week: position 8 (worse)
            'period_days' => 28,
            'captured_at' => now()->subDays(10),
        ]);

        $result = $this->service->forTeam($team);

        $posChanges = array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === 'example.com' && $c['metric'] === 'position_28d'
        );

        $this->assertNotEmpty($posChanges, 'Expected a position_28d change entry');

        $change = array_values($posChanges)[0];
        $this->assertEquals('improvement', $change['direction']);
        $this->assertLessThan(0, $change['delta_pct']); // negative delta = rank improved
    }

    public function test_position_decline_direction_is_correct_when_rank_increases(): void
    {
        // Position 15 this week vs 8 last week → numerically higher (15) = worse rank.
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $team->load('monitors');

        KpiSnapshot::create(['site' => 'example.com', 'source' => KpiSource::GSC->value, 'metric' => 'impressions_28d', 'value' => 500, 'period_days' => 28, 'captured_at' => now()->subDays(2)]);

        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'position_28d',
            'value' => 15.0,  // this week: position 15 (worse)
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'position_28d',
            'value' => 8.0,   // last week: position 8 (better)
            'period_days' => 28,
            'captured_at' => now()->subDays(10),
        ]);

        $result = $this->service->forTeam($team);

        $posChanges = array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === 'example.com' && $c['metric'] === 'position_28d'
        );

        $this->assertNotEmpty($posChanges);

        $change = array_values($posChanges)[0];
        $this->assertEquals('decline', $change['direction']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // GA4 broken tag detection
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_broken_ga4_tag_when_users_zero_and_gsc_has_clicks(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $team->load('monitors');

        // GA4 users = 0 this week.
        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GA4->value,
            'metric' => 'users_28d',
            'value' => 0,
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        // GSC clicks > 0 this week.
        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 500,
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        $result = $this->service->forTeam($team);

        $this->assertContains('example.com', $result['ga4_broken_sites']);

        // A WARNING Insight should have been created.
        $this->assertDatabaseHas('insights', [
            'team_id' => $team->id,
            'site' => 'example.com',
            'type' => InsightType::TRAFFIC_CHANGE->value,
        ]);
    }

    public function test_does_not_flag_ga4_broken_when_users_are_present(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $team->load('monitors');

        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GA4->value,
            'metric' => 'users_28d',
            'value' => 1500,
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 1000,
            'period_days' => 28,
            'captured_at' => now()->subDays(2),
        ]);

        $result = $this->service->forTeam($team);

        $this->assertNotContains('example.com', $result['ga4_broken_sites']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Core update detection
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_core_update_when_enough_sites_have_position_movement(): void
    {
        // Need >= 4 sites with position movement AND >= 50% of portfolio moving.
        // We create 4 monitors with different hosts — each site has a >10% position change.
        $team = Team::factory()->create();

        $hosts = ['alpha.example.com', 'beta.example.com', 'gamma.example.com', 'delta.example.com'];

        foreach ($hosts as $host) {
            Monitor::factory()->create([
                'url' => 'https://'.$host,
                'team_id' => $team->id,
            ]);

            $site = ltrim($host, 'www.');

            // meetsVolumeFloor() needs GSC impressions_28d >= 30 for position deltas.
            KpiSnapshot::create(['site' => $site, 'source' => KpiSource::GSC->value, 'metric' => 'impressions_28d', 'value' => 500, 'period_days' => 28, 'captured_at' => now()->subDays(2)]);

            // This week: position 15 (moved from 8 → 15, +87.5%).
            KpiSnapshot::create([
                'site' => $site,
                'source' => KpiSource::GSC->value,
                'metric' => 'position_28d',
                'value' => 15.0,
                'period_days' => 28,
                'captured_at' => now()->subDays(2),
            ]);

            // Last week: position 8.
            KpiSnapshot::create([
                'site' => $site,
                'source' => KpiSource::GSC->value,
                'metric' => 'position_28d',
                'value' => 8.0,
                'period_days' => 28,
                'captured_at' => now()->subDays(10),
            ]);
        }

        $team->load('monitors');
        $result = $this->service->forTeam($team);

        $this->assertTrue($result['core_update_suspected']);
    }

    public function test_does_not_suspect_core_update_with_too_few_sites(): void
    {
        // Only 2 sites — below the default threshold of 4.
        $team = Team::factory()->create();

        foreach (['siteone.example.com', 'sitetwo.example.com'] as $host) {
            Monitor::factory()->create([
                'url' => 'https://'.$host,
                'team_id' => $team->id,
            ]);

            $site = $host;

            KpiSnapshot::create([
                'site' => $site,
                'source' => KpiSource::GSC->value,
                'metric' => 'position_28d',
                'value' => 15.0,
                'period_days' => 28,
                'captured_at' => now()->subDays(2),
            ]);

            KpiSnapshot::create([
                'site' => $site,
                'source' => KpiSource::GSC->value,
                'metric' => 'position_28d',
                'value' => 8.0,
                'period_days' => 28,
                'captured_at' => now()->subDays(10),
            ]);
        }

        $team->load('monitors');
        $result = $this->service->forTeam($team);

        $this->assertFalse($result['core_update_suspected']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Return structure
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_required_keys(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $team->load('monitors');

        $result = $this->service->forTeam($team);

        $this->assertArrayHasKey('changes', $result);
        $this->assertArrayHasKey('core_update_suspected', $result);
        $this->assertArrayHasKey('ga4_broken_sites', $result);
        $this->assertIsBool($result['core_update_suspected']);
        $this->assertIsArray($result['ga4_broken_sites']);
    }
}
