<?php

namespace App\Jobs\Notifications;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Support\CircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class BaseNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public NotificationChannel $channel,
        public string $event,
        public Monitor $monitor,
        public MonitorIncident $incident,
        public ?MonitorCheck $check = null
    ) {
        $this->onQueue('notifications');
    }

    abstract protected function send(): void;

    public function handle(): void
    {
        $circuitKey = "notification:{$this->channel->id}";

        if (CircuitBreaker::isOpen($circuitKey)) {
            Log::info('Notification skipped — circuit breaker open', [
                'channel_id' => $this->channel->id,
                'channel_type' => $this->channel->type->value,
                'event' => $this->event,
            ]);

            return;
        }

        try {
            $this->send();
            CircuitBreaker::recordSuccess($circuitKey);
        } catch (Throwable $e) {
            CircuitBreaker::recordFailure($circuitKey);
            throw $e;
        }

        $this->logSuccess();
    }

    public function failed(Throwable $e): void
    {
        Log::error('Notification delivery failed', [
            'channel_id' => $this->channel->id,
            'channel_type' => $this->channel->type->value,
            'event' => $this->event,
            'monitor_id' => $this->monitor->id,
            'error' => $e->getMessage(),
        ]);

        $this->logFailure($e->getMessage());
    }

    protected function buildPayload(): array
    {
        return [
            'monitor' => [
                'id' => $this->monitor->id,
                'name' => $this->monitor->name,
                'url' => $this->monitor->url,
            ],
            'event' => "monitor.{$this->event}",
            'incident' => [
                'id' => $this->incident->id,
                'cause' => $this->incident->cause->value,
                'started_at' => $this->incident->started_at->toIso8601String(),
            ],
            'check' => $this->check ? [
                'status_code' => $this->check->status_code,
                'response_time_ms' => $this->check->response_time_ms,
            ] : null,
        ];
    }

    protected function logSuccess(): void
    {
        NotificationLog::create([
            'notification_channel_id' => $this->channel->id,
            'monitor_id' => $this->monitor->id,
            'monitor_incident_id' => $this->incident->id,
            'event' => $this->event,
            'channel_type' => $this->channel->type->value,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    protected function logFailure(string $error): void
    {
        NotificationLog::create([
            'notification_channel_id' => $this->channel->id,
            'monitor_id' => $this->monitor->id,
            'monitor_incident_id' => $this->incident->id,
            'event' => $this->event,
            'channel_type' => $this->channel->type->value,
            'status' => 'failed',
            'error_message' => substr($error, 0, 1000),
            'sent_at' => now(),
        ]);
    }
}
