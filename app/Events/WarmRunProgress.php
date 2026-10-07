<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarmRunProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $teamId,
        public int $warmSiteId,
        public int $warmRunId,
        public int $urlsProcessed,
        public int $urlsTotal,
        public int $hits,
        public int $misses,
        public int $errors,
        public bool $completed = false,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("team.{$this->teamId}")];
    }

    public function broadcastAs(): string
    {
        return 'warm.run.progress';
    }
}
