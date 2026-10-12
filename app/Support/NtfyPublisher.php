<?php

namespace App\Support;

use App\Exceptions\UnsafeUrlException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publishes a message to a ntfy topic (https://docs.ntfy.sh/publish/).
 *
 * Channel settings: topic (required), server (default ntfy.sh), token (optional
 * access token), oncall (bool).
 *
 * "On-call" is how a down alert gets through Android's Do Not Disturb: it is
 * sent with ntfy priority 5 (max/urgent). ntfy's Android app has one
 * notification channel per priority, and each can be set to "override Do Not
 * Disturb" (https://docs.ntfy.sh/subscribe/phone/). That override is a
 * one-time setting on the phone, not something the server can force: until the
 * app's "Max" channel is allowed to override DND, an urgent message still
 * stays silent.
 *
 * The message is sent as JSON to the server root (not as headers) so titles
 * with non-ASCII characters survive.
 */
final class NtfyPublisher
{
    public const DEFAULT_SERVER = 'https://ntfy.sh';

    public const PRIORITY_DEFAULT = 3;

    public const PRIORITY_HIGH = 4;

    public const PRIORITY_URGENT = 5;

    /**
     * Priority for a "down" alert on this channel: urgent when on-call, high otherwise.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function downPriority(array $settings): int
    {
        return filter_var($settings['oncall'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ? self::PRIORITY_URGENT
            : self::PRIORITY_HIGH;
    }

    /**
     * @param  array<string, mixed>  $settings  NotificationChannel::settings
     * @param  list<string>  $tags  ntfy emoji short codes
     */
    public static function publish(array $settings, string $title, string $message, int $priority, array $tags = [], ?string $click = null): void
    {
        $topic = $settings['topic'] ?? null;

        if (! is_string($topic) || $topic === '') {
            Log::warning('ntfy channel missing topic');

            return;
        }

        $server = rtrim((string) (($settings['server'] ?? null) ?: self::DEFAULT_SERVER), '/');

        try {
            UrlSafetyValidator::assertSafe($server);
        } catch (UnsafeUrlException $e) {
            Log::warning('ntfy server blocked by SSRF guard', ['reason' => $e->getMessage()]);

            return;
        }

        $body = array_filter([
            'topic' => $topic,
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'tags' => $tags ?: null,
            'click' => $click,
        ], fn ($value) => $value !== null);

        // No redirects: the SSRF guard only vets the configured server, so a
        // redirect to a private address must not be followed.
        $request = Http::timeout(10)->withoutRedirecting();

        if (! empty($settings['token'])) {
            $request = $request->withToken((string) $settings['token']);
        }

        $request->post($server.'/', $body)->throw();
    }
}
