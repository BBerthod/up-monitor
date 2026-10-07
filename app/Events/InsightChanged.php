<?php

namespace App\Events;

use App\Models\Insight;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast whenever an insight is created, acknowledged, or snoozed.
 *
 * Channel: private team.{team_id} — same pattern as ServerMetricReceived.
 * Payload is intentionally lean: the UI reacts by re-fetching or updating
 * a badge counter; it does not need the full insight body here.
 *
 * toOthers() is used on acknowledgement/snooze so the tab that issued the
 * action does not receive a redundant update (it already reflects the change).
 *
 * ShouldBroadcastNow (not ShouldBroadcast): a queued broadcast pushes a
 * BroadcastEvent job, polluting every Queue::fake()-based test that creates
 * insights — and a UI refresh signal gains nothing from the queue round-trip.
 */
class InsightChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $insightId,
        public readonly int $teamId,
        public readonly string $action,
        public readonly string $severity,
        public readonly string $domain,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->teamId);
    }

    public function broadcastAs(): string
    {
        return 'insight.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'insight_id' => $this->insightId,
            'action' => $this->action,
            'severity' => $this->severity,
            'domain' => $this->domain,
        ];
    }
}
