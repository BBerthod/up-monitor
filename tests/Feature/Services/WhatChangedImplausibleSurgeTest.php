<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\WhatChangedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for WhatChangedService::isImplausibleSurge() — GA4 users surge not
 * corroborated by GSC clicks is re-filed as WARNING instead of INFO.
 *
 * Config (monitoring.what_changed.implausible_surge):
 *   surge_pct          => 100  (users_28d delta must reach this to be considered)
 *   corroboration_pct  => 10   (clicks delta below this = not corroborated)
 *
 * Rules under test (isImplausibleSurge):
 *   - only applies to metric === 'users_28d'
 *   - only applies when deltaPct >= surge_pct
 *   - disabled entirely when surge_pct <= 0
 *   - returns false when GSC clicks are absent or clicksPrevious <= 0 (no data, no judgment)
 *   - otherwise true when clicksDeltaPct < corroboration_pct
 *
 * Approach: same as WhatChangedVolumeFloorTest — seed KpiSnapshot rows with
 * controlled captured_at values (subDays(2) = this week, subDays(10) = last week).
 * Monitor URL host must match the site key used in KpiSnapshot rows.
 *
 * VOLUME FLOOR REMINDER: users_28d requires max(current, previous) >= 50
 * (monitoring.what_changed.min_volume.users_28d) before any insight is even
 * considered. All fixtures below clear that floor.
 */
