<?php

namespace App\Events;

use App\Models\IngestEvent;
use App\Models\IngestSource;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IngestEventReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public IngestSource $source,
        public IngestEvent $event
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->source->team_id);
    }

    public function broadcastAs(): string
    {
        return 'ingest.event.received';
    }

    public function broadcastWith(): array
    {
        return [
            'source_id' => $this->source->id,
            'source_name' => $this->source->name,
            'event_id' => $this->event->id,
            'type' => $this->event->type->value,
            'level' => $this->event->level->value,
            'message' => $this->event->message,
            'occurred_at' => $this->event->occurred_at->toIso8601String(),
        ];
    }
}
