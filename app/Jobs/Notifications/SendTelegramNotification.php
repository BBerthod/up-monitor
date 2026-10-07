<?php

namespace App\Jobs\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramNotification extends BaseNotificationJob
{
    protected function send(): void
    {
        $botToken = $this->channel->settings['bot_token'] ?? null;
        $chatId = $this->channel->settings['chat_id'] ?? null;

        if (! $botToken || ! $chatId) {
            Log::warning('Telegram channel missing settings', ['channel_id' => $this->channel->id]);

            return;
        }

        $statusEmoji = $this->event === 'down' ? "\u{1F534}" : "\u{1F7E2}";
        $statusText = $this->event === 'down' ? 'Down' : 'Up';
        $cause = ucfirst(str_replace('_', ' ', $this->incident->cause->value));
        $monitorName = htmlspecialchars($this->monitor->name, ENT_QUOTES, 'UTF-8');
        $monitorUrl = htmlspecialchars($this->monitor->url, ENT_QUOTES, 'UTF-8');

        $text = "<b>{$statusEmoji} [Up] {$monitorName} is {$statusText}</b>\n\n"
            ."<b>URL:</b> {$monitorUrl}\n"
            .($this->check ? "<b>Status Code:</b> {$this->check->status_code}\n<b>Response Time:</b> {$this->check->response_time_ms}ms\n" : '')
            ."<b>Cause:</b> {$cause}";

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$botToken}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]
        );

        if ($response->failed()) {
            Log::error('Telegram API error', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        $response->throw();
    }
}
