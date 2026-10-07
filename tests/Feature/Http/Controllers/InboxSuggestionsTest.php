<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorLighthouseScore;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    public function test_suggestions_are_null_when_inbox_has_open_items(): void
    {
        $user = $this->userWithTeam();
        Insight::factory()->create(['team_id' => $user->team_id, 'acknowledged_at' => null, 'snoozed_until' => null]);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('suggestions', null));
    }

    public function test_suggestions_are_a_renderable_list_when_inbox_completely_empty(): void
    {
        // The page renders v-for over {label, url, count} objects, so the
        // prop must be a LIST of those — the earlier associative map shape
        // serialised to an object the block could not render.
        $user = $this->userWithTeam();

        Insight::factory()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'acknowledged_at' => now(),
        ]);
        Monitor::factory()->create(['team_id' => $user->team_id, 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('suggestions', 1)
                ->has('suggestions.0.label')
                ->has('suggestions.0.url')
                ->has('suggestions.0.count')
            );
    }

    public function test_suggestions_quick_wins_count_only_open_striking_distance(): void
    {
        $user = $this->userWithTeam();

        // Acknowledged STRIKING_DISTANCE — should NOT count.
        Insight::factory()->create(['team_id' => $user->team_id, 'type' => InsightType::STRIKING_DISTANCE->value, 'acknowledged_at' => now()]);

        // All insights acked → empty inbox → nothing actionable, so no
        // quick-wins suggestion appears (zero-count rows are filtered out).
        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('suggestions', []));
    }

    public function test_suggestions_stale_lighthouse_counts_monitors_without_recent_score(): void
    {
        $user = $this->userWithTeam();

        // Active monitor with no score at all — stale.
        Monitor::factory()->create(['team_id' => $user->team_id, 'is_active' => true]);

        // Active monitor with a score from 3 days ago — NOT stale.
        $recent = Monitor::factory()->create(['team_id' => $user->team_id, 'is_active' => true]);
        MonitorLighthouseScore::factory()->create(['monitor_id' => $recent->id, 'scored_at' => now()->subDays(3)]);

        // Inactive monitor without a score — excluded (not active).
        Monitor::factory()->create(['team_id' => $user->team_id, 'is_active' => false]);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('suggestions', 1)
                ->where('suggestions.0.count', 1)
                ->where('suggestions.0.label', 'Re-run stale Lighthouse audits')
            );
    }
}
