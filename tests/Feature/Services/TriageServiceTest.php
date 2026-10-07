<?php

namespace Tests\Feature\Services;

use App\Enums\InsightDomain;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\TriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests for TriageService (Tâche 0.2) and the Inertia triage prop (Tâche 0.4).
 */
class TriageServiceTest extends TestCase
{
    use RefreshDatabase;

    private TriageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TriageService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // counts() — badge rules
    // ─────────────────────────────────────────────────────────────────────────

    public function test_counts_excludes_info_severity(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::INFO->value,
        ]);

        $counts = $this->service->counts($team);

        $this->assertEquals(0, $counts['total']);
        $this->assertEquals(0, $counts['critical']);
    }

    public function test_counts_excludes_opportunity_severity(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::OPPORTUNITY->value,
        ]);

        $counts = $this->service->counts($team);

        $this->assertEquals(0, $counts['total']);
    }

    public function test_counts_excludes_acknowledged_insights(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->acknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $counts = $this->service->counts($team);

        $this->assertEquals(0, $counts['total']);
    }

    public function test_counts_excludes_snoozed_insights(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->snoozed(now()->addHour())->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
            'acknowledged_at' => null,
        ]);

        $counts = $this->service->counts($team);

        $this->assertEquals(0, $counts['total']);
    }

    public function test_counts_includes_warning_and_critical(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        $counts = $this->service->counts($team);

        $this->assertEquals(2, $counts['total']);
        $this->assertEquals(1, $counts['critical']);
    }

    public function test_counts_groups_by_domain_correctly(): void
    {
        $team = Team::factory()->create();

        // AVAILABILITY domain: UPTIME_INCIDENT
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::UPTIME_INCIDENT->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        // INFRASTRUCTURE domain: SERVER_HEALTH
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        // SEO_BUSINESS domain: STRIKING_DISTANCE
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $counts = $this->service->counts($team);

        $this->assertEquals(3, $counts['total']);
        $this->assertEquals(1, $counts['critical']);
        $this->assertEquals(1, $counts['domains'][InsightDomain::AVAILABILITY->value]);
        $this->assertEquals(1, $counts['domains'][InsightDomain::INFRASTRUCTURE->value]);
        $this->assertEquals(1, $counts['domains'][InsightDomain::SEO_BUSINESS->value]);
        $this->assertEquals(0, $counts['domains'][InsightDomain::ALERTING->value]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Team isolation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_counts_does_not_include_other_team_insights(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $teamB->id,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        $counts = $this->service->counts($teamA);

        $this->assertEquals(0, $counts['total']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // forDomain() filter
    // ─────────────────────────────────────────────────────────────────────────

    public function test_for_domain_filters_to_infrastructure_only(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::UPTIME_INCIDENT->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $results = $this->service
            ->forDomain($this->service->open($team), InsightDomain::INFRASTRUCTURE)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(InsightType::SERVER_HEALTH->value, $results->first()->type->value);
    }

    public function test_for_domain_filters_seo_business_correctly(): void
    {
        $team = Team::factory()->create();

        $seoCases = [
            InsightType::STRIKING_DISTANCE,
            InsightType::TRAFFIC_CHANGE,
            InsightType::POSITION_CHANGE,
        ];

        foreach ($seoCases as $type) {
            Insight::factory()->unacknowledged()->create([
                'team_id' => $team->id,
                'type' => $type->value,
                'severity' => InsightSeverity::WARNING->value,
            ]);
        }

        // Add an infrastructure insight that should be excluded.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $results = $this->service
            ->forDomain($this->service->open($team), InsightDomain::SEO_BUSINESS)
            ->get();

        $this->assertCount(3, $results);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // topItems() — limit + sort + eager load
    // ─────────────────────────────────────────────────────────────────────────

    public function test_top_items_limits_results(): void
    {
        $team = Team::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            Insight::factory()->unacknowledged()->create([
                'team_id' => $team->id,
                'severity' => InsightSeverity::WARNING->value,
                'impact_score' => $i * 10,
            ]);
        }

        $items = $this->service->topItems($team, 3);

        $this->assertCount(3, $items);
    }

    public function test_top_items_ordered_by_impact_score_descending(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 10,
        ]);
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::CRITICAL->value,
            'impact_score' => 90,
        ]);

        $items = $this->service->topItems($team, 2);

        $this->assertEquals(90, (float) $items->first()->impact_score);
        $this->assertEquals(10, (float) $items->last()->impact_score);
    }

    public function test_top_items_excludes_info_insights(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::INFO->value,
            'impact_score' => 99,
        ]);

        $items = $this->service->topItems($team, 3);

        $this->assertCount(0, $items);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Inertia triage prop (Tâche 0.4)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_inertia_triage_prop_present_for_authenticated_user(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('triage')
            ->has('triage.counts')
            ->has('triage.counts.total')
            ->has('triage.counts.critical')
            ->has('triage.counts.domains')
            ->has('triage.domains_meta')
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cache invalidation (Tâche 0.4)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cache_invalidated_after_acknowledge(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);

        $insight = Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        // Prime the cache.
        $before = $this->service->counts($team);
        $this->assertEquals(1, $before['total']);

        // Acknowledge via the model method (triggers model event).
        $insight->acknowledge();

        // Cache must be gone — re-calling counts() should reflect the change.
        $after = $this->service->counts($team);
        $this->assertEquals(0, $after['total']);
    }

    public function test_cache_invalidated_after_snooze(): void
    {
        $team = Team::factory()->create();

        $insight = Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        // Prime the cache.
        Cache::remember(
            TriageService::cacheKey($team->id),
            30,
            fn () => $this->service->counts($team),
        );

        // Snooze the insight — model event should bust the cache.
        $insight->snooze(now()->addHour());

        // The cache key must no longer exist.
        $this->assertFalse(
            Cache::has(TriageService::cacheKey($team->id)),
            'Cache should be invalidated after snooze'
        );
    }

    public function test_cache_invalidated_when_new_insight_created(): void
    {
        $team = Team::factory()->create();

        // Prime the cache with zero insights.
        Cache::remember(
            TriageService::cacheKey($team->id),
            30,
            fn () => $this->service->counts($team),
        );

        // Creating a new insight should bust the cache immediately.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        $this->assertFalse(
            Cache::has(TriageService::cacheKey($team->id)),
            'Cache should be invalidated after new insight creation'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // countsBySite()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_counts_by_site_groups_by_domain_per_monitor(): void
    {
        $team = Team::factory()->create();

        $monitorA = Monitor::factory()->create(['team_id' => $team->id]);
        $monitorB = Monitor::factory()->create(['team_id' => $team->id]);

        // Monitor A: 1 availability + 1 seo_business
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitorA->id,
            'type' => InsightType::UPTIME_INCIDENT->value,     // availability
            'severity' => InsightSeverity::WARNING->value,
        ]);
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitorA->id,
            'type' => InsightType::TRAFFIC_CHANGE->value,      // seo_business
            'severity' => InsightSeverity::WARNING->value,
        ]);

        // Monitor B: 1 infrastructure
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitorB->id,
            'type' => InsightType::SERVER_HEALTH->value,       // infrastructure
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        $map = $this->service->countsBySite($team);

        $this->assertArrayHasKey($monitorA->id, $map);
        $this->assertEquals(1, $map[$monitorA->id]['availability']);
        $this->assertEquals(1, $map[$monitorA->id]['seo_business']);
        $this->assertEquals(0, $map[$monitorA->id]['infrastructure']);

        $this->assertArrayHasKey($monitorB->id, $map);
        $this->assertEquals(0, $map[$monitorB->id]['availability']);
        $this->assertEquals(0, $map[$monitorB->id]['seo_business']);
        $this->assertEquals(1, $map[$monitorB->id]['infrastructure']);
    }

    public function test_counts_by_site_excludes_acknowledged_insights(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['team_id' => $team->id]);

        Insight::factory()->acknowledged()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::UPTIME_INCIDENT->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $map = $this->service->countsBySite($team);

        $this->assertArrayNotHasKey($monitor->id, $map);
    }

    public function test_counts_by_site_excludes_snoozed_insights(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['team_id' => $team->id]);

        Insight::factory()->snoozed(now()->addHour())->unacknowledged()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::UPTIME_INCIDENT->value,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        $map = $this->service->countsBySite($team);

        $this->assertArrayNotHasKey($monitor->id, $map);
    }

    public function test_counts_by_site_excludes_insights_without_monitor_id(): void
    {
        $team = Team::factory()->create();

        // Insight with no monitor_id (site-level insight, no linked monitor)
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'monitor_id' => null,
            'type' => InsightType::TRAFFIC_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $map = $this->service->countsBySite($team);

        $this->assertEmpty($map);
    }

    public function test_counts_by_site_does_not_include_other_team_insights(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $monitorB = Monitor::factory()->create(['team_id' => $teamB->id]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $teamB->id,
            'monitor_id' => $monitorB->id,
            'type' => InsightType::UPTIME_INCIDENT->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $map = $this->service->countsBySite($teamA);

        $this->assertArrayNotHasKey($monitorB->id, $map);
    }

    public function test_counts_by_site_returns_empty_when_no_open_insights(): void
    {
        $team = Team::factory()->create();

        $map = $this->service->countsBySite($team);

        $this->assertEmpty($map);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // topItems() — businessWeight tiebreaker
    // ─────────────────────────────────────────────────────────────────────────

    public function test_top_items_revenue_at_risk_surfaces_above_ctr_change_at_equal_impact(): void
    {
        $team = Team::factory()->create();

        // Both WARNING, same impact_score=50. REVENUE_AT_RISK (weight=10) must
        // sort above CTR_CHANGE (weight=1) when businessWeight tiebreaker is active.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::CTR_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 50,
        ]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::REVENUE_AT_RISK->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 50,
        ]);

        // limit=1 means only the top-ranked insight is returned.
        $items = $this->service->topItems($team, 1);

        $this->assertCount(1, $items);
        $this->assertEquals(
            InsightType::REVENUE_AT_RISK->value,
            $items->first()->type->value,
            'REVENUE_AT_RISK (businessWeight=10) must rank above CTR_CHANGE (businessWeight=1) at equal impact_score.'
        );
    }

    public function test_top_items_affiliate_leak_surfaces_above_striking_distance_at_equal_impact(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 40,
        ]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::AFFILIATE_LEAK->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 40,
        ]);

        $items = $this->service->topItems($team, 1);

        $this->assertCount(1, $items);
        $this->assertEquals(
            InsightType::AFFILIATE_LEAK->value,
            $items->first()->type->value,
            'AFFILIATE_LEAK (businessWeight=9) must rank above STRIKING_DISTANCE (businessWeight=0) at equal impact_score.'
        );
    }

    public function test_top_items_higher_impact_score_still_wins_over_lower_weight(): void
    {
        $team = Team::factory()->create();

        // CTR_CHANGE with impact=90 must beat REVENUE_AT_RISK with impact=50.
        // businessWeight must never override a genuine impact difference.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::CTR_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 90,
        ]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::REVENUE_AT_RISK->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 50,
        ]);

        $items = $this->service->topItems($team, 1);

        $this->assertCount(1, $items);
        $this->assertEquals(
            InsightType::CTR_CHANGE->value,
            $items->first()->type->value,
            'Higher impact_score (90) must beat lower weight (REVENUE_AT_RISK at 50).'
        );
    }
}
