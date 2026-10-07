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
 * Tests for StrikingDistanceController.
 */
class StrikingDistanceControllerTest extends TestCase
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
        $this->get(route('striking-distance.index'))->assertRedirect(route('login'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Payload structure
    // ──────────────────────────────────────────────────────────────────────

    public function test_renders_striking_distance_page_with_required_props(): void
    {
        $user = $this->createUserWithTeam();

        $response = $this->actingAs($user)->get(route('striking-distance.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('StrikingDistance')
            ->has('items')
            ->has('counts')
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Type filter — only STRIKING_DISTANCE
    // ──────────────────────────────────────────────────────────────────────

    public function test_only_striking_distance_type_appears(): void
    {
        $user = $this->createUserWithTeam();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'title' => 'SD Insight',
            'impact_score' => 80,
        ]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'Non-SD insight',
            'impact_score' => 70,
        ]);

        $response = $this->actingAs($user)->get(route('striking-distance.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.title', 'SD Insight')
        );
    }

    public function test_all_severities_are_included(): void
    {
        $user = $this->createUserWithTeam();

        foreach ([
            InsightSeverity::OPPORTUNITY,
            InsightSeverity::INFO,
            InsightSeverity::WARNING,
            InsightSeverity::CRITICAL,
        ] as $severity) {
            Insight::factory()->unacknowledged()->create([
                'team_id' => $user->team_id,
                'type' => InsightType::STRIKING_DISTANCE->value,
                'severity' => $severity->value,
                'impact_score' => 50,
            ]);
        }

        $response = $this->actingAs($user)->get(route('striking-distance.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('items.data', 4));
    }

    public function test_acknowledged_insights_are_excluded(): void
    {
        $user = $this->createUserWithTeam();

        Insight::factory()->acknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
        ]);

        $response = $this->actingAs($user)->get(route('striking-distance.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('items.data', 0));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Pagination
    // ──────────────────────────────────────────────────────────────────────

    public function test_items_are_paginated_to_25_per_page(): void
    {
        $user = $this->createUserWithTeam();

        Insight::factory()->unacknowledged()->count(30)->create([
            'team_id' => $user->team_id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'impact_score' => 50,
        ]);

        $response = $this->actingAs($user)->get(route('striking-distance.index'));

        $response->assertOk();
        // First page must have exactly 25 items.
        $response->assertInertia(fn ($page) => $page->has('items.data', 25));
    }

    public function test_items_are_sorted_by_impact_score_descending(): void
    {
        $user = $this->createUserWithTeam();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'title' => 'Low impact',
            'impact_score' => 10,
        ]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'title' => 'High impact',
            'impact_score' => 90,
        ]);

        $response = $this->actingAs($user)->get(route('striking-distance.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('items.data.0.title', 'High impact')
            ->where('items.data.1.title', 'Low impact')
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Team isolation
    // ──────────────────────────────────────────────────────────────────────

    public function test_insights_from_another_team_do_not_appear(): void
    {
        $userA = $this->createUserWithTeam();
        $teamB = Team::factory()->create();

        Insight::factory()->unacknowledged()->create([
            'team_id' => $teamB->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
        ]);

        $response = $this->actingAs($userA)->get(route('striking-distance.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('items.data', 0));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Site-scope lens
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_scope_lens_filters_by_site_id(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->create(['team_id' => $user->team_id]);
        $otherSite = Site::factory()->create(['team_id' => $user->team_id]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'site_id' => $site->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'title' => 'Scoped site insight',
            'impact_score' => 80,
        ]);

        Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'site_id' => $otherSite->id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'title' => 'Other site insight',
            'impact_score' => 70,
        ]);

        // Activate the site-scope lens via session.
        $this->actingAs($user)
            ->withSession(['site_scope' => ['mode' => 'site', 'site_id' => $site->id]])
            ->get(route('striking-distance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.title', 'Scoped site insight')
            );
    }
}
