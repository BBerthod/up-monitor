<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightType;
use App\Jobs\RunWarmSite;
use App\Models\Insight;
use App\Models\Team;
use App\Models\User;
use App\Models\WarmRun;
use App\Models\WarmSite;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WarmSiteControllerTest extends TestCase
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

    public function test_guest_cannot_access_warming_index(): void
    {
        $response = $this->get(route('warming.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_view_warming_index(): void
    {
        $user = $this->createAuthenticatedUser();
        WarmSite::factory()->count(2)->for($user->team)->create();

        $response = $this->get(route('warming.index'));

        $response->assertStatus(200);
    }

    public function test_user_only_sees_own_team_sites(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();

        WarmSite::factory()->for($user->team)->create(['name' => 'My Site']);
        WarmSite::factory()->for($otherTeam)->create(['name' => 'Other Team Site']);

        $response = $this->get(route('warming.index'));

        $response->assertStatus(200);
        $this->assertSame(1, WarmSite::count());
    }

    public function test_user_can_create_warm_site_with_urls_mode(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'My Site',
            'domain' => 'example.com',
            'mode' => 'urls',
            'urls' => ['https://example.com/page1', 'https://example.com/page2'],
            'frequency_minutes' => 60,
            'max_urls' => 50,
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('warm_sites', [
            'name' => 'My Site',
            'domain' => 'example.com',
            'mode' => 'urls',
            'team_id' => $user->team_id,
        ]);
    }

    public function test_user_can_create_warm_site_with_sitemap_mode(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Sitemap Site',
            'domain' => 'sitemap-example.com',
            'mode' => 'sitemap',
            'sitemap_url' => 'https://sitemap-example.com/sitemap.xml',
            'frequency_minutes' => 120,
            'max_urls' => 100,
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('warm_sites', [
            'name' => 'Sitemap Site',
            'domain' => 'sitemap-example.com',
            'mode' => 'sitemap',
            'team_id' => $user->team_id,
        ]);
    }

    public function test_validation_rejects_invalid_domain(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Bad Domain',
            'domain' => 'not a domain!!!',
            'mode' => 'urls',
            'urls' => ['https://example.com/'],
            'frequency_minutes' => 60,
            'max_urls' => 50,
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('domain');
        $this->assertDatabaseCount('warm_sites', 0);
    }

    public function test_validation_rejects_duplicate_domain_per_team(): void
    {
        $user = $this->createAuthenticatedUser();
        WarmSite::factory()->for($user->team)->create(['domain' => 'duplicate.com']);

        $data = [
            'name' => 'Duplicate',
            'domain' => 'duplicate.com',
            'mode' => 'urls',
            'urls' => ['https://duplicate.com/'],
            'frequency_minutes' => 60,
            'max_urls' => 50,
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('domain');
        $this->assertDatabaseCount('warm_sites', 1);
    }

    public function test_user_can_view_warm_site_show(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create();
        WarmRun::factory()->count(2)->for($site, 'warmSite')->create();

        $response = $this->get(route('warming.show', $site));

        $response->assertStatus(200);
    }

    public function test_show_returns_24h_aggregated_stats(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create();

        // 2 completed runs within last 24h
        WarmRun::factory()->for($site, 'warmSite')->create([
            'status' => 'completed',
            'urls_total' => 100,
            'urls_hit' => 80,
            'urls_miss' => 20,
            'urls_error' => 0,
            'avg_response_ms' => 200,
            'started_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2)->addMinutes(5),
        ]);
        WarmRun::factory()->for($site, 'warmSite')->create([
            'status' => 'completed',
            'urls_total' => 100,
            'urls_hit' => 60,
            'urls_miss' => 40,
            'urls_error' => 0,
            'avg_response_ms' => 300,
            'started_at' => now()->subHours(1),
            'completed_at' => now()->subHours(1)->addMinutes(5),
        ]);
        // 1 failed run within last 24h (counts in runs_total but not runs_completed)
        WarmRun::factory()->for($site, 'warmSite')->failed()->create([
            'urls_total' => 0,
            'started_at' => now()->subMinutes(30),
        ]);

        $response = $this->get(route('warming.show', $site));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('CacheWarming/Show')
            ->has('stats24h')
            ->where('stats24h.runs_completed', 2)
            ->where('stats24h.runs_total', 3)
            ->where('stats24h.total_urls', 200)
            ->where('stats24h.hit_ratio', fn ($v) => (float) $v === 70.0)
            ->has('lastSuccessfulRun')
        );
    }

    public function test_show_returns_null_last_successful_run_when_no_completed_runs(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create();
        WarmRun::factory()->for($site, 'warmSite')->failed()->create([
            'urls_total' => 0,
            'started_at' => now()->subMinutes(10),
        ]);

        $response = $this->get(route('warming.show', $site));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('CacheWarming/Show')
            ->where('lastSuccessfulRun', null)
            ->where('stats24h.runs_completed', 0)
            ->where('stats24h.hit_ratio', null)
        );
    }

    public function test_user_cannot_view_other_teams_site(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();
        $otherSite = WarmSite::factory()->for($otherTeam)->create();

        $response = $this->get(route('warming.show', $otherSite));

        $response->assertNotFound();
    }

    public function test_user_can_update_warm_site(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create(['name' => 'Old Name']);

        $response = $this->put(route('warming.update', $site), [
            'name' => 'New Name',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('warm_sites', [
            'id' => $site->id,
            'name' => 'New Name',
        ]);
    }

    public function test_reactivating_disabled_site_resolves_warming_disabled_insight(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create(['is_active' => false]);

        $insight = Insight::factory()->for($user->team)->create([
            'type' => InsightType::WARMING_DISABLED->value,
            'payload' => ['warm_site_id' => $site->id, 'already_notified_directly' => true],
            'acknowledged_at' => null,
        ]);

        $response = $this->put(route('warming.update', $site), [
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $insight->refresh();
        $this->assertNotNull($insight->acknowledged_at);
    }

    public function test_updating_active_site_without_reactivation_leaves_insight_untouched(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create(['is_active' => true, 'name' => 'Old Name']);

        $insight = Insight::factory()->for($user->team)->create([
            'type' => InsightType::WARMING_DISABLED->value,
            'payload' => ['warm_site_id' => $site->id, 'already_notified_directly' => true],
            'acknowledged_at' => null,
        ]);

        // No is_active transition (site was already active) — must not touch the insight.
        $this->put(route('warming.update', $site), ['name' => 'New Name']);

        $insight->refresh();
        $this->assertNull($insight->acknowledged_at);
    }

    public function test_user_cannot_update_other_teams_site(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();
        $otherSite = WarmSite::factory()->for($otherTeam)->create();

        $response = $this->put(route('warming.update', $otherSite), [
            'name' => 'Hacked Name',
        ]);

        $response->assertNotFound();
    }

    public function test_user_can_delete_warm_site(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create();

        $response = $this->delete(route('warming.destroy', $site));

        $response->assertRedirect(route('warming.index'));
        $this->assertDatabaseMissing('warm_sites', ['id' => $site->id]);
    }

    public function test_user_cannot_delete_other_teams_site(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();
        $otherSite = WarmSite::factory()->for($otherTeam)->create();

        $response = $this->delete(route('warming.destroy', $otherSite));

        $response->assertNotFound();
        $this->assertDatabaseHas('warm_sites', ['id' => $otherSite->id]);
    }

    public function test_warm_now_dispatches_job(): void
    {
        Queue::fake();

        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create();

        $response = $this->post(route('warming.warm-now', $site));

        $response->assertRedirect();
        Queue::assertPushed(RunWarmSite::class, function ($job) use ($site) {
            return $job->warmSite->id === $site->id;
        });
    }

    public function test_user_cannot_warm_now_other_teams_site(): void
    {
        Queue::fake();

        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();
        $otherSite = WarmSite::factory()->for($otherTeam)->create();

        $response = $this->post(route('warming.warm-now', $otherSite));

        $response->assertNotFound();
        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // timeout_seconds validation tests
    // -------------------------------------------------------------------------

    public function test_store_accepts_valid_timeout_seconds(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Timeout Site',
            'domain' => 'timeout-test.com',
            'mode' => 'sitemap',
            'sitemap_url' => 'https://timeout-test.com/sitemap.xml',
            'frequency_minutes' => 60,
            'max_urls' => 50,
            'timeout_seconds' => 30,
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('warm_sites', [
            'domain' => 'timeout-test.com',
            'timeout_seconds' => 30,
        ]);
    }

    public function test_store_uses_default_timeout_when_not_provided(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Default Timeout Site',
            'domain' => 'default-timeout.com',
            'mode' => 'sitemap',
            'sitemap_url' => 'https://default-timeout.com/sitemap.xml',
            'frequency_minutes' => 60,
            'max_urls' => 50,
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('warm_sites', [
            'domain' => 'default-timeout.com',
            'timeout_seconds' => 10,
        ]);
    }

    public function test_store_rejects_timeout_below_minimum(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Low Timeout',
            'domain' => 'low-timeout.com',
            'mode' => 'sitemap',
            'sitemap_url' => 'https://low-timeout.com/sitemap.xml',
            'frequency_minutes' => 60,
            'max_urls' => 50,
            'timeout_seconds' => 4,
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertSessionHasErrors('timeout_seconds');
        $this->assertDatabaseCount('warm_sites', 0);
    }

    public function test_store_rejects_timeout_above_maximum(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'High Timeout',
            'domain' => 'high-timeout.com',
            'mode' => 'sitemap',
            'sitemap_url' => 'https://high-timeout.com/sitemap.xml',
            'frequency_minutes' => 60,
            'max_urls' => 50,
            'timeout_seconds' => 61,
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertSessionHasErrors('timeout_seconds');
        $this->assertDatabaseCount('warm_sites', 0);
    }

    public function test_update_accepts_timeout_at_bounds(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create(['timeout_seconds' => 10]);

        // Min bound
        $this->put(route('warming.update', $site), ['timeout_seconds' => 5])
            ->assertRedirect();
        $this->assertDatabaseHas('warm_sites', ['id' => $site->id, 'timeout_seconds' => 5]);

        // Max bound
        $this->put(route('warming.update', $site), ['timeout_seconds' => 60])
            ->assertRedirect();
        $this->assertDatabaseHas('warm_sites', ['id' => $site->id, 'timeout_seconds' => 60]);
    }

    // -------------------------------------------------------------------------
    // warmNow in-progress guard tests
    // -------------------------------------------------------------------------

    public function test_warm_now_is_blocked_when_a_recent_running_run_exists(): void
    {
        Queue::fake();

        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create();

        // A run that started 5 minutes ago is still considered live
        WarmRun::factory()->for($site, 'warmSite')->running()->create([
            'started_at' => now()->subMinutes(5),
        ]);

        $response = $this->post(route('warming.warm-now', $site));

        $response->assertRedirect();
        $response->assertSessionHas('warning');
        Queue::assertNotPushed(RunWarmSite::class);
    }

    public function test_warm_now_dispatches_when_no_running_run_exists(): void
    {
        Queue::fake();

        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create();

        // Only a completed run — should not block dispatch
        WarmRun::factory()->for($site, 'warmSite')->create([
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(5),
        ]);

        $response = $this->post(route('warming.warm-now', $site));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Queue::assertPushed(RunWarmSite::class, function ($job) use ($site) {
            return $job->warmSite->id === $site->id;
        });
    }

    // -------------------------------------------------------------------------
    // custom_headers validation tests (flat header-name => value map)
    // -------------------------------------------------------------------------

    public function test_cannot_create_warm_site_with_host_custom_header(): void
    {
        $this->createAuthenticatedUser();

        $data = [
            'name' => 'Header Guard',
            'domain' => 'header-guard.com',
            'mode' => 'sitemap',
            'sitemap_url' => 'https://header-guard.com/sitemap.xml',
            'frequency_minutes' => 60,
            'max_urls' => 50,
            'custom_headers' => ['Host' => 'evil.example.com'],
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertSessionHasErrors('custom_headers');
        $this->assertDatabaseCount('warm_sites', 0);
    }

    public function test_can_create_warm_site_with_referer_custom_header(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Referer Guard',
            'domain' => 'referer-guard.com',
            'mode' => 'sitemap',
            'sitemap_url' => 'https://referer-guard.com/sitemap.xml',
            'frequency_minutes' => 60,
            'max_urls' => 50,
            'custom_headers' => ['Referer' => 'https://referer-guard.com/'],
        ];

        $response = $this->post(route('warming.store'), $data);

        $response->assertRedirect();
        $site = WarmSite::where('domain', 'referer-guard.com')->firstOrFail();
        $this->assertSame($user->team_id, $site->team_id);
        $this->assertSame(['Referer' => 'https://referer-guard.com/'], $site->custom_headers);
    }

    public function test_cannot_update_warm_site_with_host_custom_header(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create();

        $response = $this->put(route('warming.update', $site), [
            'custom_headers' => ['Host' => 'evil.example.com'],
        ]);

        $response->assertSessionHasErrors('custom_headers');
    }

    public function test_warm_now_dispatches_when_running_run_is_older_than_15_minutes(): void
    {
        Queue::fake();

        $user = $this->createAuthenticatedUser();
        $site = WarmSite::factory()->for($user->team)->create();

        // An orphaned run (20 min old) — treated as dead, dispatch is allowed
        WarmRun::factory()->for($site, 'warmSite')->running()->create([
            'started_at' => now()->subMinutes(20),
        ]);

        $response = $this->post(route('warming.warm-now', $site));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Queue::assertPushed(RunWarmSite::class, function ($job) use ($site) {
            return $job->warmSite->id === $site->id;
        });
    }
}
