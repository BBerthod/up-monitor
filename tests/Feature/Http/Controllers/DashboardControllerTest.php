<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for DashboardController (UX v2 payload).
 *
 * Verifies:
 *   - Guests are redirected to login.
 *   - Users without a team receive an empty-state payload.
 *   - The response contains the expected top-level prop keys.
 *   - triage.items are serialised via InsightTriagePresenter (domain, fix_url, …).
 *   - triage.counts comes from TriageService (WARNING + CRITICAL only).
 *   - triage.breakdown separates warnings from info/opportunity.
 *   - domainHealth contains the four domain keys with correct sub-keys.
 *   - Team isolation: insights from another team are never surfaced.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    private function userWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // ── auth guard ────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    // ── basic structure ───────────────────────────────────────────────────────

    public function test_dashboard_contains_expected_top_level_props(): void
    {
        $user = $this->userWithTeam();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('triageFeed')
                ->has('triageFeed.items')
                ->has('triageFeed.counts')
                ->has('triageFeed.breakdown')
                ->has('domainHealth')
                ->has('domainHealth.availability')
                ->has('domainHealth.seo_business')
                ->has('domainHealth.infrastructure')
                ->has('domainHealth.alerting')
                ->has('healthPortfolio')
                ->has('slaTarget')
                ->has('slaCurrent')
                ->has('statusSummary')
                ->has('statusSummary.level')
                ->has('statusSummary.headline')
                ->has('generatedAt')
            );
    }

    public function test_status_summary_reports_all_up_when_no_open_issue(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        $monitor = \App\Models\Monitor::factory()->create(['team_id' => $team->id, 'is_active' => true]);
        \App\Models\MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'status' => 'up',
            'checked_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('statusSummary.level', 'ok')
                ->where('statusSummary.monitors_up', 1)
                ->where('statusSummary.monitors_total', 1)
            );
    }

    public function test_status_summary_reports_critical_when_a_monitor_is_down(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        $monitor = \App\Models\Monitor::factory()->create(['team_id' => $team->id, 'is_active' => true, 'name' => 'charlie']);
        \App\Models\MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'status' => 'down',
            'checked_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('statusSummary.level', 'critical')
                ->where('statusSummary.monitors_down', 1)
            );
    }

    public function test_health_portfolio_site_rows_expose_state_last_check_and_timeline(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        $monitor = \App\Models\Monitor::factory()->create(['team_id' => $team->id, 'is_active' => true]);
        \App\Models\MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'status' => 'up',
            'checked_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('healthPortfolio.sites', 1)
                ->where('healthPortfolio.sites.0.status', 'up')
                ->has('healthPortfolio.sites.0.last_checked_at')
                ->has('healthPortfolio.sites.0.uptime_30d')
                ->has('healthPortfolio.sites.0.timeline')
                ->where('healthPortfolio.timeline_days', 30)
            );
    }

    public function test_sla_props_use_team_target_and_current_month_uptime(): void
    {
        $team = \App\Models\Team::factory()->create(['sla_target' => 99.5]);
        $user = \App\Models\User::factory()->create(['team_id' => $team->id]);

        // A monitor so slaCurrent can be computed (not null)
        $monitor = \App\Models\Monitor::factory()->create(['team_id' => $team->id, 'is_active' => true]);
        \App\Models\MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'status' => 'up',
            'checked_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('slaTarget', 99.5)
                // json_encode flattens whole floats (100.0 → 100), so the type
                // can legitimately arrive as integer.
                ->whereType('slaCurrent', ['double', 'integer'])
            );
    }

    public function test_sla_current_is_null_when_team_has_no_monitors(): void
    {
        // Team exists but has no monitors → slaCurrent = null, slaTarget = 99.90 default.
        $user = $this->userWithTeam();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('slaTarget', 99.9)
                ->where('slaCurrent', null)
            );
    }

    public function test_sla_props_are_null_when_user_has_no_team(): void
    {
        $user = \App\Models\User::factory()->create(['team_id' => null]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('slaTarget', null)
                ->where('slaCurrent', null)
            );
    }

    public function test_user_without_team_receives_empty_state(): void
    {
        $user = User::factory()->create(['team_id' => null]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('triageFeed.counts.total', 0)
                ->where('triageFeed.items', [])
                ->where('healthPortfolio.sites', [])
            );
    }

    // ── triage section ────────────────────────────────────────────────────────

    public function test_triage_items_are_serialised_with_domain_and_urls(): void
    {
        $user = $this->userWithTeam();

        Insight::factory()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'acknowledged_at' => null,
            'snoozed_until' => null,
            'impact_score' => 90,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('triageFeed.items', 1)
                ->where('triageFeed.items.0.domain', 'infrastructure')
                ->has('triageFeed.items.0.fix_url')
                ->has('triageFeed.items.0.acknowledge_url')
                ->has('triageFeed.items.0.snooze_url')
                ->has('triageFeed.items.0.detected_at')
                ->has('triageFeed.items.0.impact_score')
            );
    }

    /**
     * Regression: the legacy string column `site` on Insight shadows an Eloquent
     * relation of the same name ($insight->site returns the hostname string, not a
     * Site model). The presenter must therefore read `linkedSite` (the renamed
     * BelongsTo) to surface site.id / site.name in the triage feed.
     */
    public function test_triage_item_linked_to_a_site_exposes_site_id_and_name(): void
    {
        $user = $this->userWithTeam();

        $site = Site::factory()->create(['team_id' => $user->team_id]);

        Insight::factory()->create([
            'team_id' => $user->team_id,
            'site_id' => $site->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::WARNING->value,
            'acknowledged_at' => null,
            'snoozed_until' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('triageFeed.items.0.site.id', $site->id)
                ->has('triageFeed.items.0.site.name')
            );
    }

    public function test_triage_item_replaces_a_raw_slug_title_with_the_type_label(): void
    {
        $user = $this->userWithTeam();

        Insight::factory()->create([
            'team_id' => $user->team_id,
            'site' => 'delta.example.net',
            'type' => InsightType::HEALTH_DROP->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'Health_drop detected on delta',
            'acknowledged_at' => null, 'snoozed_until' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('triageFeed.items.0.label', 'Health drop')
                ->where('triageFeed.items.0.display_title', 'Health drop — delta.example.net')
                // The raw title stays available: nothing is dropped from the payload.
                ->where('triageFeed.items.0.title', 'Health_drop detected on delta')
            );
    }

    public function test_triage_item_keeps_a_written_title(): void
    {
        $user = $this->userWithTeam();

        Insight::factory()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::CMP_MISSING->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'No consent platform detected on example.com',
            'acknowledged_at' => null, 'snoozed_until' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('triageFeed.items.0.display_title', 'No consent platform detected on example.com')
                ->where('triageFeed.items.0.label', 'Consent management missing')
            );
    }

    public function test_triage_counts_only_include_warning_and_critical(): void
    {
        $user = $this->userWithTeam();

        // 1 critical + 1 warning → total = 2
        Insight::factory()->create([
            'team_id' => $user->team_id,
            'severity' => InsightSeverity::CRITICAL->value,
            'acknowledged_at' => null, 'snoozed_until' => null,
        ]);
        Insight::factory()->create([
            'team_id' => $user->team_id,
            'severity' => InsightSeverity::WARNING->value,
            'acknowledged_at' => null, 'snoozed_until' => null,
        ]);
        // INFO insight — must NOT count towards badge total
        Insight::factory()->create([
            'team_id' => $user->team_id,
            'severity' => InsightSeverity::INFO->value,
            'acknowledged_at' => null, 'snoozed_until' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('triageFeed.counts.total', 2)
                ->where('triageFeed.counts.critical', 1)
            );
    }

    public function test_triage_breakdown_separates_warnings_from_info_opportunity(): void
    {
        $user = $this->userWithTeam();

        Insight::factory()->create([
            'team_id' => $user->team_id,
            'severity' => InsightSeverity::WARNING->value,
            'acknowledged_at' => null, 'snoozed_until' => null,
        ]);
        Insight::factory()->create([
            'team_id' => $user->team_id,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'acknowledged_at' => null, 'snoozed_until' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('triageFeed.breakdown.warnings', 1)
                ->where('triageFeed.breakdown.info_opportunity', 1)
            );
    }

    // ── domainHealth sub-keys ─────────────────────────────────────────────────

    public function test_availability_health_has_expected_keys(): void
    {
        $user = $this->userWithTeam();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('domainHealth.availability.monitors_total')
                ->has('domainHealth.availability.monitors_up')
                ->has('domainHealth.availability.incidents_open')
                ->has('domainHealth.availability.uptime_30d')
            );
    }

    public function test_infrastructure_health_reflects_server_metrics(): void
    {
        $user = $this->userWithTeam();

        $server = Server::factory()->create([
            'team_id' => $user->team_id,
            'is_active' => true,
        ]);
        ServerMetric::factory()->create([
            'server_id' => $server->id,
            'cpu_percent' => 10,
            'ram_percent' => 20,
            'disk_percent' => 55.5,
            'load_avg_1' => 0.5,
            'captured_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('domainHealth.infrastructure.servers_total', 1)
                ->where('domainHealth.infrastructure.servers_silent', 0)
                ->has('domainHealth.infrastructure.worst_server')
                ->where('domainHealth.infrastructure.worst_server.id', $server->id)
                ->where('domainHealth.infrastructure.worst_server.disk', 55.5)
            );
    }

    public function test_alerting_health_has_expected_keys(): void
    {
        $user = $this->userWithTeam();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('domainHealth.alerting.channels_active')
                ->has('domainHealth.alerting.failures_24h')
            );
    }

    // ── team isolation ────────────────────────────────────────────────────────

    public function test_triage_does_not_surface_other_team_insights(): void
    {
        $userA = $this->userWithTeam();
        $teamB = Team::factory()->create();

        // insight for team B — must not appear for user A
        Insight::factory()->create([
            'team_id' => $teamB->id,
            'severity' => InsightSeverity::CRITICAL->value,
            'acknowledged_at' => null, 'snoozed_until' => null,
            'impact_score' => 100,
        ]);

        $this->actingAs($userA)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('triageFeed.counts.total', 0)
                ->where('triageFeed.items', [])
            );
    }
}
