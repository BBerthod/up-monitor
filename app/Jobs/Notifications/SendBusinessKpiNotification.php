<?php

namespace App\Jobs\Notifications;

use App\Models\BusinessKpiIncident;
use App\Models\NotificationChannel;
use App\Support\CircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches a business-KPI regression alert to a single notification channel.
 *
 * Unlike the monitor up/down notification jobs, this job does not extend
 * BaseNotificationJob because it carries different payload (BusinessKpiIncident
 * instead of Monitor+MonitorIncident). The same circuit-breaker pattern is applied.
 */
class SendBusinessKpiNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public readonly NotificationChannel $channel,
        public readonly string $message,
        public readonly BusinessKpiIncident $incident,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $circuitKey = "notification:{$this->channel->id}";

        if (CircuitBreaker::isOpen($circuitKey)) {
            Log::info('Business KPI notification skipped — circuit breaker open', [
                'channel_id' => $this->channel->id,
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
    }

    public function failed(Throwable $e): void
    {
        Log::error('Business KPI notification delivery failed', [
            'channel_id' => $this->channel->id,
            'incident_id' => $this->incident->id,
            'error' => $e->getMessage(),
        ]);
    }

    private function send(): void
    {
        $type = $this->channel->type;
        $settings = $this->channel->settings ?? [];

        match ($type->value) {
            'slack' => $this->sendSlack($settings),
            'discord' => $this->sendDiscord($settings),
            'webhook' => $this->sendWebhook($settings),
            'email' => $this->sendEmail($settings),
            default => Log::debug('Business KPI notification: channel type not yet implemented', ['type' => $type->value]),
        };
    }

    private function sendSlack(array $settings): void
    {
        $webhookUrl = $settings['webhook_url'] ?? null;
        if (! $webhookUrl) {
            return;
        }

        Http::timeout(10)->post($webhookUrl, [
            'attachments' => [[
                'color' => '#f97316',
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => ['type' => 'plain_text', 'text' => $this->message, 'emoji' => true],
                    ],
                    [
                        'type' => 'section',
                        'fields' => [
                            ['type' => 'mrkdwn', 'text' => "*Site:*\n{$this->incident->site}"],
                            ['type' => 'mrkdwn', 'text' => "*Metric:*\n{$this->incident->metric}"],
                            ['type' => 'mrkdwn', 'text' => "*Delta:*\n".number_format((float) $this->incident->delta_pct, 1).'%'],
                            ['type' => 'mrkdwn', 'text' => "*Severity:*\n".$this->incident->severity->label()],
                        ],
                    ],
                ],
            ]],
        ])->throw();
    }

    private function sendDiscord(array $settings): void
    {
        $webhookUrl = $settings['webhook_url'] ?? null;
        if (! $webhookUrl) {
            return;
        }

        Http::timeout(10)->post($webhookUrl, [
            'content' => $this->message,
            'embeds' => [[
                'color' => 16737570, // orange #FF7722
                'fields' => [
                    ['name' => 'Site', 'value' => $this->incident->site, 'inline' => true],
                    ['name' => 'Metric', 'value' => $this->incident->metric, 'inline' => true],
                    ['name' => 'Delta', 'value' => number_format((float) $this->incident->delta_pct, 1).'%', 'inline' => true],
                    ['name' => 'Severity', 'value' => $this->incident->severity->label(), 'inline' => true],
                ],
            ]],
        ])->throw();
    }

    private function sendWebhook(array $settings): void
    {
        $url = $settings['url'] ?? null;
        if (! $url) {
            return;
        }

        Http::timeout(10)->post($url, [
            'event' => 'business_kpi.regression',
            'message' => $this->message,
            'incident' => [
                'id' => $this->incident->id,
                'site' => $this->incident->site,
                'source' => $this->incident->source->value,
                'metric' => $this->incident->metric,
                'severity' => $this->incident->severity->value,
                'baseline_value' => $this->incident->baseline_value,
                'current_value' => $this->incident->current_value,
                'delta_pct' => $this->incident->delta_pct,
                'detected_at' => $this->incident->detected_at->toIso8601String(),
            ],
        ])->throw();
    }

    private function sendEmail(array $settings): void
    {
        // Email for business KPI alerts is handled via standard Laravel Mail.
        // In this minimal implementation we log — a proper Mailable can be added in Sprint 4.
        Log::info('Business KPI email notification (not yet implemented for email)', [
            'channel_id' => $this->channel->id,
            'message' => $this->message,
        ]);
    }
}
