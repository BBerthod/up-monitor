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

class InboxGroupCountsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    public function test_group_severity_returns_all_severity_buckets(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::CRITICAL->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::WARNING->value, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index', ['group' => 'severity']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('groupCounts', count(InsightSeverity::cases()))
                ->where('group', 'severity')
            );
    }

    public function test_group_domain_returns_all_domain_buckets(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'type' => InsightType::SERVER_HEALTH->value, 'severity' => InsightSeverity::WARNING->value, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index', ['group' => 'domain']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('groupCounts', count(InsightDomain::cases()))
                ->where('group', 'domain')
            );
    }

    public function test_group_site_lists_sites_with_counts(): void
    {
        $user = $this->userWithTeam();
        $site = Site::factory()->create(['team_id' => $user->team_id]);
        Insight::factory()->count(2)->create(['team_id' => $user->team_id, 'site_id' => $site->id, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index', ['group' => 'site']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('groupCounts', 1)
                ->where('groupCounts.0.id', $site->id)
                ->where('groupCounts.0.count', 2)
            );
    }

    public function test_group_counts_reflect_full_open_set_even_when_filtered(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::CRITICAL->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::WARNING->value, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $response = $this->actingAs($user)
            ->get(route('inbox.index', ['severity' => 'critical', 'group' => 'severity']))
            ->assertOk();

        // Filtered list has 1 item.
        $response->assertInertia(fn ($p) => $p->where('items.total', 1));

        // groupCounts sums the FULL open set (2 items) across severity buckets.
        $response->assertInertia(function ($p) {
            $counts = collect($p->toArray()['props']['groupCounts']);
            $total = $counts->sum('count');
            $this->assertEquals(2, $total, 'groupCounts must total the full open set, not just the filtered page');
        });
    }

    public function test_group_defaults_to_severity(): void
    {
        $user = $this->userWithTeam();

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('group', 'severity'));
    }

    public function test_severity_counts_always_present_regardless_of_group(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::CRITICAL->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::WARNING->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::INFO->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::OPPORTUNITY->value, 'acknowledged_at' => null, 'snoozed_until' => null]);

        // group=domain — severityCounts must still be populated (not just when group=severity).
        $this->actingAs($user)
            ->get(route('inbox.index', ['group' => 'domain']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('severityCounts.critical', 1)
                ->where('severityCounts.warning', 1)
                // INFO + OPPORTUNITY both collapse into the 'info' bucket.
                ->where('severityCounts.info', 2)
            );
    }
}
