<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verify that MonitorController@show includes open insights scoped to the monitor.
 */
class MonitorInsightsTest extends TestCase
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

    public function test_guest_is_redirected_from_monitor_show(): void
    {
        $monitor = Monitor::factory()->for(Team::factory()->create())->create();

        $this->get(route('monitors.show', $monitor))->assertRedirect(route('login'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // insights prop — happy path
    // ──────────────────────────────────────────────────────────────────────

    public function test_monitor_show_includes_insights_prop(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->for($user->team)->create();

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('insights')
        );
    }

    public function test_insights_are_scoped_to_the_monitor(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->for($user->team)->create();
        $otherMonitor = Monitor::factory()->for($user->team)->create();

        // Insight for THIS monitor.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'title' => 'Rank #15 for "best widget"',
            'impact_score' => 80,
        ]);

        // Insight for ANOTHER monitor — must not appear.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'monitor_id' => $otherMonitor->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'title' => 'Other monitor insight',
            'impact_score' => 50,
        ]);

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('insights', 1)
            ->where('insights.0.title', 'Rank #15 for "best widget"')
        );
    }

    public function test_acknowledged_insights_are_excluded_from_monitor_show(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->for($user->team)->create();

        Insight::factory()->acknowledged()->create([
            'team_id' => $user->team_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('insights', []));
    }

    public function test_monitor_insights_capped_at_five(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->for($user->team)->create();

        Insight::factory()->unacknowledged()->count(7)->create([
            'team_id' => $user->team_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
        ]);

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('insights', 5));
    }

    public function test_monitor_insights_from_another_team_are_excluded(): void
    {
        $userA = $this->createUserWithTeam();
        $teamB = Team::factory()->create();
        $monitorB = Monitor::factory()->for($teamB)->create();

        // Insight on team B's monitor.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $teamB->id,
            'monitor_id' => $monitorB->id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $monitorA = Monitor::factory()->for($userA->team)->create();

        $response = $this->actingAs($userA)->get(route('monitors.show', $monitorA));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('insights', []));
    }
}
