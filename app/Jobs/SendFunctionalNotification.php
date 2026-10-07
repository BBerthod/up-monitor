<?php

namespace App\Jobs;

use App\DTOs\FunctionalResult;
use App\Enums\ChannelType;
use App\Models\FunctionalCheck;
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
use Throwable;

class SendFunctionalNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public NotificationChannel $channel,
        public FunctionalCheck $check,
        public FunctionalResult $result,
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
            ChannelType::TELEGRAM => $this->sendTelegram(),
            ChannelType::PUSH => $this->sendPush(),
        };
    }

    public function failed(Throwable $e): void
    {
        Log::error('Functional notification delivery failed', [
            'channel_id' => $this->channel->id,
            'functional_check_id' => $this->check->id,
            'error' => $e->getMessage(),
        ]);
    }

    private function failedRules(): array
    {
        return array_filter($this->result->details, fn ($d) => ! ($d['passed'] ?? true));
    }

    private function summaryText(): string
    {
        $monitor = $this->check->monitor;
        $lines = [
            "Functional check failed: \"{$this->check->name}\"",
            "Monitor: {$monitor->name} ({$monitor->url})",
            "URL checked: {$this->check->resolveUrl()}",
        ];

        foreach ($this->failedRules() as $rule) {
            $lines[] = "• [{$rule['rule']}] {$rule['message']}";
        }

        if ($this->result->errorMessage) {
            $lines[] = "Error: {$this->result->errorMessage}";
        }

        return implode("\n", $lines);
    }

    private function sendEmail(): void
    {
        $subject = "[Up] Functional check failed: {$this->check->name}";

        Mail::raw($this->summaryText(), function ($mail) use ($subject) {
            $mail->to($this->channel->settings['recipients'])
                ->subject($subject);
        });
    }

    private function sendWebhook(): void
    {
        $monitor = $this->check->monitor;

        Http::timeout(10)->post($this->channel->settings['url'], [
            'event' => 'functional.failed',
            'monitor' => ['id' => $monitor->id, 'name' => $monitor->name, 'url' => $monitor->url],
            'check' => ['id' => $this->check->id, 'name' => $this->check->name, 'url' => $this->check->resolveUrl(), 'type' => $this->check->type->value],
            'failed_rules' => array_values($this->failedRules()),
            'error_message' => $this->result->errorMessage,
        ])->throw();
    }

    private function sendSlack(): void
    {
        $monitor = $this->check->monitor;

        Http::timeout(10)->post($this->channel->settings['webhook_url'], [
            'attachments' => [[
                'color' => '#DC2626',
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => ['type' => 'plain_text', 'text' => "[Up] Functional check failed: {$this->check->name}"],
                    ],
                    [
                        'type' => 'section',
                        'fields' => [
                            ['type' => 'mrkdwn', 'text' => "*Monitor:*\n{$monitor->name}"],
                            ['type' => 'mrkdwn', 'text' => "*URL:*\n{$this->check->resolveUrl()}"],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'text' => ['type' => 'mrkdwn', 'text' => "```{$this->summaryText()}```"],
                    ],
                ],
            ]],
        ])->throw();
    }

    private function sendDiscord(): void
    {
        $monitor = $this->check->monitor;
        $fields = [
            ['name' => 'Monitor', 'value' => $monitor->name, 'inline' => true],
            ['name' => 'Check',   'value' => $this->check->name, 'inline' => true],
            ['name' => 'URL',     'value' => $this->check->resolveUrl(), 'inline' => false],
        ];

        foreach (array_slice(array_values($this->failedRules()), 0, 5) as $rule) {
            $fields[] = ['name' => $rule['rule'], 'value' => $rule['message'], 'inline' => false];
        }

        Http::timeout(10)->post($this->channel->settings['webhook_url'], [
            'embeds' => [[
                'title' => "[Up] Functional check failed: {$this->check->name}",
                'color' => 15158332,
                'fields' => $fields,
                'timestamp' => now()->toIso8601String(),
                'footer' => ['text' => config('app.name')],
            ]],
        ])->throw();
    }

    private function sendTelegram(): void
    {
        $botToken = $this->channel->settings['bot_token'] ?? null;
        $chatId = $this->channel->settings['chat_id'] ?? null;

        if (! $botToken || ! $chatId) {
            \Log::warning('Telegram channel missing settings', ['channel_id' => $this->channel->id]);

            return;
        }

        $checkName = htmlspecialchars($this->check->name, ENT_QUOTES, 'UTF-8');
        $monitorName = htmlspecialchars($this->check->monitor->name, ENT_QUOTES, 'UTF-8');
        $checkUrl = htmlspecialchars($this->check->resolveUrl(), ENT_QUOTES, 'UTF-8');

        $text = "\u{1F534} <b>[Up] Functional check failed</b>\n\n"
            ."<b>Check:</b> {$checkName}\n"
            ."<b>Monitor:</b> {$monitorName}\n"
            ."<b>URL:</b> {$checkUrl}\n\n"
            .htmlspecialchars($this->summaryText());

        Http::timeout(10)->post(
            "https://api.telegram.org/bot{$botToken}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]
        )->throw();
    }

    private function sendPush(): void
    {
        $monitor = $this->check->monitor;
        $auth = [
            'VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => config('services.webpush.vapid.public_key'),
                'privateKey' => config('services.webpush.vapid.private_key'),
            ],
        ];

        try {
            $webPush = new \Minishlink\WebPush\WebPush($auth);
        } catch (\Exception $e) {
            Log::error('WebPush init failed', ['error' => $e->getMessage()]);

            return;
        }

        $payload = json_encode([
            'title' => "[Up] Functional check failed: {$this->check->name}",
            'body' => "Monitor: {$monitor->name}",
            'data' => ['url' => config('app.url')."/monitors/{$monitor->id}"],
        ]);

        $userIds = $this->channel->team->users()->pluck('id');
        $subscriptions = PushSubscription::whereIn('user_id', $userIds)->get();

        foreach ($subscriptions as $dbSubscription) {
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint' => $dbSubscription->endpoint,
                'publicKey' => $dbSubscription->p256dh,
                'authToken' => $dbSubscription->auth,
            ]);

            $report = $webPush->sendOneNotification($subscription, $payload);

            if ($report->isSubscriptionExpired()) {
                $dbSubscription->delete();
            }
        }
    }
}
