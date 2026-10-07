<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\IncidentCause;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Team;
use App\Models\User;
use App\Services\DokployRepoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Feature tests for FixPromptController.
 *
 * DokployRepoResolver is mocked so no real Dokploy API calls are made.
 * Route-model binding respects team-scoped global scopes:
 *   - MonitorIncident uses ScopedByMonitorTeam → filters via monitor.team_id.
 *   - Insight uses ScopedByTeam → filters via team_id directly.
 * A cross-team access therefore returns 404 (binding fails before the policy runs).
 */
class FixPromptControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock resolver globally for every test — no real HTTP calls needed.
        $this->mock(DokployRepoResolver::class, function ($mock) {
            $mock->shouldReceive('resolveRepoForHost')
                ->andReturn([
                    'repo' => 'git@github.com:X/Y.git',
                    'app_name' => 'y',
                    'app_id' => '1',
                    'project_name' => 'X',
                    'branch' => 'main',
                    'all_domains' => ['example.com'],
                ]);
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Incident routes
    // ──────────────────────────────────────────────────────────────────────

    public function test_incident_fix_page_renders(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->create([
            'team_id' => $user->team_id,
            'url' => 'https://example.com',
        ]);
        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
        ]);

        $response = $this->actingAs($user)
            ->get(route('incidents.fix', $incident));

        $response->assertStatus(200);
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('FixPrompt')
                ->has('prompt')
        );
    }

    public function test_incident_fix_requires_auth(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['team_id' => $team->id]);
        $incident = MonitorIncident::factory()->create(['monitor_id' => $monitor->id]);

        $response = $this->get(route('incidents.fix', $incident));

        $response->assertRedirect(route('login'));
    }

    public function test_cannot_access_other_team_incident(): void
    {
        $userA = $this->createUserWithTeam();

        // Create incident on a different team.
        $teamB = Team::factory()->create();
        $monitorB = Monitor::factory()->create(['team_id' => $teamB->id]);
        $incidentB = MonitorIncident::factory()->create(['monitor_id' => $monitorB->id]);

        // ScopedByMonitorTeam filters via monitor.team_id for the authenticated
        // user (team A), so route-model binding returns 404 before the policy runs.
        $response = $this->actingAs($userA)
            ->get(route('incidents.fix', $incidentB->id));

        $response->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Insight routes
    // ──────────────────────────────────────────────────────────────────────

    public function test_insight_fix_page_renders(): void
    {
        $user = $this->createUserWithTeam();
        $insight = Insight::factory()->create([
            'team_id' => $user->team_id,
            'site' => 'https://example.com',
            'type' => InsightType::REVENUE_AT_RISK->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'title' => 'Test insight',
            'payload' => ['page' => '/test', 'status_code' => 404, 'clicks' => 100],
        ]);

        $response = $this->actingAs($user)
            ->get(route('insights.fix', $insight));

        $response->assertStatus(200);
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('FixPrompt')
                ->has('prompt')
        );
    }

    public function test_insight_fix_requires_auth(): void
    {
        $insight = Insight::factory()->create([
            'type' => InsightType::TRAFFIC_CHANGE->value,
            'severity' => InsightSeverity::INFO->value,
        ]);

        $response = $this->get(route('insights.fix', $insight));

        $response->assertRedirect(route('login'));
    }

    public function test_cannot_access_other_team_insight(): void
    {
        $userA = $this->createUserWithTeam();

        $teamB = Team::factory()->create();
        $insightB = Insight::factory()->create([
            'team_id' => $teamB->id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
            'severity' => InsightSeverity::INFO->value,
        ]);

        // ScopedByTeam filters by team_id → route-model binding returns 404.
        $response = $this->actingAs($userA)
            ->get(route('insights.fix', $insightB->id));

        $response->assertStatus(404);
    }
}
