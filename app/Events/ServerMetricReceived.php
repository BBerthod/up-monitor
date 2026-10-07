<?php

namespace App\Events;

use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerMetricReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Server $server,
        public ServerMetric $metric,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->server->team_id);
    }

    public function broadcastAs(): string
    {
        return 'server.metric.received';
    }

    public function broadcastWith(): array
    {
        return [
            'server' => [
                'id' => $this->server->id,
                'name' => $this->server->name,
            ],
            'metric' => [
                'cpu' => (float) $this->metric->cpu_percent,
                'ram' => (float) $this->metric->ram_percent,
                'disk' => (float) $this->metric->disk_percent,
                'load_avg_1' => $this->metric->load_avg_1 !== null ? (float) $this->metric->load_avg_1 : null,
                'captured_at' => $this->metric->captured_at->toIso8601String(),
            ],
        ];
    }
}
