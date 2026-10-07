<?php

namespace App\Jobs\Notifications;

use App\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendDiscordNotification extends BaseNotificationJob
{
    protected function send(): void
    {
        $webhookUrl = $this->channel->settings['webhook_url'] ?? null;
        if (! $webhookUrl) {
            Log::warning('Discord channel missing webhook_url', ['channel_id' => $this->channel->id]);

            return;
        }

        try {
            UrlSafetyValidator::assertSafe($webhookUrl);
        } catch (UnsafeUrlException $e) {
            Log::warning('Discord webhook URL blocked by SSRF guard', ['channel_id' => $this->channel->id, 'reason' => $e->getMessage()]);

            return;
        }

        $color = $this->event === 'down' ? 15158332 : 3066993;
        $statusText = $this->event === 'down' ? 'Down' : 'Up';

        Http::timeout(10)->post($webhookUrl, [
            'embeds' => [[
                'title' => "[Up] {$this->monitor->name} is {$statusText}",
                'url' => $this->monitor->url,
                'color' => $color,
                'fields' => array_filter([
                    ['name' => 'URL', 'value' => $this->monitor->url, 'inline' => false],
                    $this->check ? ['name' => 'Status Code', 'value' => (string) $this->check->status_code, 'inline' => true] : null,
                    $this->check ? ['name' => 'Response Time', 'value' => "{$this->check->response_time_ms}ms", 'inline' => true] : null,
                    ['name' => 'Cause', 'value' => ucfirst(str_replace('_', ' ', $this->incident->cause->value)), 'inline' => true],
                ]),
                'timestamp' => now()->toIso8601String(),
                'footer' => ['text' => config('app.name')],
            ]],
        ])->throw();
    }
}
