<?php

namespace App\Jobs\Notifications;

use App\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWebhookNotification extends BaseNotificationJob
{
    protected function send(): void
    {
        $url = $this->channel->settings['url'] ?? null;
        if (! $url) {
            Log::warning('Webhook channel missing URL', ['channel_id' => $this->channel->id]);

            return;
        }

        try {
            UrlSafetyValidator::assertSafe($url);
        } catch (UnsafeUrlException $e) {
            Log::warning('Webhook URL blocked by SSRF guard', ['channel_id' => $this->channel->id, 'reason' => $e->getMessage()]);

            return;
        }

        $body = json_encode($this->buildPayload(), JSON_THROW_ON_ERROR);

        $headers = ['Content-Type' => 'application/json'];

        $secret = $this->channel->settings['secret'] ?? null;
        if (! empty($secret)) {
            $headers['X-Up-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        Http::timeout(10)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();
    }
}
