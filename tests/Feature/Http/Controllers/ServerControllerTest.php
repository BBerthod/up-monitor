<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for ServerController.
 *
 * Auth guard:
 *   - Guests are redirected to login.
 *   - Users with team_id === null are rejected with 403 (viewAny policy).
 *   - Users with a valid team see their own servers only (ScopedByTeam).
 */
class ServerControllerTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Auth guard
    // ──────────────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('servers.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_without_team_receives_403(): void
    {
        // team_id = null → policy viewAny returns false.
        $user = User::factory()->create(['team_id' => null]);

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Index — happy path
    // ──────────────────────────────────────────────────────────────────────

    public function test_authenticated_user_sees_servers_index_page(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Servers/Index'));
    }

    public function test_servers_prop_contains_latest_sparkline_and_sites(): void
    {
        $user = $this->createUserWithTeam();

        $server = Server::factory()->create([
            'team_id' => $user->team_id,
            'name' => 'prod-server',
            'is_active' => true,
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'tok',
        ]);

        // Attach a site.
        Site::factory()->create([
            'team_id' => $user->team_id,
            'server_id' => $server->id,
            'primary_domain' => 'app.example.com',
        ]);

        // Create one ServerMetric so latest + sparkline are populated.
        // Use a fractional value so JSON serialization keeps the decimal
        // (42.0 would serialize to 42 and break a strict float assertion).
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 42.5,
            'ram_percent' => 55.0,
            'disk_percent' => 30.0,
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($server) {
            $page->component('Servers/Index')
                ->has('servers', 1)
                ->where('servers.0.id', $server->id)
                ->where('servers.0.name', 'prod-server')
                ->has('servers.0.latest')
                ->where('servers.0.latest.cpu', 42.5)
                ->has('servers.0.sparkline')
                ->has('servers.0.sites')
                ->where('servers.0.sites.0', 'app.example.com');
        });
    }

    public function test_server_with_no_metrics_has_null_latest_and_empty_sparkline(): void
    {
        $user = $this->createUserWithTeam();

        Server::factory()->create([
            'team_id' => $user->team_id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('servers', 1)
            ->where('servers.0.latest', null)
            ->where('servers.0.sparkline', [])
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Team scoping
    // ──────────────────────────────────────────────────────────────────────

    public function test_server_from_another_team_does_not_appear_in_props(): void
    {
        $userA = $this->createUserWithTeam();

        $teamB = Team::factory()->create();
        Server::factory()->create(['team_id' => $teamB->id, 'name' => 'team-b-server']);

        // Also create one server for teamA so the response has data.
        Server::factory()->create(['team_id' => $userA->team_id, 'name' => 'team-a-server']);

        $response = $this->actingAs($userA)->get(route('servers.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            // Only team-a-server must appear.
            $page->has('servers', 1)
                ->where('servers.0.name', 'team-a-server');
        });
    }

    public function test_user_sees_only_their_own_team_servers_when_multiple_teams_exist(): void
    {
        $userA = $this->createUserWithTeam();

        // Three servers for teamA.
        Server::factory()->count(3)->create(['team_id' => $userA->team_id]);

        // Two servers for an unrelated team.
        $teamB = Team::factory()->create();
        Server::factory()->count(2)->create(['team_id' => $teamB->id]);

        $response = $this->actingAs($userA)->get(route('servers.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('servers', 3));
    }
}
