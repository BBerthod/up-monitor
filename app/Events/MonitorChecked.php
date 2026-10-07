<?php

namespace App\Events;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MonitorChecked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Monitor $monitor,
        public MonitorCheck $check
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->monitor->team_id);
    }

    public function broadcastAs(): string
    {
        return 'monitor.checked';
    }

    public function broadcastWith(): array
    {
        return [
            'monitor' => [
                'id' => $this->monitor->id,
                'name' => $this->monitor->name,
                'url' => $this->monitor->url,
                'is_active' => $this->monitor->is_active,
            ],
            'check' => [
                'status' => $this->check->status->value,
                'response_time_ms' => $this->check->response_time_ms,
                'status_code' => $this->check->status_code,
                'checked_at' => $this->check->checked_at->toIso8601String(),
            ],
        ];
    }
}
