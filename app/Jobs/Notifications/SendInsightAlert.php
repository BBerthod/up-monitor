<?php

namespace App\Jobs\Notifications;

use App\Models\Insight;
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
 * Delivers a single SEO insight alert to one notification channel.
 *
 * Mirrors SendBusinessKpiNotification — same circuit-breaker key scheme
 * ("notification:{channel_id}"), same retry/backoff contract. Keeping the key
 * shared across notification job types is intentional: if a channel endpoint is
 * flapping, all downstream notification jobs for that channel back off together,
 * preventing cascading delivery storms.
 *
 * Dispatched by SeoAlertService after it marks insight.notified_at, so
 * re-delivery on retry does not re-mark the timestamp (idempotency lives in
 * the service, not this job).
 */
class SendInsightAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [30, 60, 120];

    /**
     * @param  string|null  $titleOverride  Title to display instead of the insight's own
     *                                      title (e.g. a "[Priority]" prefixed variant). Passed as a scalar because
     *                                      SerializesModels reloads $insight from the DB at execution time, which would
     *                                      otherwise discard any in-memory mutation of $insight->title.
     */
    public function __construct(
        public readonly NotificationChannel $channel,
        public readonly Insight $insight,
        public readonly ?string $titleOverride = null,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * The title to display — the override when provided, otherwise the insight's own.
     */
    /**
     * Deep link to the ready-to-paste Claude Code fix prompt for this insight.
     *
     * This is the first link of the alert→fix loop: without it every alert
     * ended at "something is wrong" and reaching the fix page meant opening
     * the app and hunting for the insight by hand.
     */
    private function fixUrl(): string
    {
        return route('insights.fix', $this->insight->id);
    }

    private function title(): string
    {
        return $this->titleOverride ?? $this->insight->title;
    }

    public function handle(): void
    {
        $circuitKey = "notification:{$this->channel->id}";

        if (CircuitBreaker::isOpen($circuitKey)) {
            Log::info('SEO insight alert skipped — circuit breaker open', [
                'channel_id' => $this->channel->id,
                'insight_id' => $this->insight->id,
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
        Log::error('SEO insight alert delivery failed', [
            'channel_id' => $this->channel->id,
            'channel_type' => $this->channel->type->value,
            'insight_id' => $this->insight->id,
            'site' => $this->insight->site,
            'severity' => $this->insight->severity->value,
            'error' => $e->getMessage(),
        ]);
    }

    private function send(): void
    {
        $settings = $this->channel->settings ?? [];

        match ($this->channel->type->value) {
            'slack' => $this->sendSlack($settings),
            'discord' => $this->sendDiscord($settings),
            'webhook' => $this->sendWebhook($settings),
            'telegram' => $this->sendTelegram($settings),
            'email' => $this->sendEmail($settings),
            default => Log::debug('SEO insight alert: channel type not yet implemented', [
                'type' => $this->channel->type->value,
            ]),
        };
    }

    /**
     * Slack color-coded attachment.
     * WARNING = orange (#f97316), CRITICAL = red (#dc2626), else grey.
     * Uses existing color() helper on InsightSeverity.
     */
    private function sendSlack(array $settings): void
    {
        $webhookUrl = $settings['webhook_url'] ?? null;
        if (! $webhookUrl) {
            return;
        }

        $color = $this->insight->severity->color();
        $fields = $this->buildSlackFields();

        Http::timeout(10)->post($webhookUrl, [
            'attachments' => [[
                'color' => $color,
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => ['type' => 'plain_text', 'text' => $this->title(), 'emoji' => true],
                    ],
                    [
                        'type' => 'section',
                        'fields' => $fields,
                    ],
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => '<'.$this->fixUrl().'|Fix with Claude →>',
                        ],
                    ],
                ],
            ]],
        ])->throw();
    }

    /**
     * Discord embed. Converts hex color to integer as Discord requires.
     */
    private function sendDiscord(array $settings): void
    {
        $webhookUrl = $settings['webhook_url'] ?? null;
        if (! $webhookUrl) {
            return;
        }

        // Discord requires color as a decimal integer (not hex string).
        $colorHex = ltrim($this->insight->severity->color(), '#');
        $colorInt = (int) hexdec($colorHex);

        Http::timeout(10)->post($webhookUrl, [
            'content' => $this->title(),
            'embeds' => [[
                'title' => 'Fix with Claude →',
                'url' => $this->fixUrl(),
                'color' => $colorInt,
                'fields' => $this->buildDiscordFields(),
            ]],
        ])->throw();
    }

    /**
     * Generic webhook: structured JSON with full insight data.
     */
    private function sendWebhook(array $settings): void
    {
        $url = $settings['url'] ?? null;
        if (! $url) {
            return;
        }

        Http::timeout(10)->post($url, [
            'event' => 'insight.alert',
            'message' => $this->title(),
            'insight' => [
                'id' => $this->insight->id,
                'site' => $this->insight->site,
                'type' => $this->insight->type->value,
                'severity' => $this->insight->severity->value,
                'title' => $this->title(),
                'payload' => $this->insight->payload,
                'detected_at' => $this->insight->detected_at?->toIso8601String(),
                'fix_url' => $this->fixUrl(),
            ],
        ])->throw();
    }

    /**
     * Telegram HTML message.
     * Settings schema: ['bot_token' => string, 'chat_id' => string|int]
     * Matches the schema used by SendTelegramNotification.
     */
    private function sendTelegram(array $settings): void
    {
        $botToken = $settings['bot_token'] ?? null;
        $chatId = $settings['chat_id'] ?? null;

        if (! $botToken || ! $chatId) {
            Log::warning('SEO insight Telegram alert: missing bot_token or chat_id', [
                'channel_id' => $this->channel->id,
            ]);

            return;
        }

        $severityLabel = htmlspecialchars($this->insight->severity->label(), ENT_QUOTES, 'UTF-8');
        $typeLabel = htmlspecialchars($this->insight->type->label(), ENT_QUOTES, 'UTF-8');
        $site = htmlspecialchars($this->insight->site, ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($this->title(), ENT_QUOTES, 'UTF-8');

        // Surface a key payload metric when available (delta_pct or current/previous pair).
        $extraLine = $this->buildTelegramPayloadLine();

        $fixUrl = htmlspecialchars($this->fixUrl(), ENT_QUOTES, 'UTF-8');

        $text = "<b>[Up Alert] {$title}</b>\n\n"
            ."<b>Site:</b> {$site}\n"
            ."<b>Type:</b> {$typeLabel}\n"
            ."<b>Severity:</b> {$severityLabel}\n"
            .$extraLine
            ."\n<a href=\"{$fixUrl}\">Fix with Claude &rarr;</a>";

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
            Log::error('Telegram API error (SEO insight alert)', [
                'channel_id' => $this->channel->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        $response->throw();
    }

    /**
     * Email delivery via InsightAlertMail.
     * Recipients come from channel settings — same key used by SendEmailNotification.
     */
    private function sendEmail(array $settings): void
    {
        $recipients = $settings['recipients'] ?? null;
        if (empty($recipients)) {
            Log::warning('Insight email alert: no recipients configured', [
                'channel_id' => $this->channel->id,
            ]);

            return;
        }

        \Illuminate\Support\Facades\Mail::to($recipients)
            ->send(new \App\Mail\InsightAlertMail($this->insight, $this->title()));
    }

    /**
     * Build Slack field array from insight data.
     * Always shows Site / Type / Severity.
     * Adds a payload delta line when delta_pct is present.
     */
    private function buildSlackFields(): array
    {
        $payload = $this->insight->payload ?? [];

        $fields = [
            ['type' => 'mrkdwn', 'text' => "*Site:*\n{$this->insight->site}"],
            ['type' => 'mrkdwn', 'text' => "*Type:*\n{$this->insight->type->label()}"],
            ['type' => 'mrkdwn', 'text' => "*Severity:*\n{$this->insight->severity->label()}"],
        ];

        if ($impacted = $this->impactedSitesField()) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*{$impacted['label']}:*\n{$impacted['value']}"];
        } elseif (isset($payload['delta_pct'])) {
            $delta = number_format((float) $payload['delta_pct'], 1);
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Change:*\n{$delta}%"];
        } elseif (isset($payload['current'], $payload['previous'])) {
            $before = self::formatPayloadValue($payload['previous']);
            $after = self::formatPayloadValue($payload['current']);
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Before → After:*\n{$before} → {$after}"];
        }

        return $fields;
    }

    /**
     * Build Discord embed fields (inline) from insight data.
     */
    private function buildDiscordFields(): array
    {
        $payload = $this->insight->payload ?? [];

        $fields = [
            ['name' => 'Site',     'value' => $this->insight->site,                    'inline' => true],
            ['name' => 'Type',     'value' => $this->insight->type->label(),            'inline' => true],
            ['name' => 'Severity', 'value' => $this->insight->severity->label(),        'inline' => true],
        ];

        if ($impacted = $this->impactedSitesField()) {
            $fields[] = ['name' => $impacted['label'], 'value' => $impacted['value'], 'inline' => false];
        } elseif (isset($payload['delta_pct'])) {
            $fields[] = ['name' => 'Change', 'value' => number_format((float) $payload['delta_pct'], 1).'%', 'inline' => true];
        } elseif (isset($payload['current'], $payload['previous'])) {
            $before = self::formatPayloadValue($payload['previous']);
            $after = self::formatPayloadValue($payload['current']);
            $fields[] = ['name' => 'Before → After', 'value' => "{$before} → {$after}", 'inline' => true];
        }

        return $fields;
    }

    /**
     * Returns an HTML line for Telegram showing the most informative payload metric,
     * or an empty string when no relevant data is found.
     */
    private function buildTelegramPayloadLine(): string
    {
        $payload = $this->insight->payload ?? [];

        // Server-health insights: surface the impacted sites so the operator
        // immediately knows which properties the resource pressure threatens.
        if (! empty($payload['sites']) && is_array($payload['sites'])) {
            $sites = htmlspecialchars(self::formatPayloadValue($payload['sites']), ENT_QUOTES, 'UTF-8');

            return "<b>Affects:</b> {$sites}\n";
        }

        if (isset($payload['delta_pct'])) {
            $delta = number_format((float) $payload['delta_pct'], 1);

            return "<b>Change:</b> {$delta}%\n";
        }

        if (isset($payload['current'], $payload['previous'])) {
            $prev = htmlspecialchars(self::formatPayloadValue($payload['previous']), ENT_QUOTES, 'UTF-8');
            $curr = htmlspecialchars(self::formatPayloadValue($payload['current']), ENT_QUOTES, 'UTF-8');

            return "<b>Before → After:</b> {$prev} → {$curr}\n";
        }

        return '';
    }

    /**
     * For server-health insights, a "Affects" field listing the impacted sites.
     * Returns null for non-server insights or when no sites are present.
     *
     * @return array{label: string, value: string}|null
     */
    private function impactedSitesField(): ?array
    {
        $payload = $this->insight->payload ?? [];

        if (empty($payload['sites']) || ! is_array($payload['sites'])) {
            return null;
        }

        return ['label' => 'Affects', 'value' => self::formatPayloadValue($payload['sites'])];
    }

    /**
     * Format an arbitrary payload value into a short, human-readable string.
     *
     * Insight payloads used to be scalar-or-list-of-strings ('delta_pct' => 12.3,
     * 'sites' => ['a.com', 'b.com']) which every channel builder above interpolated
     * or cast directly. PerfRegressionDetector introduced metric-shaped associative
     * arrays for 'previous'/'current' (e.g. {performance, lcp, cls, scored_at}) and
     * every one of those call sites broke: PHP's "Array to string conversion" warning
     * is promoted to an ErrorException by Laravel's error handler, so the job (and the
     * mail view, which hits the same shape) threw before anything was ever sent. This
     * silently killed every perf_regression alert on every channel.
     *
     * Public + static so resources/views/mail/insight-alert.blade.php can call it
     * directly and stay in lockstep with the Telegram/Slack/Discord rendering instead
     * of re-implementing the same formatting.
     */
    public static function formatPayloadValue(mixed $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (! is_array($value) || $value === []) {
            // Objects, resources, empty arrays, or anything else unforeseen — never
            // let a payload shape crash alert delivery.
            return 'n/a';
        }

        return array_is_list($value)
            ? self::formatValueList($value)
            : self::formatValueMap($value);
    }

    /**
     * Comma-joined list of values, capped at 5 with a "+N more" suffix.
     * Mirrors the previous payload['sites'] rendering.
     */
    private static function formatValueList(array $value): string
    {
        $items = array_map(
            static fn (mixed $item): string => self::formatPayloadValue($item),
            array_slice($value, 0, 5)
        );

        $more = count($value) > 5 ? ' +'.(count($value) - 5).' more' : '';

        return implode(', ', $items).$more;
    }

    /**
     * Short labels for metric keys we know about across detectors (PerfRegressionDetector,
     * etc.). Unknown keys fall back to a humanized version of the key itself.
     *
     * @var array<string, string>
     */
    private const METRIC_LABELS = [
        'performance' => 'perf',
        'lcp' => 'LCP',
        'cls' => 'CLS',
        'fcp' => 'FCP',
        'tbt' => 'TBT',
        'ttfb' => 'TTFB',
    ];

    /**
     * Bookkeeping keys that add noise to a one-line alert summary — the full insight
     * is a click away via the "Fix with Claude" link.
     *
     * @var list<string>
     */
    private const IGNORED_METRIC_KEYS = ['scored_at', 'detected_at', 'notified_at', 'acknowledged_at'];

    /**
     * "Label value" pairs for an associative array, capped at 4 populated entries.
     * LCP is rendered in seconds (payloads store it in milliseconds) since a raw
     * "16516" reads as a typo, not a Core Web Vital.
     */
    private static function formatValueMap(array $value): string
    {
        $parts = [];

        foreach ($value as $key => $item) {
            if (count($parts) >= 4) {
                break;
            }

            $key = (string) $key;

            if (in_array($key, self::IGNORED_METRIC_KEYS, true) || $item === null) {
                continue;
            }

            $label = self::METRIC_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
            $formatted = $key === 'lcp' && is_numeric($item)
                ? number_format(((float) $item) / 1000, 1).'s'
                : self::formatPayloadValue($item);

            $parts[] = "{$label} {$formatted}";
        }

        return $parts === [] ? 'n/a' : implode(', ', $parts);
    }
}
