<?php

namespace App\Jobs\Notifications;

use App\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendSlackNotification extends BaseNotificationJob
{
    protected function send(): void
    {
        $webhookUrl = $this->channel->settings['webhook_url'] ?? null;
        if (! $webhookUrl) {
            Log::warning('Slack channel missing webhook_url', ['channel_id' => $this->channel->id]);

            return;
        }

        try {
            UrlSafetyValidator::assertSafe($webhookUrl);
        } catch (UnsafeUrlException $e) {
            Log::warning('Slack webhook URL blocked by SSRF guard', ['channel_id' => $this->channel->id, 'reason' => $e->getMessage()]);

            return;
        }

        $color = $this->event === 'down' ? '#DC2626' : '#16A34A';
        $statusText = $this->event === 'down' ? 'Down' : 'Up';

        Http::timeout(10)->post($webhookUrl, [
            'attachments' => [[
                'color' => $color,
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => ['type' => 'plain_text', 'text' => "[Up] {$this->monitor->name} is {$statusText}", 'emoji' => true],
                    ],
                    [
                        'type' => 'section',
                        'fields' => [
                            ['type' => 'mrkdwn', 'text' => "*URL:*\n{$this->monitor->url}"],
                            ['type' => 'mrkdwn', 'text' => "*Cause:*\n{$this->incident->cause->value}"],
                        ],
                    ],
                    ...($this->check ? [[
                        'type' => 'section',
                        'fields' => [
                            ['type' => 'mrkdwn', 'text' => "*Status Code:*\n{$this->check->status_code}"],
                            ['type' => 'mrkdwn', 'text' => "*Response Time:*\n{$this->check->response_time_ms}ms"],
                        ],
                    ]] : []),
                ],
            ]],
        ])->throw();
    }
}
