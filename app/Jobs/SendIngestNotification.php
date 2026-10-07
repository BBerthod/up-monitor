<?php

namespace App\Jobs;

use App\Enums\ChannelType;
use App\Models\IngestEvent;
use App\Models\IngestSource;
use App\Models\NotificationChannel;
use App\Models\PushSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class SendIngestNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public NotificationChannel $channel,
        public IngestSource $source,
        public IngestEvent $event
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        match ($this->channel->type) {
            ChannelType::EMAIL => $this->sendEmail(),
            ChannelType::WEBHOOK => $this->sendWebhook(),
            ChannelType::SLACK => $this->sendSlack(),
            ChannelType::DISCORD => $this->sendDiscord(),
            ChannelType::PUSH => $this->sendPush(),
            ChannelType::TELEGRAM => $this->sendTelegram(),
        };
    }

    public function failed(Throwable $e): void
    {
        Log::error('Ingest notification delivery failed', [
            'channel_id' => $this->channel->id,
            'channel_type' => $this->channel->type->value,
            'source_id' => $this->source->id,
            'event_id' => $this->event->id,
            'level' => $this->event->level->value,
            'error' => $e->getMessage(),
        ]);
    }

    private function buildPayload(): array
    {
        return [
            'source' => [
                'id' => $this->source->id,
                'name' => $this->source->name,
            ],
            'event' => [
                'id' => $this->event->id,
                'type' => $this->event->type->value,
                'level' => $this->event->level->value,
                'message' => $this->event->message,
                'context' => $this->event->context,
                'occurred_at' => $this->event->occurred_at->toIso8601String(),
            ],
        ];
    }

    private function levelEmoji(): string
    {
        return match ($this->event->level->value) {
            'emergency' => "\u{1F6A8}",
            'critical' => "\u{1F534}",
            'error' => "\u{274C}",
            'warning' => "\u{26A0}\u{FE0F}",
            default => "\u{2139}\u{FE0F}",
        };
    }

    private function levelColor(): string
    {
        return match ($this->event->level->value) {
            'emergency', 'critical' => '#7F1D1D',
            'error' => '#DC2626',
            'warning' => '#D97706',
            default => '#2563EB',
        };
    }

    private function sendEmail(): void
    {
        $subject = sprintf(
            '[Up] %s — %s: %s',
            $this->source->name,
            strtoupper($this->event->level->value),
            $this->event->type->label()
        );

        $body = $this->buildEmailBody();

        Mail::raw($body, function ($message) use ($subject): void {
            $message->to($this->channel->settings['recipients'])
                ->subject($subject);
        });
    }

    private function buildEmailBody(): string
    {
        $body = sprintf(
            "Source: %s\nLevel: %s\nType: %s\nMessage: %s\nOccurred at: %s",
            $this->source->name,
            strtoupper($this->event->level->value),
            $this->event->type->label(),
            $this->event->message,
            $this->event->occurred_at->toIso8601String()
        );

        if ($this->event->context) {
            $body .= "\n\nContext:\n".json_encode($this->event->context, JSON_PRETTY_PRINT);
        }

        return $body;
    }

    private function sendWebhook(): void
    {
        Http::timeout(10)->post($this->channel->settings['url'], $this->buildPayload())->throw();
    }

    private function sendSlack(): void
    {
        $emoji = $this->levelEmoji();
        $color = $this->levelColor();
        $title = "{$emoji} [{$this->source->name}] {$this->event->level->label()}: {$this->event->type->label()}";

        $fields = [
            ['type' => 'mrkdwn', 'text' => "*Level:*\n{$this->event->level->label()}"],
            ['type' => 'mrkdwn', 'text' => "*Type:*\n{$this->event->type->label()}"],
        ];

        if ($this->event->context) {
            $contextSummary = implode(', ', array_map(
                fn ($k, $v) => "{$k}: ".(is_string($v) ? $v : json_encode($v)),
                array_keys(array_slice($this->event->context, 0, 3)),
                array_slice($this->event->context, 0, 3)
            ));
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Context:*\n`{$contextSummary}`"];
        }

        Http::timeout(10)->post($this->channel->settings['webhook_url'], [
            'attachments' => [[
                'color' => $color,
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => ['type' => 'plain_text', 'text' => $title, 'emoji' => true],
                    ],
                    [
                        'type' => 'section',
                        'text' => ['type' => 'mrkdwn', 'text' => $this->event->message],
                    ],
                    [
                        'type' => 'section',
                        'fields' => $fields,
                    ],
                ],
            ]],
        ])->throw();
    }

    private function sendDiscord(): void
    {
        $colorInt = match ($this->event->level->value) {
            'emergency', 'critical' => 8323072,
            'error' => 15158332,
            'warning' => 16750592,
            default => 2461868,
        };

        Http::timeout(10)->post($this->channel->settings['webhook_url'], [
            'embeds' => [[
                'title' => "[{$this->source->name}] {$this->event->level->label()}: {$this->event->type->label()}",
                'description' => $this->event->message,
                'color' => $colorInt,
                'fields' => [
                    ['name' => 'Source', 'value' => $this->source->name, 'inline' => true],
                    ['name' => 'Level', 'value' => $this->event->level->label(), 'inline' => true],
                    ['name' => 'Type', 'value' => $this->event->type->label(), 'inline' => true],
                ],
                'timestamp' => $this->event->occurred_at->toIso8601String(),
                'footer' => ['text' => config('app.name')],
            ]],
        ])->throw();
    }

    private function sendPush(): void
    {
        $auth = [
            'VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => config('services.webpush.vapid.public_key'),
                'privateKey' => config('services.webpush.vapid.private_key'),
            ],
        ];

        try {
            $webPush = new WebPush($auth);
        } catch (\Exception $e) {
            Log::error('WebPush initialization failed', ['error' => $e->getMessage()]);

            return;
        }

        $payload = json_encode([
            'title' => "[Up] {$this->source->name} — {$this->event->level->label()}",
            'body' => $this->event->message,
            'data' => ['url' => config('app.url').'/events'],
        ]);

        $userIds = $this->channel->team->users()->pluck('id');
        $subscriptions = PushSubscription::whereIn('user_id', $userIds)->get();

        foreach ($subscriptions as $dbSubscription) {
            $subscription = Subscription::create([
                'endpoint' => $dbSubscription->endpoint,
                'publicKey' => $dbSubscription->p256dh,
                'authToken' => $dbSubscription->auth,
            ]);

            $report = $webPush->sendOneNotification($subscription, $payload);

            if ($report->isSubscriptionExpired()) {
                $dbSubscription->delete();
            } elseif (! $report->isSuccess()) {
                Log::error('Push notification failed', [
                    'endpoint' => $dbSubscription->endpoint,
                    'reason' => $report->getReason(),
                ]);
            }
        }
    }

    private function sendTelegram(): void
    {
        $botToken = $this->channel->settings['bot_token'] ?? null;
        $chatId = $this->channel->settings['chat_id'] ?? null;

        if (! $botToken || ! $chatId) {
            \Log::warning('Telegram channel missing settings', ['channel_id' => $this->channel->id]);

            return;
        }

        $emoji = $this->levelEmoji();
        $sourceName = htmlspecialchars($this->source->name, ENT_QUOTES, 'UTF-8');
        $eventMessage = htmlspecialchars($this->event->message, ENT_QUOTES, 'UTF-8');

        $text = "<b>{$emoji} [{$sourceName}] {$this->event->level->label()}</b>\n\n"
            ."<b>Type:</b> {$this->event->type->label()}\n"
            ."<b>Message:</b> {$eventMessage}\n"
            ."<b>Occurred:</b> {$this->event->occurred_at->toIso8601String()}";

        if ($this->event->context) {
            $contextJson = htmlspecialchars(json_encode(array_slice($this->event->context, 0, 5), JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
            $text .= "\n\n<b>Context:</b>\n<code>{$contextJson}</code>";
        }

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
            Log::error('Telegram API error for ingest notification', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        $response->throw();
    }
}
