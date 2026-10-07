<?php

namespace Tests\Feature\Events;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Events\InsightChanged;
use App\Models\Insight;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InsightChangedTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    public function test_insight_changed_is_broadcast_on_creation(): void
    {
        Event::fake([InsightChanged::class]);

        $user = $this->userWithTeam();

        Insight::factory()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::WARNING->value,
            'acknowledged_at' => null,
            'snoozed_until' => null,
        ]);

        Event::assertDispatched(InsightChanged::class, function (InsightChanged $e) use ($user): bool {
            return $e->action === 'created'
                && $e->teamId === $user->team_id
                && $e->severity === 'warning'
                && $e->domain === 'infrastructure';
        });
    }

    public function test_insight_changed_is_broadcast_on_acknowledgement(): void
    {
        $user = $this->userWithTeam();

        $insight = Insight::factory()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::INFO->value,
            'acknowledged_at' => null,
        ]);

        Event::fake([InsightChanged::class]);

        $insight->acknowledge();

        Event::assertDispatched(InsightChanged::class, function (InsightChanged $e) use ($insight): bool {
            return $e->action === 'acknowledged'
                && $e->insightId === $insight->id
                && $e->domain === 'seo_business';
        });
    }

    public function test_insight_changed_is_not_broadcast_on_unrelated_update(): void
    {
        $user = $this->userWithTeam();

        $insight = Insight::factory()->create([
            'team_id' => $user->team_id,
            'acknowledged_at' => null,
            'snoozed_until' => null,
        ]);

        Event::fake([InsightChanged::class]);

        // Changing a non-watched field (title) must NOT trigger the event.
        $insight->update(['title' => 'Updated title']);

        Event::assertNotDispatched(InsightChanged::class);
    }

    public function test_insight_changed_broadcast_channel_is_private_team_channel(): void
    {
        $event = new InsightChanged(
            insightId: 1,
            teamId: 42,
            action: 'created',
            severity: 'critical',
            domain: 'infrastructure',
        );

        $channel = $event->broadcastOn();

        $this->assertStringContainsString('team.42', $channel->name);
    }

    public function test_insight_changed_broadcast_as_returns_correct_event_name(): void
    {
        $event = new InsightChanged(
            insightId: 1,
            teamId: 1,
            action: 'created',
            severity: 'warning',
            domain: 'seo_business',
        );

        $this->assertEquals('insight.changed', $event->broadcastAs());
    }

    public function test_insight_changed_payload_is_lean(): void
    {
        $event = new InsightChanged(
            insightId: 7,
            teamId: 3,
            action: 'snoozed',
            severity: 'warning',
            domain: 'availability',
        );

        $payload = $event->broadcastWith();

        $this->assertEquals([
            'insight_id' => 7,
            'action' => 'snoozed',
            'severity' => 'warning',
            'domain' => 'availability',
        ], $payload);
    }
}
