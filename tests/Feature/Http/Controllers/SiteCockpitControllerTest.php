<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorLighthouseScore;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for SiteController::show() cockpit payload.
 *
 * Covers:
 *  1. Full payload for site with monitors + checks + server + metrics + insights
 *  2. Site without server (infrastructure null-safe)
 *  3. Site without monitors (availability empty/safe)
 *  4. Team isolation — other team's site returns 404
 *  5. Timeline merged and sorted descending
 *  6. Insights passed through InsightTriagePresenter (site.name present)
 */
class SiteCockpitControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    private function createAuthUser(): User
    {
        $team = Team::factory()->create();
        $user = User::factory()->create([
            'team_id' => $team->id,
            'role' => 'member',
        ]);
        $this->actingAs($user);

        return $user;
    }

    // ──────────────────────────────────────────────────────────
    // 1. Full payload (monitors + server + checks + insights)
    // ──────────────────────────────────────────────────────────

    public function test_show_returns_full_cockpit_payload(): void
    {
        $user = $this->createAuthUser();
        $team = $user->team;
        $server = Server::factory()->for($team)->create(['name' => 'prod-server']);

        $site = Site::factory()->for($team)->create([
            'alias' => 'my-site',
            'primary_domain' => 'example.com',
            'server_id' => $server->id,
        ]);

        $monitor = Monitor::factory()->for($team)->create([
            'site_id' => $site->id,
            'name' => 'Main HTTP',
            'is_active' => true,
        ]);

        // 3 UP checks in the last 30 days
        MonitorCheck::factory()->count(3)->for($monitor)->create([
            'status' => CheckStatus::UP,
            'response_time_ms' => 120,
            'checked_at' => now()->subHours(1),
        ]);

        // One server metric
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 25.0,
            'ram_percent' => 50.0,
            'disk_percent' => 60.0,
            'load_avg_1' => 1.5,
            'captured_at' => now(),
        ]);

        // Lighthouse score
        MonitorLighthouseScore::factory()->for($monitor)->create([
            'performance' => 90,
            'seo' => 95,
            'accessibility' => 88,
            'best_practices' => 92,
            'scored_at' => now(),
        ]);

        // Open WARNING insight for this site
        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'severity' => InsightSeverity::WARNING->value,
            'type' => InsightType::HEALTH_DROP->value,
            'detected_at' => now(),
        ]);

        $response = $this->get(route('sites.show', $site));

        $response->assertStatus(200);
        $response->assertInertia(function ($page) use ($server, $site): void {
            // ── site header ───
            $page->where('site.id', $site->id)
                ->where('site.name', 'my-site')
                ->where('site.domain', 'example.com')
                ->where('site.server.id', $server->id)
                ->where('site.server.name', 'prod-server');

            // ── cockpit.availability ──
            $page->has('cockpit.availability.uptime_30d')
                ->has('cockpit.availability.incidents_30d')
                ->has('cockpit.availability.incidents_open')
                ->has('cockpit.availability.monitors')
                ->has('cockpit.availability.monitors.0.id')
                ->has('cockpit.availability.monitors.0.status')
                ->has('cockpit.availability.monitors.0.uptime_30d')
                ->has('cockpit.availability.monitors.0.response_ms');

            // ── cockpit.seo ──
            $page->has('cockpit.seo.lighthouse.performance')
                ->has('cockpit.seo.lighthouse.seo')
                ->has('cockpit.seo.lighthouse.scored_at');

            // ── cockpit.infrastructure ──
            $page->where('cockpit.infrastructure.server_metric.cpu', 25)
                ->where('cockpit.infrastructure.server_metric.ram', 50)
                ->where('cockpit.infrastructure.server_metric.disk', 60)
                ->where('cockpit.infrastructure.server_metric.load_1', 1.5)
                ->has('cockpit.infrastructure.cohosted_sites');

            // ── insights ──
            $page->has('insights', 1)
                ->has('insights.0.id')
                ->has('insights.0.severity')
                ->has('insights.0.title');

            // ── timeline ──
            $page->has('timeline');

            // ── health score ──
            $page->has('site.health')
                ->has('site.health.score')
                ->has('site.health.trend')
                ->where('site.health.score', fn ($v) => is_int($v) && $v >= 0 && $v <= 100);
        });
    }

    // ──────────────────────────────────────────────────────────
    // 2. Site without server — infrastructure null-safe
    // ──────────────────────────────────────────────────────────

    public function test_show_site_without_server_returns_null_infrastructure(): void
    {
        $user = $this->createAuthUser();
        $site = Site::factory()->for($user->team)->create([
            'server_id' => null,
        ]);

        $response = $this->get(route('sites.show', $site));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('site.server', null)
            ->where('cockpit.infrastructure.server_metric', null)
            ->where('cockpit.infrastructure.cohosted_sites', [])
        );
    }

    // ──────────────────────────────────────────────────────────
    // 3. Site without monitors — availability gracefully empty
    // ──────────────────────────────────────────────────────────

    public function test_show_site_without_monitors_returns_empty_availability(): void
    {
        $user = $this->createAuthUser();
        $site = Site::factory()->for($user->team)->create(['server_id' => null]);

        $response = $this->get(route('sites.show', $site));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('cockpit.availability.uptime_30d', null)
            ->where('cockpit.availability.incidents_30d', 0)
            ->where('cockpit.availability.incidents_open', 0)
            ->where('cockpit.availability.mttr_minutes', null)
            ->where('cockpit.availability.monitors', [])
            ->where('cockpit.availability.warming', null)
        );
    }

    // ──────────────────────────────────────────────────────────
    // 4. Team isolation — other team's site returns 404
    // ──────────────────────────────────────────────────────────

    public function test_show_other_team_site_returns_404(): void
    {
        $this->createAuthUser();

        $otherTeam = Team::factory()->create();
        $otherSite = Site::factory()->for($otherTeam)->create();

        $response = $this->get(route('sites.show', $otherSite));

        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────
    // 5. Timeline — merged + sorted descending
    // ──────────────────────────────────────────────────────────

    public function test_timeline_merges_incidents_and_insights_sorted_desc(): void
    {
        $user = $this->createAuthUser();
        $team = $user->team;
        $site = Site::factory()->for($team)->create(['server_id' => null]);
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        // Incident at T-5 days
        $incidentAt = now()->subDays(5);
        MonitorIncident::factory()->for($monitor)->create([
            'started_at' => $incidentAt,
            'cause' => IncidentCause::STATUS_CODE,
        ]);

        // Insight at T-2 days
        $insightAt = now()->subDays(2);
        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'detected_at' => $insightAt,
            'type' => InsightType::TRAFFIC_CHANGE->value,
            'severity' => InsightSeverity::INFO->value,
        ]);

        $response = $this->get(route('sites.show', $site));

        $response->assertStatus(200);
        $response->assertInertia(function ($page): void {
            $page->has('timeline', 2);

            // First item is more recent = insight (T-2 days)
            $page->where('timeline.0.kind', 'insight');

            // Second item = incident (T-5 days)
            $page->where('timeline.1.kind', 'incident');
        });
    }

    // ──────────────────────────────────────────────────────────
    // 6. Insights through presenter — site.name present
    // ──────────────────────────────────────────────────────────

    public function test_insights_contain_site_name_from_presenter(): void
    {
        $user = $this->createAuthUser();
        $team = $user->team;
        $site = Site::factory()->for($team)->create([
            'alias' => 'my-blog',
            'server_id' => null,
        ]);

        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'severity' => InsightSeverity::WARNING->value,
            'type' => InsightType::CONTENT_DECAY->value,
            'detected_at' => now(),
        ]);

        $response = $this->get(route('sites.show', $site));

        $response->assertStatus(200);
        $response->assertInertia(function ($page): void {
            $page->has('insights', 1);

            // The presenter exposes site.name (which maps to alias 'my-blog')
            $page->where('insights.0.site.name', 'my-blog');
        });
    }

    // ──────────────────────────────────────────────────────────
    // 7. Uptime per monitor via GROUP BY (no N+1 regression)
    // ──────────────────────────────────────────────────────────

    public function test_availability_contains_uptime_per_monitor(): void
    {
        $user = $this->createAuthUser();
        $team = $user->team;
        $site = Site::factory()->for($team)->create(['server_id' => null]);
        $monitor = Monitor::factory()->for($team)->create([
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        // 8 UP + 2 DOWN checks in last 30 days → 80% uptime
        MonitorCheck::factory()->count(8)->for($monitor)->create([
            'status' => CheckStatus::UP,
            'checked_at' => now()->subHours(2),
        ]);
        MonitorCheck::factory()->count(2)->for($monitor)->create([
            'status' => CheckStatus::DOWN,
            'checked_at' => now()->subHours(1),
        ]);

        $response = $this->get(route('sites.show', $site));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('cockpit.availability.monitors', 1)
            ->where('cockpit.availability.monitors.0.uptime_30d', 80)
        );
    }
}
