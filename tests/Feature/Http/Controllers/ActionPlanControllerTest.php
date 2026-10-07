<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('action-plan.index'))->assertRedirect(route('login'));
    }

    public function test_action_plan_returns_inertia_component(): void
    {
        $user = $this->userWithTeam();

        $this->actingAs($user)
            ->get(route('action-plan.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('ActionPlan')
                ->has('actions')
                ->has('counts')
            );
    }

    public function test_action_plan_payload_matches_action_plan_service_shape(): void
    {
        $user = $this->userWithTeam();

        Insight::factory()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::WARNING->value,
            'acknowledged_at' => null,
            'snoozed_until' => null,
            'impact_score' => 50,
        ]);

        $this->actingAs($user)
            ->get(route('action-plan.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('actions.actions')
                ->has('actions.total_actionable')
                ->has('actions.generated_at')
                ->where('actions.total_actionable', 1)
            );
    }

    public function test_action_plan_is_team_scoped(): void
    {
        $userA = $this->userWithTeam();
        $teamB = Team::factory()->create();

        // Insight belongs to team B — must NOT appear for user A.
        Insight::factory()->create([
            'team_id' => $teamB->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'acknowledged_at' => null,
        ]);

        $this->actingAs($userA)
            ->get(route('action-plan.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('actions.total_actionable', 0));
    }

    public function test_user_without_team_receives_empty_state(): void
    {
        $user = User::factory()->create(['team_id' => null]);

        $this->actingAs($user)
            ->get(route('action-plan.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('ActionPlan')
                ->where('actions', [])
                ->where('counts.total', 0)
            );
    }
}
