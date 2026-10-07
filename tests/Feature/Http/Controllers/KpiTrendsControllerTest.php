<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\MonitorLighthouseScore;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for KpiTrendsController.
 */
class KpiTrendsControllerTest extends TestCase
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('kpi-trends.index'))->assertRedirect(route('login'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Payload structure
    // ──────────────────────────────────────────────────────────────────────

    public function test_renders_kpi_trends_page_with_required_props(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)->get(route('kpi-trends.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('KpiTrends')
            ->has('sites')
            ->has('lighthouse')
        );
    }

    public function test_sites_prop_contains_team_sites(): void
    {
        $user = $this->createUserWithTeam();
        Site::factory()->create([
            'team_id' => $user->team_id,
            'alias' => 'My Site',
            'primary_domain' => 'example.com',
        ]);

        $response = $this->actingAs($user)->get(route('kpi-trends.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('sites', 1)
            ->where('sites.0.domain', 'example.com')
            ->has('sites.0.kpis')
            ->has('sites.0.kpis.gsc_clicks_28d')
            ->has('sites.0.kpis.gsc_impressions_28d')
            ->has('sites.0.kpis.gsc_position_28d')
            ->has('sites.0.kpis.bing_clicks_28d')
            ->has('sites.0.kpis.ga4_users_28d')
            ->has('sites.0.kpis.ttfb')
            ->has('sites.0.health_score')
        );
    }

    public function test_kpi_values_are_populated_from_snapshots(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->create([
            'team_id' => $user->team_id,
            'primary_domain' => 'mysite.com',
        ]);

        // Create a KpiSnapshot for this site.
        KpiSnapshot::factory()->create([
            'site' => 'mysite.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 1234.0,
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('kpi-trends.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('sites.0.kpis.gsc_clicks_28d', 1234)
        );
    }

    public function test_lighthouse_prop_is_grouped_per_monitor(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->create(['team_id' => $user->team_id, 'primary_domain' => 'lh.com']);
        $monitor = Monitor::factory()->for($user->team)->create(['site_id' => $site->id, 'url' => 'https://lh.com']);

        MonitorLighthouseScore::factory()->for($monitor)->create([
            'performance' => 90,
            'seo' => 85,
            'accessibility' => 92,
            'best_practices' => 88,
            'scored_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('kpi-trends.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('lighthouse', 1)
            ->where('lighthouse.0.monitor_id', $monitor->id)
            ->where('lighthouse.0.performance', 90)
            ->has('lighthouse.0.site_id')
            ->has('lighthouse.0.scored_at')
        );
    }

    public function test_lighthouse_returns_only_latest_score_per_monitor(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->create(['team_id' => $user->team_id, 'primary_domain' => 'lh2.com']);
        $monitor = Monitor::factory()->for($user->team)->create(['site_id' => $site->id, 'url' => 'https://lh2.com']);

        // Older score.
        MonitorLighthouseScore::factory()->for($monitor)->create([
            'performance' => 60,
            'scored_at' => now()->subDays(7),
        ]);
        // Newer score.
        MonitorLighthouseScore::factory()->for($monitor)->create([
            'performance' => 88,
            'scored_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('kpi-trends.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('lighthouse', 1)
            ->where('lighthouse.0.performance', 88)
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Team isolation
    // ──────────────────────────────────────────────────────────────────────

    public function test_sites_from_another_team_do_not_appear(): void
    {
        $userA = $this->createUserWithTeam();
        $teamB = Team::factory()->create();

        Site::factory()->create(['team_id' => $userA->team_id, 'alias' => 'team-a-site', 'primary_domain' => 'a.com']);
        Site::factory()->create(['team_id' => $teamB->id, 'alias' => 'team-b-site', 'primary_domain' => 'b.com']);

        $response = $this->actingAs($userA)->get(route('kpi-trends.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('sites', 1));
    }

    public function test_no_sites_for_user_without_team(): void
    {
        $user = User::factory()->create(['team_id' => null]);

        $response = $this->actingAs($user)->get(route('kpi-trends.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('sites', [])
            ->where('lighthouse', [])
        );
    }
}
