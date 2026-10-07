<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function createAuthenticatedUser(): User
    {
        $team = Team::factory()->create();
        $user = User::factory()->create([
            'team_id' => $team->id,
            'role' => 'member',
        ]);
        $this->actingAs($user);

        return $user;
    }

    private function validSitePayload(array $overrides = []): array
    {
        return array_merge([
            'alias' => 'my-site',
            'organization' => 'Radiank',
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'type' => 'wordpress',
            'is_active' => true,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Auth guard
    // -------------------------------------------------------------------------

    public function test_requires_auth(): void
    {
        $response = $this->get(route('sites.index'));

        $response->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_index_lists_team_sites(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();

        Site::factory()->count(2)->for($user->team)->create();
        Site::factory()->for($otherTeam)->create();

        $response = $this->get(route('sites.index'));

        // The Vue page does not exist yet (frontend is a separate task).
        // Assert 200 + that the Inertia props only contain the 2 team-scoped sites.
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('sites', 2));
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_can_create_site(): void
    {
        $user = $this->createAuthenticatedUser();

        $response = $this->post(route('sites.store'), $this->validSitePayload());

        $response->assertRedirect(route('sites.index'));
        $this->assertDatabaseHas('sites', [
            'alias' => 'my-site',
            'primary_domain' => 'example.com',
            'team_id' => $user->team_id,
        ]);
    }

    public function test_alias_must_be_unique_per_team(): void
    {
        $user = $this->createAuthenticatedUser();
        Site::factory()->for($user->team)->create(['alias' => 'taken']);

        // Same alias, same team — should fail.
        $response = $this->post(route('sites.store'), $this->validSitePayload(['alias' => 'taken']));

        $response->assertSessionHasErrors('alias');
        $this->assertDatabaseCount('sites', 1);
    }

    public function test_alias_is_unique_per_team_not_globally(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();
        // Another team already has alias "shared".
        Site::factory()->for($otherTeam)->create(['alias' => 'shared']);

        // Same alias but different team — should succeed.
        $response = $this->post(route('sites.store'), $this->validSitePayload(['alias' => 'shared']));

        $response->assertRedirect(route('sites.index'));
        $this->assertDatabaseCount('sites', 2);
    }

    public function test_domains_must_have_at_least_one_entry(): void
    {
        $this->createAuthenticatedUser();

        $response = $this->post(route('sites.store'), $this->validSitePayload(['domains' => []]));

        $response->assertSessionHasErrors('domains');
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function test_can_update_site(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = Site::factory()->for($user->team)->create(['alias' => 'old-alias']);

        $response = $this->put(route('sites.update', $site), [
            'alias' => 'new-alias',
            'primary_domain' => $site->primary_domain,
            'domains' => $site->domains,
            'type' => $site->type,
        ]);

        $response->assertRedirect(route('sites.index'));
        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'alias' => 'new-alias',
        ]);
    }

    public function test_update_ignores_own_alias_in_uniqueness_check(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = Site::factory()->for($user->team)->create(['alias' => 'mine']);

        // Submitting the same alias on update should succeed (not flag as duplicate).
        $response = $this->put(route('sites.update', $site), [
            'alias' => 'mine',
            'primary_domain' => $site->primary_domain,
            'domains' => $site->domains,
            'type' => $site->type,
        ]);

        $response->assertRedirect(route('sites.index'));
    }

    // -------------------------------------------------------------------------
    // Destroy
    // -------------------------------------------------------------------------

    public function test_can_delete_site(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = Site::factory()->for($user->team)->create();

        $response = $this->delete(route('sites.destroy', $site));

        $response->assertRedirect(route('sites.index'));
        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_delete_nullifies_monitor_site_id(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = Site::factory()->for($user->team)->create();
        $monitor = Monitor::factory()->for($user->team)->create(['site_id' => $site->id]);

        $this->delete(route('sites.destroy', $site));

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('monitors', [
            'id' => $monitor->id,
            'site_id' => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Cross-team protection
    // -------------------------------------------------------------------------

    public function test_cannot_manage_other_team_site_update(): void
    {
        $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();
        $otherSite = Site::factory()->for($otherTeam)->create();

        $response = $this->put(route('sites.update', $otherSite), [
            'alias' => 'hacked',
            'primary_domain' => 'hacked.com',
            'domains' => ['hacked.com'],
            'type' => 'static',
        ]);

        // The ScopedByTeam global scope causes a 404 (model not found for the
        // authenticated user's team) rather than a 403 from the policy.
        $response->assertNotFound();
        $this->assertDatabaseMissing('sites', ['alias' => 'hacked']);
    }

    public function test_cannot_manage_other_team_site_delete(): void
    {
        $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();
        $otherSite = Site::factory()->for($otherTeam)->create();

        $response = $this->delete(route('sites.destroy', $otherSite));

        $response->assertNotFound();
        $this->assertDatabaseHas('sites', ['id' => $otherSite->id]);
    }
}
