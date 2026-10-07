<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that the site-scope lens (all / site / unassigned) correctly filters
 * list controllers: MonitorController, IncidentController, WarmSiteController,
 * ServerController.
 *
 * Strategy: use the `site` query param to set the scope on each request, since
 * ResolveSiteScope reads it before the controller runs.
 */
class SiteScopeFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // =========================================================================
    // MonitorController::index
    // =========================================================================

    public function test_monitors_scoped_to_site_only_shows_site_monitors(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['primary_domain' => 'a.example.com']);

        $scoped = Monitor::factory()->for($user->team)->create([
            'name' => 'scoped-monitor',
            'url' => 'https://a.example.com',
            'site_id' => $site->id,
        ]);
        $unscoped = Monitor::factory()->for($user->team)->create([
            'name' => 'unscoped-monitor',
            'url' => 'https://b.example.com',
            'site_id' => null,
        ]);

        $response = $this->actingAs($user)->get(route('monitors.index', ['site' => $site->id]));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($scoped, $unscoped): void {
            $ids = array_column($page->toArray()['props']['monitors'], 'id');
            $this->assertContains($scoped->id, $ids);
            $this->assertNotContains($unscoped->id, $ids);
        });
    }

    public function test_monitors_unassigned_scope_shows_only_site_null_monitors(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['primary_domain' => 'a.example.com']);

        $assigned = Monitor::factory()->for($user->team)->create([
            'name' => 'assigned',
            'url' => 'https://a.example.com',
            'site_id' => $site->id,
        ]);
        $orphan = Monitor::factory()->for($user->team)->create([
            'name' => 'orphan',
            'url' => 'https://b.example.com',
            'site_id' => null,
        ]);

        $response = $this->actingAs($user)->get(route('monitors.index', ['site' => 'unassigned']));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($assigned, $orphan): void {
            $ids = array_column($page->toArray()['props']['monitors'], 'id');
            $this->assertContains($orphan->id, $ids);
            $this->assertNotContains($assigned->id, $ids);
        });
    }

    public function test_monitors_all_scope_shows_all_team_monitors(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['primary_domain' => 'a.example.com']);

        $m1 = Monitor::factory()->for($user->team)->create([
            'name' => 'mon1',
            'url' => 'https://a.example.com',
            'site_id' => $site->id,
        ]);
        $m2 = Monitor::factory()->for($user->team)->create([
            'name' => 'mon2',
            'url' => 'https://b.example.com',
            'site_id' => null,
        ]);

        $response = $this->actingAs($user)->get(route('monitors.index', ['site' => 'all']));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($m1, $m2): void {
            $ids = array_column($page->toArray()['props']['monitors'], 'id');
            $this->assertContains($m1->id, $ids);
            $this->assertContains($m2->id, $ids);
        });
    }

    // =========================================================================
    // MonitorController::create — defaultSiteId pre-fill
    // =========================================================================

    public function test_create_prefills_site_id_when_scoped(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['primary_domain' => 'a.example.com']);

        $response = $this->actingAs($user)->get(route('monitors.create', ['site' => $site->id]));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($site): void {
            $this->assertSame($site->id, $page->toArray()['props']['defaultSiteId']);
        });
    }

    public function test_create_sends_null_default_site_id_when_all(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)->get(route('monitors.create'));

        $response->assertOk();
        $response->assertInertia(function ($page): void {
            $this->assertNull($page->toArray()['props']['defaultSiteId']);
        });
    }

    // =========================================================================
    // IncidentController::index
    // =========================================================================

    public function test_incidents_scoped_to_site_only_shows_site_incidents(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['primary_domain' => 'a.example.com']);

        $scopedMonitor = Monitor::factory()->for($user->team)->create([
            'url' => 'https://a.example.com',
            'site_id' => $site->id,
        ]);
        $otherMonitor = Monitor::factory()->for($user->team)->create([
            'url' => 'https://b.example.com',
            'site_id' => null,
        ]);

        $scopedIncident = MonitorIncident::factory()
            ->for($scopedMonitor)
            ->create(['started_at' => now()]);
        $otherIncident = MonitorIncident::factory()
            ->for($otherMonitor)
            ->create(['started_at' => now()]);

        $response = $this->actingAs($user)->get(route('incidents.index', ['site' => $site->id]));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($scopedIncident, $otherIncident): void {
            $ids = array_column($page->toArray()['props']['incidents']['data'], 'id');
            $this->assertContains($scopedIncident->id, $ids);
            $this->assertNotContains($otherIncident->id, $ids);
        });
    }

    public function test_incidents_unassigned_scope_shows_orphan_monitor_incidents(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['primary_domain' => 'a.example.com']);

        $assignedMonitor = Monitor::factory()->for($user->team)->create([
            'url' => 'https://a.example.com',
            'site_id' => $site->id,
        ]);
        $orphanMonitor = Monitor::factory()->for($user->team)->create([
            'url' => 'https://b.example.com',
            'site_id' => null,
        ]);

        $assignedIncident = MonitorIncident::factory()->for($assignedMonitor)->create(['started_at' => now()]);
        $orphanIncident = MonitorIncident::factory()->for($orphanMonitor)->create(['started_at' => now()]);

        $response = $this->actingAs($user)->get(route('incidents.index', ['site' => 'unassigned']));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($assignedIncident, $orphanIncident): void {
            $ids = array_column($page->toArray()['props']['incidents']['data'], 'id');
            $this->assertContains($orphanIncident->id, $ids);
            $this->assertNotContains($assignedIncident->id, $ids);
        });
    }

    // =========================================================================
    // ServerController::index
    // =========================================================================

    public function test_servers_scoped_to_site_only_shows_hosting_server(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['primary_domain' => 'a.example.com']);

        $hostingServer = Server::factory()->for($user->team)->create(['name' => 'hosting-server']);
        $otherServer = Server::factory()->for($user->team)->create(['name' => 'other-server']);

        // Associate site with hostingServer
        $site->update(['server_id' => $hostingServer->id]);

        $response = $this->actingAs($user)->get(route('servers.index', ['site' => $site->id]));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($hostingServer, $otherServer): void {
            $ids = array_column($page->toArray()['props']['servers'], 'id');
            $this->assertContains($hostingServer->id, $ids);
            $this->assertNotContains($otherServer->id, $ids);
        });
    }

    public function test_servers_unassigned_scope_shows_servers_without_sites(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['primary_domain' => 'a.example.com']);

        $serverWithSite = Server::factory()->for($user->team)->create(['name' => 'with-site']);
        $serverWithout = Server::factory()->for($user->team)->create(['name' => 'without-site']);

        $site->update(['server_id' => $serverWithSite->id]);

        $response = $this->actingAs($user)->get(route('servers.index', ['site' => 'unassigned']));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($serverWithSite, $serverWithout): void {
            $ids = array_column($page->toArray()['props']['servers'], 'id');
            $this->assertContains($serverWithout->id, $ids);
            $this->assertNotContains($serverWithSite->id, $ids);
        });
    }

    // =========================================================================
    // Cross-team isolation (IDOR guard)
    // =========================================================================

    public function test_foreign_site_scope_does_not_leak_data(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $foreignSite = Site::factory()->for($userB->team)->create(['primary_domain' => 'foreign.example.com']);
        Monitor::factory()->for($userB->team)->create([
            'url' => 'https://foreign.example.com',
            'site_id' => $foreignSite->id,
        ]);

        // userA tries to scope to userB's site — must silently become mode=all
        // and only show userA's own monitors (none in this case)
        $response = $this->actingAs($userA)->get(route('monitors.index', ['site' => $foreignSite->id]));

        $response->assertOk();
        $response->assertInertia(function ($page): void {
            // scope falls back to all but userA has no monitors — empty list
            $monitors = $page->toArray()['props']['monitors'];
            $this->assertIsArray($monitors);
            $this->assertEmpty($monitors);
        });
    }
}
