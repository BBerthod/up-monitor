<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for ServerController — CRUD (store, update, destroy, show, edit).
 *
 * Auth model:
 *   - Guests are redirected to login.
 *   - Authenticated users with a team can manage their own servers.
 *   - Cross-team access returns 404 (ScopedByTeam global scope resolves the model
 *     using the authenticated user's team_id, so a foreign server is simply not found).
 *
 * Note: rotateToken is tested separately in ServerRotateTokenTest.php.
 */
class ServerCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Bypass CSRF so POST/PUT/DELETE forms work without a session token.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    /**
     * Minimum valid payload for StoreServerRequest / UpdateServerRequest.
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'prod-server',
            'is_active' => true,
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Guest guard
    // ──────────────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_when_accessing_create(): void
    {
        $response = $this->get(route('servers.create'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_when_posting_store(): void
    {
        $response = $this->post(route('servers.store'), $this->validPayload());

        $response->assertRedirect(route('login'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Create page
    // ──────────────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_access_create_page(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)->get(route('servers.create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Servers/Create')
            ->has('defaultThresholds'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Store — happy path
    // ──────────────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_store_server(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)
            ->post(route('servers.store'), $this->validPayload([
                'name' => 'web-01',
                'is_active' => true,
            ]));

        $response->assertRedirect(route('servers.index'));

        $this->assertDatabaseHas('servers', [
            'name' => 'web-01',
            'team_id' => $user->team_id,
        ]);
    }

    public function test_stored_server_belongs_to_authenticated_users_team(): void
    {
        $user = $this->createUserWithTeam();

        $this->actingAs($user)
            ->post(route('servers.store'), $this->validPayload(['name' => 'team-server']));

        $server = Server::withoutGlobalScopes()->where('name', 'team-server')->first();

        $this->assertNotNull($server);
        $this->assertEquals($user->team_id, $server->team_id);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Store — validation
    // ──────────────────────────────────────────────────────────────────────

    public function test_store_fails_when_name_is_missing(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)
            ->post(route('servers.store'), ['is_active' => true]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('servers', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Store — settings.thresholds persisted as JSON
    // ──────────────────────────────────────────────────────────────────────

    public function test_store_persists_settings_thresholds_as_json(): void
    {
        $user = $this->createUserWithTeam();

        $this->actingAs($user)
            ->post(route('servers.store'), $this->validPayload([
                'name' => 'threshold-server',
                'settings' => [
                    'thresholds' => [
                        'disk_warning' => 90,
                        'disk_critical' => 95,
                    ],
                ],
            ]));

        $server = Server::withoutGlobalScopes()->where('name', 'threshold-server')->first();

        $this->assertNotNull($server);
        $this->assertEquals(90, $server->settings['thresholds']['disk_warning']);
        $this->assertEquals(95, $server->settings['thresholds']['disk_critical']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Show
    // ──────────────────────────────────────────────────────────────────────

    public function test_show_returns_inertia_page_with_required_props(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Servers/Show')
            ->has('server')
            ->has('latest')
            ->has('history')
            ->has('sites'));
    }

    public function test_show_history_contains_points_from_recent_metrics(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        // Two metrics in the last 30 days — both should appear in history.
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 60.0,
            'ram_percent' => 50.0,
            'disk_percent' => 40.0,
            'captured_at' => now()->subDays(1),
        ]);
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 70.0,
            'ram_percent' => 55.0,
            'disk_percent' => 45.0,
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Servers/Show')
            ->has('history', 2));
    }

    public function test_show_latest_prop_reflects_most_recent_metric(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        // Fractional values so JSON serialization keeps the decimal — a whole
        // number like 55.0 serializes to 55 and breaks a strict float assertion.
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 42.5,
            'ram_percent' => 55.5,
            'disk_percent' => 30.0,
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('latest.cpu', 42.5)
            ->where('latest.ram', 55.5));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Edit
    // ──────────────────────────────────────────────────────────────────────

    public function test_edit_returns_inertia_page_with_server_and_default_thresholds(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create([
            'team_id' => $user->team_id,
            'name' => 'edit-me',
        ]);

        $response = $this->actingAs($user)->get(route('servers.edit', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Servers/Edit')
            ->has('server')
            ->where('server.id', $server->id)
            ->where('server.name', 'edit-me')
            ->has('defaultThresholds'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Update
    // ──────────────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_update_server(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create([
            'team_id' => $user->team_id,
            'name' => 'old-name',
        ]);

        $response = $this->actingAs($user)
            ->put(route('servers.update', $server), $this->validPayload([
                'name' => 'new-name',
                'is_active' => false,
            ]));

        $response->assertRedirect(route('servers.index'));
        $this->assertDatabaseHas('servers', [
            'id' => $server->id,
            'name' => 'new-name',
            'is_active' => false,
        ]);
    }

    public function test_update_persists_settings_thresholds(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        $this->actingAs($user)
            ->put(route('servers.update', $server), $this->validPayload([
                'settings' => [
                    'thresholds' => ['cpu_warning' => 85],
                ],
            ]));

        $server->refresh();
        $this->assertEquals(85, $server->settings['thresholds']['cpu_warning']);
    }

    public function test_update_fails_when_name_is_missing(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        $response = $this->actingAs($user)
            ->put(route('servers.update', $server), ['is_active' => true]);

        $response->assertSessionHasErrors('name');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Destroy
    // ──────────────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_delete_their_server(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        $response = $this->actingAs($user)
            ->delete(route('servers.destroy', $server));

        $response->assertRedirect(route('servers.index'));
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Cross-team scoping
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The ScopedByTeam global scope means a server from another team is invisible
     * to the authenticated user — route model binding returns 404, not 403.
     */
    public function test_show_returns_404_for_server_from_another_team(): void
    {
        $userA = $this->createUserWithTeam();

        $teamB = Team::factory()->create();
        $serverB = Server::factory()->create(['team_id' => $teamB->id]);

        $response = $this->actingAs($userA)->get(route('servers.show', $serverB));

        $response->assertNotFound();
    }

    public function test_update_returns_404_for_server_from_another_team(): void
    {
        $userA = $this->createUserWithTeam();

        $teamB = Team::factory()->create();
        $serverB = Server::factory()->create(['team_id' => $teamB->id, 'name' => 'legit']);

        $response = $this->actingAs($userA)
            ->put(route('servers.update', $serverB), $this->validPayload(['name' => 'hacked']));

        $response->assertNotFound();
        $this->assertDatabaseMissing('servers', ['name' => 'hacked']);
    }

    public function test_delete_returns_404_for_server_from_another_team(): void
    {
        $userA = $this->createUserWithTeam();

        $teamB = Team::factory()->create();
        $serverB = Server::factory()->create(['team_id' => $teamB->id]);

        $response = $this->actingAs($userA)
            ->delete(route('servers.destroy', $serverB));

        $response->assertNotFound();
        $this->assertDatabaseHas('servers', ['id' => $serverB->id]);
    }

    public function test_edit_returns_404_for_server_from_another_team(): void
    {
        $userA = $this->createUserWithTeam();

        $teamB = Team::factory()->create();
        $serverB = Server::factory()->create(['team_id' => $teamB->id]);

        $response = $this->actingAs($userA)->get(route('servers.edit', $serverB));

        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Sites prop on show
    // ──────────────────────────────────────────────────────────────────────

    public function test_show_sites_prop_lists_domains_attached_to_server(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        Site::factory()->create([
            'team_id' => $user->team_id,
            'server_id' => $server->id,
            'primary_domain' => 'alpha.example.com',
        ]);
        Site::factory()->create([
            'team_id' => $user->team_id,
            'server_id' => $server->id,
            'primary_domain' => 'beta.example.com',
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Servers/Show')
            ->has('sites', 2));
    }
}
