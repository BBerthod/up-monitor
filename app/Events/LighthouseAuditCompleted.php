<?php

namespace App\Events;

use App\Models\Monitor;
use App\Models\MonitorLighthouseScore;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LighthouseAuditCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Monitor $monitor,
        public MonitorLighthouseScore $score
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->monitor->team_id);
    }

    public function broadcastAs(): string
    {
        return 'lighthouse.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'monitor_id' => $this->monitor->id,
            'performance' => $this->score->performance,
            'accessibility' => $this->score->accessibility,
            'best_practices' => $this->score->best_practices,
            'seo' => $this->score->seo,
            'scored_at' => $this->score->scored_at->toIso8601String(),
        ];
    }
}
