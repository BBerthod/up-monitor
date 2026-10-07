<?php

namespace App\Events;

use App\Models\MonitorIncident;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentResolved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MonitorIncident $incident
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->incident->monitor->team_id);
    }

    public function broadcastAs(): string
    {
        return 'incident.resolved';
    }

    public function broadcastWith(): array
    {
        return [
            'incident' => [
                'id' => $this->incident->id,
                'monitor_id' => $this->incident->monitor_id,
                'cause' => $this->incident->cause->value,
                'severity' => $this->incident->severity?->value ?? 'major',
                'started_at' => $this->incident->started_at->toIso8601String(),
                'resolved_at' => $this->incident->resolved_at?->toIso8601String(),
            ],
        ];
    }
}
