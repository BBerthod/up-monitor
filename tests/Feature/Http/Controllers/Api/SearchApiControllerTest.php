<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for SearchApiController.
 *
 * Covers:
 *  - authentication requirement
 *  - short query (< 2 chars) returns empty groups
 *  - `sites` group: match on alias and primary_domain
 *  - `servers` group: match on name and dokploy_server_id
 *  - team isolation for both new groups
 */
class SearchApiControllerTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // ──────────────────────────────────────────────────
    // Auth guard
    // ──────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson(route('api.search', ['q' => 'example']));

        $response->assertUnauthorized();
    }

    // ──────────────────────────────────────────────────
    // Short query fast-path
    // ──────────────────────────────────────────────────

    public function test_short_query_returns_empty_groups(): void
    {
        Sanctum::actingAs($this->createUserWithTeam());

        $response = $this->getJson(route('api.search', ['q' => 'a']));

        $response->assertOk();
        $response->assertJsonFragment(['sites' => [], 'servers' => []]);
    }

    // ──────────────────────────────────────────────────
    // Sites group — alias match
    // ──────────────────────────────────────────────────

    public function test_sites_group_matches_alias(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['alias' => 'my-blog']);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.search', ['q' => 'blog']));

        $response->assertOk();

        $sites = $response->json('sites');
        $this->assertCount(1, $sites);
        $this->assertSame($site->id, $sites[0]['id']);
        $this->assertSame('my-blog', $sites[0]['name']);
        $this->assertArrayHasKey('domain', $sites[0]);
        $this->assertArrayHasKey('url', $sites[0]);
    }

    // ──────────────────────────────────────────────────
    // Sites group — primary_domain match
    // ──────────────────────────────────────────────────

    public function test_sites_group_matches_primary_domain(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create([
            'alias' => 'some-alias',
            'primary_domain' => 'acme-corp.com',
            'domains' => ['acme-corp.com'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.search', ['q' => 'acme']));

        $response->assertOk();

        $sites = $response->json('sites');
        $this->assertCount(1, $sites);
        $this->assertSame($site->id, $sites[0]['id']);
    }

    // ──────────────────────────────────────────────────
    // Sites group — team isolation
    // ──────────────────────────────────────────────────

    public function test_sites_group_is_team_scoped(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        // userB owns a site whose alias would match our query
        Site::factory()->for($userB->team)->create(['alias' => 'shared-term']);

        // userA owns a different site
        $siteA = Site::factory()->for($userA->team)->create(['alias' => 'shared-term-a']);

        Sanctum::actingAs($userA);

        $response = $this->getJson(route('api.search', ['q' => 'shared-term']));

        $response->assertOk();

        $siteIds = array_column($response->json('sites'), 'id');

        // userA's site appears; userB's site must not
        $this->assertContains($siteA->id, $siteIds);
        $this->assertNotContains(
            Site::withoutGlobalScopes()->where('team_id', $userB->team_id)->first()->id,
            $siteIds
        );
    }

    // ──────────────────────────────────────────────────
    // Servers group — name match
    // ──────────────────────────────────────────────────

    public function test_servers_group_matches_name(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->for($user->team)->create(['name' => 'prod-server']);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.search', ['q' => 'hetzner']));

        $response->assertOk();

        $servers = $response->json('servers');
        $this->assertCount(1, $servers);
        $this->assertSame($server->id, $servers[0]['id']);
        $this->assertSame('prod-server', $servers[0]['name']);
        $this->assertArrayHasKey('is_active', $servers[0]);
        $this->assertArrayHasKey('url', $servers[0]);
    }

    // ──────────────────────────────────────────────────
    // Servers group — dokploy_server_id match
    // ──────────────────────────────────────────────────

    public function test_servers_group_matches_dokploy_server_id(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->for($user->team)->create([
            'name' => 'main-server',
            'dokploy_server_id' => 'abc-1234-xyz',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.search', ['q' => 'abc-1234']));

        $response->assertOk();

        $servers = $response->json('servers');
        $this->assertCount(1, $servers);
        $this->assertSame($server->id, $servers[0]['id']);
    }

    // ──────────────────────────────────────────────────
    // Servers group — team isolation
    // ──────────────────────────────────────────────────

    public function test_servers_group_is_team_scoped(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        Server::factory()->for($userB->team)->create(['name' => 'prod-server']);
        $serverA = Server::factory()->for($userA->team)->create(['name' => 'prod-server-a']);

        Sanctum::actingAs($userA);

        $response = $this->getJson(route('api.search', ['q' => 'prod-server']));

        $response->assertOk();

        $serverIds = array_column($response->json('servers'), 'id');

        $this->assertContains($serverA->id, $serverIds);
        $this->assertNotContains(
            Server::withoutGlobalScopes()->where('team_id', $userB->team_id)->first()->id,
            $serverIds
        );
    }

    // ──────────────────────────────────────────────────
    // Response structure — all groups always present
    // ──────────────────────────────────────────────────

    public function test_response_always_includes_all_groups(): void
    {
        Sanctum::actingAs($this->createUserWithTeam());

        $response = $this->getJson(route('api.search', ['q' => 'zzz-no-match']));

        $response->assertOk();
        $response->assertJsonStructure([
            'monitors',
            'incidents',
            'notification_channels',
            'status_pages',
            'sites',
            'servers',
        ]);
    }
}
