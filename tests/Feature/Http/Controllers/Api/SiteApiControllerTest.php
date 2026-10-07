<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteApiControllerTest extends TestCase
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
    // index — authentication + team isolation
    // ──────────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $response = $this->getJson(route('api.sites.index'));

        $response->assertUnauthorized();
    }

    public function test_can_list_sites_via_api(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        Site::factory()->count(2)->for($userA->team)->create();
        Site::factory()->count(1)->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->getJson(route('api.sites.index'));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    // ──────────────────────────────────────────────────
    // store
    // ──────────────────────────────────────────────────

    public function test_can_create_site_via_api(): void
    {
        $user = $this->createUserWithTeam();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.sites.store'), [
            'alias' => 'my-site',
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'type' => 'wordpress',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.alias', 'my-site');
        $this->assertDatabaseHas('sites', [
            'team_id' => $user->team_id,
            'alias' => 'my-site',
        ]);
    }

    public function test_create_validates_required_fields(): void
    {
        $user = $this->createUserWithTeam();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.sites.store'), [
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'type' => 'wordpress',
            // alias intentionally omitted
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['alias']);
    }

    // ──────────────────────────────────────────────────
    // show — cross-team 404
    // ──────────────────────────────────────────────────

    public function test_can_show_own_site(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.sites.show', $site));

        $response->assertOk();
        $response->assertJsonPath('data.id', $site->id);
    }

    public function test_cannot_access_other_team_site(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $siteB = Site::factory()->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->getJson(route('api.sites.show', $siteB));

        // ScopedByTeam global scope filters out other teams' sites before the policy runs,
        // so route-model binding returns 404 rather than policy returning 403.
        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────────
    // update
    // ──────────────────────────────────────────────────

    public function test_can_update_site_via_api(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create(['alias' => 'original-alias']);

        Sanctum::actingAs($user);

        $response = $this->putJson(route('api.sites.update', $site), [
            'alias' => 'updated-alias',
            'primary_domain' => $site->primary_domain,
            'domains' => $site->domains,
            'type' => $site->type,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.alias', 'updated-alias');
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'alias' => 'updated-alias']);
    }

    public function test_cannot_update_other_team_site(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $siteB = Site::factory()->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->putJson(route('api.sites.update', $siteB), [
            'alias' => 'hacked',
        ]);

        // ScopedByTeam prevents cross-team sites from being found; route-model binding returns 404.
        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────────
    // destroy
    // ──────────────────────────────────────────────────

    public function test_can_destroy_own_site(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create();

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.sites.destroy', $site));

        $response->assertNoContent();
        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_cannot_destroy_other_team_site(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $siteB = Site::factory()->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->deleteJson(route('api.sites.destroy', $siteB));

        // ScopedByTeam prevents cross-team sites from being found; route-model binding returns 404.
        $response->assertNotFound();
        $this->assertDatabaseHas('sites', ['id' => $siteB->id]);
    }
}
