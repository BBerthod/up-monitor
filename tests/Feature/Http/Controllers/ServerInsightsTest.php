<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verify that ServerController@show includes open insights scoped to the server.
 */
class ServerInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Auth guard
    // ──────────────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_from_server_show(): void
    {
        $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->get(route('servers.show', $server))->assertRedirect(route('login'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // insights prop — happy path
    // ──────────────────────────────────────────────────────────────────────

    public function test_server_show_includes_insights_prop(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Servers/Show')
            ->has('insights')
        );
    }

    public function test_insights_are_scoped_to_the_server(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);
        $otherServer = Server::factory()->create(['team_id' => $user->team_id]);

        // Insight for THIS server.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'server_id' => $server->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'CPU high on prod',
            'impact_score' => 70,
        ]);

        // Insight for ANOTHER server — must not appear.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'server_id' => $otherServer->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'Other server',
            'impact_score' => 50,
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('insights', 1)
            ->where('insights.0.title', 'CPU high on prod')
        );
    }

    public function test_acknowledged_insights_are_excluded(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        Insight::factory()->acknowledged()->create([
            'team_id' => $user->team_id,
            'server_id' => $server->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('insights', []));
    }

    public function test_insights_capped_at_five(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        Insight::factory()->unacknowledged()->count(8)->create([
            'team_id' => $user->team_id,
            'server_id' => $server->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('insights', 5));
    }

    public function test_insights_from_another_team_server_are_excluded(): void
    {
        $userA = $this->createUserWithTeam();
        $teamB = Team::factory()->create();
        $serverB = Server::factory()->create(['team_id' => $teamB->id]);

        // Insight on team B's server — must NEVER appear for user A.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $teamB->id,
            'server_id' => $serverB->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        // User A's own server with no insights.
        $serverA = Server::factory()->create(['team_id' => $userA->team_id]);

        $response = $this->actingAs($userA)->get(route('servers.show', $serverA));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('insights', []));
    }

    public function test_insights_presenter_shape_is_correct(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'server_id' => $server->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'title' => 'Disk 92 %',
            'impact_score' => 90,
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('insights', 1)
            ->has('insights.0.id')
            ->has('insights.0.severity')
            ->has('insights.0.type')
            ->has('insights.0.title')
            ->has('insights.0.impact_score')
            ->has('insights.0.fix_url')
            ->has('insights.0.acknowledge_url')
        );
    }
}
