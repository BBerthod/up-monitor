<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for InboxController.
 *
 * Verifies team isolation, open-only filter (ack + snoozed excluded),
 * pagination (25/page), and serialisation via InsightTriagePresenter.
 */
class InboxControllerTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('inbox.index'))->assertRedirect(route('login'));
    }

    public function test_inbox_returns_inertia_component_with_expected_props(): void
    {
        $user = $this->userWithTeam();

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inbox')
                ->has('items')
                ->has('counts')
            );
    }

    public function test_user_without_team_receives_empty_state(): void
    {
        $user = User::factory()->create(['team_id' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inbox')
                ->where('counts.total', 0)
            );
    }

    public function test_acknowledged_insights_are_excluded(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'acknowledged_at' => now(), 'snoozed_until' => null]);

        $this->actingAs($user)->get(route('inbox.index'))
            ->assertInertia(fn ($p) => $p->where('items.total', 0));
    }

    public function test_snoozed_insights_are_excluded(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'acknowledged_at' => null, 'snoozed_until' => now()->addDay()]);

        $this->actingAs($user)->get(route('inbox.index'))
            ->assertInertia(fn ($p) => $p->where('items.total', 0));
    }

    public function test_expired_snooze_is_visible(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'acknowledged_at' => null, 'snoozed_until' => now()->subMinute()]);

        $this->actingAs($user)->get(route('inbox.index'))
            ->assertInertia(fn ($p) => $p->where('items.total', 1));
    }

    public function test_items_are_paginated_with_page_size_25(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->count(30)->create(['team_id' => $user->team_id, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)->get(route('inbox.index'))
            ->assertInertia(fn ($p) => $p
                ->where('items.total', 30)
                ->where('items.per_page', 25)
                ->has('items.data', 25)
            );
    }

    public function test_items_are_serialised_with_domain_and_action_urls(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'acknowledged_at' => null,
            'snoozed_until' => null,
            'impact_score' => 75,
        ]);

        $this->actingAs($user)->get(route('inbox.index'))
            ->assertInertia(fn ($p) => $p
                ->has('items.data', 1)
                ->where('items.data.0.domain', 'infrastructure')
                ->where('items.data.0.severity', 'critical')
                ->has('items.data.0.fix_url')
                ->has('items.data.0.acknowledge_url')
                ->has('items.data.0.snooze_url')
            );
    }

    public function test_insights_from_other_teams_are_not_visible(): void
    {
        $userA = $this->userWithTeam();
        $teamB = Team::factory()->create();
        Insight::factory()->count(3)->create(['team_id' => $teamB->id, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($userA)->get(route('inbox.index'))
            ->assertInertia(fn ($p) => $p->where('items.total', 0));
    }

    /**
     * Regression: the legacy string column `site` on Insight shadows the Eloquent
     * relation — the presenter must read `linkedSite` (BelongsTo renamed to avoid the
     * collision) to populate site.id / site.name in the paginated inbox feed.
     */
    public function test_inbox_item_linked_to_a_site_exposes_site_id_and_name(): void
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

        $this->actingAs($user)->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('items.data', 1)
                ->where('items.data.0.site.id', $site->id)
                ->has('items.data.0.site.name')
            );
    }

    public function test_counts_badge_excludes_info_severity(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::CRITICAL->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::WARNING->value, 'acknowledged_at' => null, 'snoozed_until' => null]);
        Insight::factory()->create(['team_id' => $user->team_id, 'severity' => InsightSeverity::INFO->value, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)->get(route('inbox.index'))
            ->assertInertia(fn ($p) => $p
                ->where('counts.total', 2)
                ->where('counts.critical', 1)
            );
    }
}