class WhatChangedImplausibleSurgeTest extends TestCase
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

    private function makeTeamWithMonitor(string $host): Team
    {
        $team = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://'.$host,
            'team_id' => $team->id,
        ]);
        $team->load('monitors');

        return $team;
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

    private function findChange(array $result, string $site, string $metric): ?array
    {
        $matches = array_values(array_filter(
            $result['changes'],
            fn ($c) => $c['site'] === $site && $c['metric'] === $metric
        ));

        return $matches[0] ?? null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Case 1 — production scenario: implausible surge → WARNING + suffix + payload flag
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Users +359% (1669 → 7662) while GSC clicks fall -14% (5 → 4.3). This mirrors
     * the webcompare.com production case: a large GA4 improvement that search clicks
     * do not corroborate. The insight must be requalified as WARNING with the bot
     * suffix and payload.implausible_surge = true.
     */
    public function test_uncorroborated_users_surge_is_filed_as_warning(): void
    {
        $team = $this->makeTeamWithMonitor('surge.example.com');

        $this->seedWeekSnapshots('surge.example.com', KpiSource::GA4, 'users_28d', 7662.0, 1669.0);
        $this->seedWeekSnapshots('surge.example.com', KpiSource::GSC, 'clicks_28d', 4.3, 5.0);

        $result = $this->service->forTeam($team);

        $change = $this->findChange($result, 'surge.example.com', 'users_28d');
        $this->assertNotNull($change, 'A +359% users move must generate a change entry.');
        $this->assertEquals('improvement', $change['direction']);

        $insight = \App\Models\Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('site', 'surge.example.com')
            ->where('type', InsightType::TRAFFIC_CHANGE->value)
            ->whereJsonContains('payload->metric', 'users_28d')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::WARNING->value, $insight->severity->value);
        $this->assertStringContainsString('not corroborated by search clicks', $insight->title);
        $this->assertTrue($insight->payload['implausible_surge']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Case 2 — healthy growth stays INFO
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Users +16% with clicks +15%: both under surge_pct (100) AND corroborated.
     * Stays INFO for a double reason — the guard must not fire either way.
     */
    public function test_healthy_growth_under_surge_threshold_stays_info(): void
    {
        $team = $this->makeTeamWithMonitor('healthy-small.example.com');

        // 500 -> 580 = +16%
        $this->seedWeekSnapshots('healthy-small.example.com', KpiSource::GA4, 'users_28d', 580.0, 500.0);
        // 100 -> 115 = +15%
        $this->seedWeekSnapshots('healthy-small.example.com', KpiSource::GSC, 'clicks_28d', 115.0, 100.0);

        $result = $this->service->forTeam($team);

        $insight = \App\Models\Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('site', 'healthy-small.example.com')
            ->where('type', InsightType::TRAFFIC_CHANGE->value)
            ->whereJsonContains('payload->metric', 'users_28d')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::INFO->value, $insight->severity->value);
        $this->assertStringNotContainsString('not corroborated', $insight->title);
        $this->assertFalse($insight->payload['implausible_surge']);
    }

    /**
     * Users +150% (past surge_pct) but clicks +50% (well above corroboration_pct).
     * Proves the corroboration check itself saves the insight, not merely being
     * under the surge threshold like case 2 above.
     */
    public function test_large_surge_corroborated_by_clicks_stays_info(): void
    {
        $team = $this->makeTeamWithMonitor('healthy-big.example.com');

        // 1000 -> 2500 = +150%
        $this->seedWeekSnapshots('healthy-big.example.com', KpiSource::GA4, 'users_28d', 2500.0, 1000.0);
        // 200 -> 300 = +50%
        $this->seedWeekSnapshots('healthy-big.example.com', KpiSource::GSC, 'clicks_28d', 300.0, 200.0);

        $result = $this->service->forTeam($team);

        $insight = \App\Models\Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('site', 'healthy-big.example.com')
            ->where('type', InsightType::TRAFFIC_CHANGE->value)
            ->whereJsonContains('payload->metric', 'users_28d')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::INFO->value, $insight->severity->value);
        $this->assertStringNotContainsString('not corroborated', $insight->title);
        $this->assertFalse($insight->payload['implausible_surge']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Case 3 — below surge_pct threshold: never requalified, even with a clicks drop
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Users +28% (below surge_pct of 100) with clicks -45%. The guard must not
     * fire because the surge itself never reaches the threshold — regardless of
     * how uncorroborated it is.
     */
    public function test_surge_below_threshold_is_never_requalified(): void
    {
        $team = $this->makeTeamWithMonitor('below-threshold.example.com');

        // 1000 -> 1280 = +28%
        $this->seedWeekSnapshots('below-threshold.example.com', KpiSource::GA4, 'users_28d', 1280.0, 1000.0);
        // 200 -> 110 = -45%
        $this->seedWeekSnapshots('below-threshold.example.com', KpiSource::GSC, 'clicks_28d', 110.0, 200.0);

        $result = $this->service->forTeam($team);

        $insight = \App\Models\Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('site', 'below-threshold.example.com')
            ->where('type', InsightType::TRAFFIC_CHANGE->value)
            ->whereJsonContains('payload->metric', 'users_28d')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::INFO->value, $insight->severity->value);
        $this->assertStringNotContainsString('not corroborated', $insight->title);
        $this->assertFalse($insight->payload['implausible_surge']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Case 4 — no GSC clicks data at all: cannot judge, stays INFO
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Users +300% with NO GSC clicks snapshots whatsoever. isImplausibleSurge()
     * must return false because there is nothing to corroborate against — silence,
     * not accusation.
     */
    public function test_surge_without_gsc_clicks_data_stays_info(): void
    {
        $team = $this->makeTeamWithMonitor('no-gsc-data.example.com');

        // 1000 -> 4000 = +300%, no clicks_28d snapshots seeded at all.
        $this->seedWeekSnapshots('no-gsc-data.example.com', KpiSource::GA4, 'users_28d', 4000.0, 1000.0);

        $result = $this->service->forTeam($team);

        $insight = \App\Models\Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('site', 'no-gsc-data.example.com')
            ->where('type', InsightType::TRAFFIC_CHANGE->value)
            ->whereJsonContains('payload->metric', 'users_28d')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::INFO->value, $insight->severity->value);
        $this->assertStringNotContainsString('not corroborated', $insight->title);
        $this->assertFalse($insight->payload['implausible_surge']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Case 5 — guard is scoped to users_28d only, never other metrics
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * A large, uncorroborated-looking clicks_28d surge must never be requalified:
     * the guard only inspects metric === 'users_28d'. Clicks itself is a GSC
     * metric and has no "corroboration" concept in this guard.
     */
    public function test_clicks_surge_is_never_requalified_by_implausible_surge_guard(): void
    {
        $team = $this->makeTeamWithMonitor('clicks-surge.example.com');

        // 50 -> 300 = +500% clicks surge, well past surge_pct.
        $this->seedWeekSnapshots('clicks-surge.example.com', KpiSource::GSC, 'clicks_28d', 300.0, 50.0);

        $result = $this->service->forTeam($team);

        $insight = \App\Models\Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('site', 'clicks-surge.example.com')
            ->where('type', InsightType::TRAFFIC_CHANGE->value)
            ->whereJsonContains('payload->metric', 'clicks_28d')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::INFO->value, $insight->severity->value);
        $this->assertStringNotContainsString('not corroborated', $insight->title);
        $this->assertFalse($insight->payload['implausible_surge']);
    }

    /**
     * Same idea for impressions_28d: a huge surge must stay INFO, untouched by
     * the users-only guard.
     */
    public function test_impressions_surge_is_never_requalified_by_implausible_surge_guard(): void
    {
        $team = $this->makeTeamWithMonitor('impressions-surge.example.com');

        // 500 -> 3000 = +500% impressions surge, well past surge_pct.
        $this->seedWeekSnapshots('impressions-surge.example.com', KpiSource::GSC, 'impressions_28d', 3000.0, 500.0);

        $result = $this->service->forTeam($team);

        $insight = \App\Models\Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('site', 'impressions-surge.example.com')
            ->where('type', InsightType::TRAFFIC_CHANGE->value)
            ->whereJsonContains('payload->metric', 'impressions_28d')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::INFO->value, $insight->severity->value);
        $this->assertStringNotContainsString('not corroborated', $insight->title);
        $this->assertFalse($insight->payload['implausible_surge']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Case 6 — surge_pct <= 0 disables the guard entirely
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Same fixture as Case 1 (the production scenario) but with surge_pct forced
     * to 0. isImplausibleSurge() must short-circuit to false and the insight must
     * stay INFO, even though the underlying data is identical to the case that
     * triggers WARNING.
     */
    public function test_surge_pct_zero_disables_the_guard(): void
    {
        config(['monitoring.what_changed.implausible_surge.surge_pct' => 0]);

        $team = $this->makeTeamWithMonitor('disabled-guard.example.com');

        $this->seedWeekSnapshots('disabled-guard.example.com', KpiSource::GA4, 'users_28d', 7662.0, 1669.0);
        $this->seedWeekSnapshots('disabled-guard.example.com', KpiSource::GSC, 'clicks_28d', 4.3, 5.0);

        $result = $this->service->forTeam($team);

        $insight = \App\Models\Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('site', 'disabled-guard.example.com')
            ->where('type', InsightType::TRAFFIC_CHANGE->value)
            ->whereJsonContains('payload->metric', 'users_28d')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::INFO->value, $insight->severity->value);
        $this->assertStringNotContainsString('not corroborated', $insight->title);
        $this->assertFalse($insight->payload['implausible_surge']);
    }
}
