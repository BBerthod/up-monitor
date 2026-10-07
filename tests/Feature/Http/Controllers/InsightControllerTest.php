<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Insight;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    public function test_user_can_acknowledge_own_team_insight(): void
    {
        $user = $this->createUserWithTeam();
        $insight = Insight::factory()
            ->unacknowledged()
            ->create(['team_id' => $user->team_id]);

        $response = $this->actingAs($user)
            ->post(route('insights.acknowledge', $insight));

        $response->assertRedirect();

        $this->assertNotNull(
            Insight::withoutGlobalScopes()->find($insight->id)->acknowledged_at
        );
    }

    public function test_acknowledge_requires_auth(): void
    {
        $insight = Insight::factory()->unacknowledged()->create();

        $response = $this->post(route('insights.acknowledge', $insight));

        $response->assertRedirect(route('login'));
    }

    public function test_user_cannot_acknowledge_other_team_insight(): void
    {
        $userA = $this->createUserWithTeam();

        $teamB = Team::factory()->create();
        $insightB = Insight::factory()
            ->unacknowledged()
            ->create(['team_id' => $teamB->id]);

        // ScopedByTeam filters by userA's team_id → route-model binding returns 404
        $response = $this->actingAs($userA)
            ->post(route('insights.acknowledge', $insightB->id));

        $response->assertStatus(404);

        $this->assertNull(
            Insight::withoutGlobalScopes()->find($insightB->id)->acknowledged_at
        );
    }

    public function test_already_acknowledged_insight_stays_acknowledged(): void
    {
        $user = $this->createUserWithTeam();
        $insight = Insight::factory()
            ->acknowledged()
            ->create(['team_id' => $user->team_id]);

        $response = $this->actingAs($user)
            ->post(route('insights.acknowledge', $insight));

        $response->assertRedirect();

        $this->assertNotNull(
            Insight::withoutGlobalScopes()->find($insight->id)->acknowledged_at
        );
    }
}
