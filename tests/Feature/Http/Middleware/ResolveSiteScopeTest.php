<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for ResolveSiteScope middleware.
 *
 * Strategy: hit the authenticated /monitors route so the full middleware stack
 * runs, then inspect the siteScope shared Inertia prop.
 */
class ResolveSiteScopeTest extends TestCase
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

    public function test_default_scope_is_all(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)->get(route('monitors.index'));

        $response->assertOk();
        $response->assertInertia(function ($page): void {
            $scope = $page->toArray()['props']['siteScope'];
            $this->assertSame('all', $scope['mode']);
            $this->assertNull($scope['site']);
        });
    }

    public function test_site_param_all_sets_mode_all(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)->get(route('monitors.index', ['site' => 'all']));

        $response->assertOk();
        $response->assertInertia(function ($page): void {
            $scope = $page->toArray()['props']['siteScope'];
            $this->assertSame('all', $scope['mode']);
            $this->assertNull($scope['site']);
        });
    }

    public function test_site_param_unassigned_sets_mode_unassigned(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)->get(route('monitors.index', ['site' => 'unassigned']));

        $response->assertOk();
        $response->assertInertia(function ($page): void {
            $scope = $page->toArray()['props']['siteScope'];
            $this->assertSame('unassigned', $scope['mode']);
            $this->assertNull($scope['site']);
        });
    }

    public function test_site_param_own_site_id_sets_mode_site(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create([
            'alias' => 'my-blog',
            'primary_domain' => 'blog.example.com',
        ]);

        $response = $this->actingAs($user)->get(route('monitors.index', ['site' => $site->id]));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($site): void {
            $scope = $page->toArray()['props']['siteScope'];
            $this->assertSame('site', $scope['mode']);
            $this->assertNotNull($scope['site']);
            $this->assertSame($site->id, $scope['site']['id']);
            $this->assertSame('my-blog', $scope['site']['name']);
        });
    }

    public function test_foreign_team_site_silently_becomes_all(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();
        $foreignSite = Site::factory()->for($userB->team)->create(['primary_domain' => 'secret.example.com']);

        $response = $this->actingAs($userA)->get(route('monitors.index', ['site' => $foreignSite->id]));

        // No 403 (anti-IDOR) — must return 200 and fall back to mode=all
        $response->assertOk();
        $response->assertInertia(function ($page): void {
            $scope = $page->toArray()['props']['siteScope'];
            $this->assertSame('all', $scope['mode'], 'Foreign site must silently fall back to all');
        });
    }

    public function test_scope_is_persisted_in_session(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create([
            'alias' => 'persistent-site',
            'primary_domain' => 'persistent.example.com',
        ]);

        // First request: set the scope
        $this->actingAs($user)->get(route('monitors.index', ['site' => $site->id]));

        // Second request: no query param — scope must be restored from session
        $response = $this->actingAs($user)->get(route('monitors.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($site): void {
            $scope = $page->toArray()['props']['siteScope'];
            $this->assertSame('site', $scope['mode'], 'Scope must be restored from session');
            $this->assertSame($site->id, $scope['site']['id']);
        });
    }

    public function test_site_all_clears_session_scope(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['primary_domain' => 'example.com']);

        // Set a site scope then clear it
        $this->actingAs($user)->get(route('monitors.index', ['site' => $site->id]));
        $this->actingAs($user)->get(route('monitors.index', ['site' => 'all']));

        // Subsequent request: scope must be all
        $response = $this->actingAs($user)->get(route('monitors.index'));

        $response->assertOk();
        $response->assertInertia(function ($page): void {
            $scope = $page->toArray()['props']['siteScope'];
            $this->assertSame('all', $scope['mode']);
        });
    }
}
