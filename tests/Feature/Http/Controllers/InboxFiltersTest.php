<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightDomain;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    public function test_severity_filter_critical_returns_only_critical(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::CRITICAL->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::WARNING->value, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index', ['severity' => 'critical']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('items.total', 1)->where('items.data.0.severity', 'critical'));
    }

    public function test_severity_filter_info_includes_opportunity(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::INFO->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::OPPORTUNITY->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::WARNING->value, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index', ['severity' => 'info']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('items.total', 2));
    }

    public function test_domain_filter_returns_matching_types_only(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'type' => InsightType::SERVER_HEALTH->value, 'severity' => InsightSeverity::WARNING->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'type' => InsightType::STRIKING_DISTANCE->value, 'severity' => InsightSeverity::INFO->value, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index', ['domain' => InsightDomain::INFRASTRUCTURE->value]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('items.total', 1)->where('items.data.0.domain', 'infrastructure'));
    }

    public function test_site_filter_returns_insights_for_site_only(): void
    {
        $user = $this->userWithTeam();
        $siteA = Site::factory()->create(['team_id' => $user->team_id]);
        $siteB = Site::factory()->create(['team_id' => $user->team_id]);
        Insight::factory()->create(['team_id' => $user->team_id, 'site_id' => $siteA->id, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'site_id' => $siteB->id, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index', ['site' => $siteA->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('items.total', 1));
    }

    public function test_severity_and_domain_filters_are_composable(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'type' => InsightType::SERVER_HEALTH->value, 'severity' => InsightSeverity::CRITICAL->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'type' => InsightType::SERVER_HEALTH->value, 'severity' => InsightSeverity::WARNING->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'type' => InsightType::STRIKING_DISTANCE->value, 'severity' => InsightSeverity::CRITICAL->value, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index', ['severity' => 'critical', 'domain' => InsightDomain::INFRASTRUCTURE->value]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('items.total', 1));
    }

    public function test_invalid_severity_is_rejected(): void
    {
        $user = $this->userWithTeam();

        $this->actingAs($user)
            ->get(route('inbox.index', ['severity' => 'bad_value']))
            ->assertStatus(302);
    }
}
