<?php

namespace App\Support;

/**
 * Normalises a monitor "url" value for de-duplication purposes.
 *
 * Two entries that only differ by host casing or a trailing slash target the
 * exact same content. Treating them as distinct monitors doubles every
 * outbound check against that content (PSI audits, HTTP checks, ...) for no
 * benefit — this was the root cause of the PSI `429 RESOURCE_EXHAUSTED` quota
 * exhaustion identified in the 2026-09-01 audit (e.g. "https://example.com"
 * and "https://example.com/" living as two separate monitors).
 *
 * Deliberately NOT normalised: scheme (http vs https) and a leading "www.".
 * Both are legitimate, separately-monitored targets in this project — a team
 * commonly runs one monitor on http:// and one on https://, or one on the
 * apex domain and one on www., specifically to catch a regression on either
 * variant independently (see HealthDropDeduplicationTest and
 * DispatchInsightsTest::test_www_prefix_and_bare_hostname_are_treated_as_the_same_site
 * — the SEO/insight layer already collapses them into one signal per
 * `site_id`, but each stays its own uptime-checked Monitor). Folding them
 * together here would silently block that pattern.
 *
 * This intentionally stays conservative: it does not touch query strings,
 * ports, or non-root path segments beyond stripping one trailing slash, so
 * it never merges two genuinely distinct pages together.
 */
class UrlNormalizer
{
    public static function normalize(string $url): string
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return '';
        }

        if (! str_contains($trimmed, '://')) {
            // Bare host/IP, as used by the ping/port/dns monitor types.
            return strtolower($trimmed);
        }

        $parts = parse_url($trimmed);

        if ($parts === false || empty($parts['host'])) {
            // Unparseable — fall back to a lowercase, slash-trimmed copy so
            // exact-case/slash duplicates are still caught even though we
            // can't fully normalise the value.
            return strtolower(rtrim($trimmed, '/'));
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $query = ! empty($parts['query']) ? '?'.$parts['query'] : '';

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }
}
