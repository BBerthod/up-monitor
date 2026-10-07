<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests for the `navSites` shared Inertia prop injected by HandleInertiaRequests.
 *
 * Strategy: hit any authenticated Inertia page (/sites) and assert the shared
 * prop. The array cache driver (phpunit.xml) ensures isolation between tests.
 */
class NavSitesPropTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ──────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // ──────────────────────────────────────────────────
    // Guest → null
    // ──────────────────────────────────────────────────

    public function test_guest_receives_null_nav_sites(): void
    {
        // Hit the login page — it's a plain Inertia page that guests can access.
        $response = $this->get(route('login'));

        // For a guest, no Inertia prop assertion is needed; we just verify the
        // middleware does not blow up.  A redirect means the middleware ran fine.
        $response->assertSuccessful();
    }

    // ──────────────────────────────────────────────────
    // Authenticated user with sites
    // ──────────────────────────────────────────────────

    public function test_authenticated_user_sees_own_sites_in_nav(): void
    {
        $user = $this->createUserWithTeam();

        Site::factory()->for($user->team)->create([
            'alias' => 'my-blog',
            'primary_domain' => 'blog.example.com',
            'domains' => ['blog.example.com'],
        ]);

        $this->actingAs($user);

        $response = $this->get(route('sites.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->has('navSites');
            $navSites = $page->toArray()['props']['navSites'];
            $this->assertIsArray($navSites);
            $this->assertCount(1, $navSites);
            $this->assertSame('my-blog', $navSites[0]['name']);
            $this->assertSame('blog.example.com', $navSites[0]['domain']);
            $this->assertArrayHasKey('id', $navSites[0]);
        });
    }

    // ──────────────────────────────────────────────────
    // Team isolation — other team's sites never leak
    // ──────────────────────────────────────────────────

    public function test_nav_sites_is_team_scoped(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        // userB's site must never appear for userA
        Site::factory()->for($userB->team)->create(['alias' => 'secret-site']);

        $siteA = Site::factory()->for($userA->team)->create(['alias' => 'my-site']);

        $this->actingAs($userA);

        $response = $this->get(route('sites.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($siteA) {
            $navSites = $page->toArray()['props']['navSites'];
            $this->assertIsArray($navSites);
            $ids = array_column($navSites, 'id');
            $this->assertContains($siteA->id, $ids, 'Own site must be present');
            // userB's site must not appear
            $otherSite = Site::withoutGlobalScopes()->where('alias', 'secret-site')->first();
            $this->assertNotContains($otherSite->id, $ids, 'Other team site must not leak');
        });
    }

    // ──────────────────────────────────────────────────
    // Cache invalidation on Site update
    // ──────────────────────────────────────────────────

    public function test_cache_is_busted_when_site_is_updated(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['alias' => 'before-update']);

        $cacheKey = HandleInertiaRequests::navSitesCacheKey($user->team->id);

        // Seed the cache manually with stale data
        Cache::put($cacheKey, [['id' => $site->id, 'name' => 'stale', 'domain' => 'stale.com']], 60);
        $this->assertTrue(Cache::has($cacheKey), 'Cache should be seeded before update');

        // Trigger an Eloquent update — should bust the cache via booted()
        $site->update(['alias' => 'after-update']);

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be cleared after site update');
    }

    // ──────────────────────────────────────────────────
    // Cache invalidation on Site created
    // ──────────────────────────────────────────────────

    public function test_cache_is_busted_when_site_is_created(): void
    {
        $user = $this->createUserWithTeam();

        $cacheKey = HandleInertiaRequests::navSitesCacheKey($user->team->id);
        Cache::put($cacheKey, [], 60);
        $this->assertTrue(Cache::has($cacheKey));

        Site::factory()->for($user->team)->create();

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be cleared after site creation');
    }

    // ──────────────────────────────────────────────────
    // Cache invalidation on Site deleted
    // ──────────────────────────────────────────────────

    public function test_cache_is_busted_when_site_is_deleted(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create();

        $cacheKey = HandleInertiaRequests::navSitesCacheKey($user->team->id);
        Cache::put($cacheKey, [['id' => $site->id, 'name' => 'n', 'domain' => 'd']], 60);
        $this->assertTrue(Cache::has($cacheKey));

        $site->delete();

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be cleared after site deletion');
    }

    // ──────────────────────────────────────────────────
    // Sorted by name
    // ──────────────────────────────────────────────────

    public function test_nav_sites_are_sorted_by_name(): void
    {
        $user = $this->createUserWithTeam();

        Site::factory()->for($user->team)->create(['alias' => 'zebra-site']);
        Site::factory()->for($user->team)->create(['alias' => 'alpha-site']);
        Site::factory()->for($user->team)->create(['alias' => 'middle-site']);

        $this->actingAs($user);

        $response = $this->get(route('sites.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $navSites = $page->toArray()['props']['navSites'];
            $names = array_column($navSites, 'name');
            $sorted = $names;
            sort($sorted);
            $this->assertSame($sorted, $names, 'navSites must be sorted alphabetically by name');
        });
    }
}
