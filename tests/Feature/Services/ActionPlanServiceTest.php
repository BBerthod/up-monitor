<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Team;
use App\Services\ActionPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActionPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ActionPlanService::class);
    }

    // -------------------------------------------------------------------------
    // AFFILIATE_LEAK is now actionable
    // -------------------------------------------------------------------------

    public function test_affiliate_leak_appears_in_action_plan(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::AFFILIATE_LEAK->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 50,
            'site' => 'example.com',
            'payload' => ['affected_links' => 12],
        ]);

        $result = $this->service->forTeam($team);

        $this->assertCount(1, $result['actions']);
        $this->assertSame(InsightType::AFFILIATE_LEAK->value, $result['actions'][0]['type']);
    }

    public function test_affiliate_leak_has_effort_one(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::AFFILIATE_LEAK->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 50,
            'site' => 'example.com',
        ]);

        $result = $this->service->forTeam($team);

        $this->assertSame(1, $result['actions'][0]['effort']);
    }

    public function test_affiliate_leak_action_text_contains_site(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::AFFILIATE_LEAK->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 30,
            'site' => 'mysite.com',
            'payload' => ['affected_links' => 5],
        ]);

        $result = $this->service->forTeam($team);

        $this->assertStringContainsString('mysite.com', $result['actions'][0]['action']);
    }

    public function test_striking_distance_action_includes_query_and_rounded_position(): void
    {
        $action = $this->strikingDistanceAction([
            'query' => 'best widgets',
            'position' => 11.44,
        ]);

        $this->assertSame(
            'Optimize the title/meta of alpha.example.com for "best widgets" (currently position 11.4)',
            $action,
        );
    }

    public function test_striking_distance_action_omits_missing_position(): void
    {
        $action = $this->strikingDistanceAction([
            'query' => 'best widgets',
            'position' => null,
        ]);

        $this->assertSame(
            'Optimize the title/meta of alpha.example.com for "best widgets"',
            $action,
        );
    }

    public function test_striking_distance_action_uses_generic_copy_without_query(): void
    {
        $action = $this->strikingDistanceAction([
            'query' => '   ',
            'position' => 12,
        ]);

        $this->assertSame(
            'Optimize the title/meta of alpha.example.com for its near-page-one queries',
            $action,
        );
    }

    // -------------------------------------------------------------------------
    // REVENUE_AT_RISK sorts above AFFILIATE_LEAK at equal impact (higher ROI)
    // -------------------------------------------------------------------------

    public function test_revenue_at_risk_higher_roi_than_ctr_change_at_equal_impact(): void
    {
        $team = Team::factory()->create();

        // normaliseImpact() for CTR_CHANGE uses abs(delta_pct ?? impact_score) * 2.
        // With delta_pct=10 that yields 20 normalised. effort=2 → ROI = 10.
        // REVENUE_AT_RISK with impact_score=80: normalised=80, effort=1 → ROI=80.
        // REVENUE_AT_RISK must rank first.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::CTR_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 50,
            'site' => 'site-ctr.com',
            'payload' => ['delta_pct' => 10],
        ]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::REVENUE_AT_RISK->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 80,
            'site' => 'site-revenue.com',
        ]);

        $result = $this->service->forTeam($team);

        $this->assertSame(InsightType::REVENUE_AT_RISK->value, $result['actions'][0]['type']);
    }

    // -------------------------------------------------------------------------
    // forTeam() returns expected shape
    // -------------------------------------------------------------------------

    public function test_returns_expected_shape(): void
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::REVENUE_AT_RISK->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'impact_score' => 80,
            'site' => 'shop.com',
        ]);

        $result = $this->service->forTeam($team, 5);

        $this->assertArrayHasKey('actions', $result);
        $this->assertArrayHasKey('total_actionable', $result);
        $this->assertArrayHasKey('generated_at', $result);

        $action = $result['actions'][0];
        foreach (['insight_id', 'site', 'type', 'severity', 'title', 'action', 'impact', 'effort', 'priority_score'] as $key) {
            $this->assertArrayHasKey($key, $action, "Action plan item is missing key: {$key}");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function strikingDistanceAction(array $payload): string
    {
        $team = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 25,
            'site' => 'alpha.example.com',
            'payload' => $payload,
        ]);

        return $this->service->forTeam($team)['actions'][0]['action'];
    }
}
