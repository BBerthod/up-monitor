<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\PageMetric;
use App\Support\UrlSafetyValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies that outbound affiliate redirects (/go/{ASIN} and friends) actually
 * reach the merchant — the single failure mode that silently zeroes revenue.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Production ran for several days with 100% of /go/{ASIN} links returning 404
 * across three sites. Nothing caught it:
 *
 *  - Uptime monitors probe the site root, which stayed 200.
 *  - BrokenPageService probes pages known to GSC. A redirect endpoint has no
 *    impressions of its own, so it is never in page_metrics and never probed.
 *  - AffiliateAuditService only inspects links whose HOST is amazon.* — a /go/
 *    link points at the site's OWN domain, so it is filtered out before any
 *    check happens.
 *
 * The content pages rendered perfectly the whole time. Only the outbound hop
 * was dead, and the outbound hop is where the money is. This service closes
 * that gap by walking the redirect itself.
 *
 * HOW IT WORKS
 * ────────────
 * 1. Fetch a sample of the site's highest-traffic pages (page_metrics).
 * 2. Extract INTERNAL links matching the configured redirect pattern
 *    (default: a /go/ path segment) — deliberately the mirror image of
 *    AffiliateAuditService, which keeps only EXTERNAL Amazon links.
 * 3. Follow each redirect WITHOUT letting the client resolve it, so we observe
 *    the hop itself rather than the merchant's response.
 * 4. Flag anything that fails to hand off to a merchant domain.
 *
 * WHY NOT FOLLOW REDIRECTS
 * ────────────────────────
 * Following would conflate two very different failures: "the redirect is
 * broken" (our bug, actionable) and "Amazon returned 503" (their problem,
 * transient). We want the first. Reading the Location header directly also
 * means one request per link instead of two, and never sends traffic to the
 * merchant — which would pollute affiliate click statistics with bot hits.
 *
 * SEVERITY
 * ────────
 * A broken redirect is CRITICAL as soon as the failure rate crosses the
 * configured ratio: unlike a single broken page, one dead rewrite rule usually
 * breaks EVERY affiliate link on the site at once. Below that ratio it is a
 * WARNING — likely a handful of stale ASINs rather than a systemic break.
 */
class BrokenRedirectService
{
    /** User-Agent string for redirect probe requests. */
    private const USER_AGENT = 'Up-Monitor/1.0 (+https://github.com/BBerthod/up-monitor)';

    /** Maximum response body inspected for the small browser-proof page. */
    private const MAX_BROWSER_PROOF_BYTES = 16 * 1024;

    public function __construct(private readonly KpiCollector $kpiCollector) {}

    /**
     * Probe affiliate redirects for this monitor and raise an insight when they
     * fail to hand off to a merchant.
     *
     * @return int Number of AFFILIATE_REDIRECT_BROKEN insights newly created (0 or 1).
     *             Repeated findings refresh the open insight in place.
     */
    public function detectForMonitor(Monitor $monitor): int
    {
        $site = $this->kpiCollector->siteNameFromUrl($monitor->url);

        // Only audit sites that actually do affiliate business. Mirrors the
        // merchant_domains guard in AffiliateAuditService.
        if (! $this->hasAffiliateBusiness($monitor)) {
            return 0;
        }

        $maxPages = (int) config('monitoring.affiliate_redirects.max_pages_per_monitor', 10);
        $maxLinks = (int) config('monitoring.affiliate_redirects.max_links_per_run', 25);
        $minSample = (int) config('monitoring.affiliate_redirects.min_sample', 3);

        $pages = PageMetric::where('site', $site)
            ->whereIn('id', function ($sub) use ($site): void {
                $sub->selectRaw('MAX(id)')
                    ->from('page_metrics')
                    ->where('site', $site)
                    ->groupBy('page');
            })
            ->orderByDesc('impressions')
            ->limit($maxPages)
            ->get();

        if ($pages->isEmpty()) {
            return 0;
        }

        $redirectUrls = $this->collectRedirectUrls($pages->pluck('page')->all(), $monitor, $maxLinks);

        if ($redirectUrls === []) {
            Log::info('BrokenRedirectService: no affiliate redirects found', [
                'monitor_id' => $monitor->id,
                'site' => $site,
                'pages_scanned' => $pages->count(),
            ]);

            return 0;
        }

        $broken = [];
        $tested = 0;

        foreach ($redirectUrls as $url => $sourcePage) {
            $verdict = $this->probeRedirect($url, $monitor, $sourcePage);

            if ($verdict === null) {
                continue;
            }

            $tested++;

            if ($verdict['broken']) {
                $broken[] = $verdict;
            }
        }

        // Fewer conclusive probes than the configured minimum is not evidence
        // either way. In particular, do not erase an existing alert just because
        // bot protection or network failures made this run inconclusive.
        if ($tested < $minSample) {
            Log::info('BrokenRedirectService: affiliate redirect sample is inconclusive', [
                'monitor_id' => $monitor->id,
                'site' => $site,
                'tested' => $tested,
                'min_sample' => $minSample,
            ]);

            return 0;
        }

        if ($broken === []) {
            Log::info('BrokenRedirectService: all redirects healthy', [
                'monitor_id' => $monitor->id,
                'site' => $site,
                'tested' => $tested,
            ]);

            // Match ServerHealthDetector's auto-resolution convention: a
            // confirmed recovery is closed by acknowledging it. Keeping the row
            // preserves its history, while a future break gets a fresh insight.
            $this->openInsight($monitor)?->acknowledge();

            return 0;
        }

        $failureRatio = count($broken) / $tested;
        $systemicRatio = (float) config('monitoring.affiliate_redirects.systemic_failure_ratio', 0.5);

        $severity = $failureRatio >= $systemicRatio
            ? InsightSeverity::CRITICAL
            : InsightSeverity::WARNING;

        $title = $failureRatio >= $systemicRatio
            ? sprintf(
                'Affiliate redirects broken: %d of %d tested links fail to reach the merchant',
                count($broken),
                $tested,
            )
            : sprintf(
                'Affiliate redirects failing: %d of %d tested links are broken',
                count($broken),
                $tested,
            );

        $attributes = [
            'team_id' => $monitor->team_id,
            'site' => $site,
            'site_id' => $monitor->site_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => [
                'tested' => $tested,
                'broken' => count($broken),
                'failure_ratio' => round($failureRatio, 3),
                // Cap the sample so the payload stays readable in the inbox; the
                // count above already conveys the scale.
                'samples' => array_slice($broken, 0, 5),
            ],
            // Scale with both breadth and severity of the break: a systemic failure
            // must outrank an ordinary broken page (whose score is raw monthly clicks).
            'impact_score' => round($failureRatio * 100, 2),
        ];

        $existing = $this->openInsight($monitor);

        if ($existing !== null) {
            // Deliberately update only mutable finding details. Notification,
            // snooze and first-detection state belong to the incident lifecycle.
            $existing->update([
                'title' => $attributes['title'],
                'severity' => $attributes['severity'],
                'payload' => $attributes['payload'],
                'impact_score' => $attributes['impact_score'],
            ]);
        } else {
            Insight::create(array_merge($attributes, ['detected_at' => now()]));
        }

        Log::warning('BrokenRedirectService: broken affiliate redirects detected', [
            'monitor_id' => $monitor->id,
            'site' => $site,
            'tested' => $tested,
            'broken' => count($broken),
        ]);

        return $existing === null ? 1 : 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * True when this monitor's site declares affiliate merchants, so probing
     * outbound redirects is meaningful.
     */
    private function hasAffiliateBusiness(Monitor $monitor): bool
    {
        $merchants = $monitor->site?->merchant_domains;

        return is_array($merchants) && $merchants !== [];
    }

    /** Return the oldest open redirect insight for this monitor, if any. */
    private function openInsight(Monitor $monitor): ?Insight
    {
        return Insight::openUnacknowledgedOfType(
            InsightType::AFFILIATE_REDIRECT_BROKEN,
            fn ($query) => $query->where('monitor_id', $monitor->id),
        )
            ->oldest('detected_at')
            ->first();
    }

    /**
     * Fetch the given pages and collect internal affiliate-redirect URLs found
     * in their markup, de-duplicated and capped.
     *
     * Each URL is mapped to the page it was found on, because the probe must
     * present that page as its Referer to look like a genuine click.
     *
     * @param  array<int, string>  $pages
     * @return array<string, string> redirect URL => source page URL
     */
    private function collectRedirectUrls(array $pages, Monitor $monitor, int $maxLinks): array
    {
        $pattern = (string) config('monitoring.affiliate_redirects.path_pattern', '#/go/[A-Za-z0-9]+#i');
        $found = [];

        foreach ($pages as $page) {
            if (count($found) >= $maxLinks) {
                break;
            }

            if (! UrlSafetyValidator::isSafe($page)) {
                continue;
            }

            try {
                $response = Http::timeout(15)
                    ->connectTimeout(10)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->get($page);

                if (! $response->successful()) {
                    continue;
                }

                // Entity-decode before matching so &amp; in hrefs does not split URLs,
                // mirroring the contract documented in AffiliateAuditService.
                $html = html_entity_decode($response->body(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                foreach ($this->extractRedirectLinks($html, $page, $pattern) as $url) {
                    $found[$url] = $page;

                    if (count($found) >= $maxLinks) {
                        break;
                    }
                }
            } catch (ConnectionException $e) {
                Log::debug('BrokenRedirectService: page fetch failed', [
                    'monitor_id' => $monitor->id,
                    'page' => $page,
                    'exception' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::error('BrokenRedirectService: unexpected error scanning page', [
                    'monitor_id' => $monitor->id,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $found;
    }

    /**
     * Extract internal affiliate-redirect links from HTML.
     *
     * Relative hrefs are resolved against the page URL, since WordPress themes
     * commonly emit "/go/B01ABCDEFG" rather than an absolute URL.
     *
     * IMPORTANT: the URL is taken verbatim from the markup, query string
     * included. A redirect rule can be broken by exactly the suffix a plugin
     * appends (a trailing "&keywords=…" defeated a rewrite in production), so
     * reconstructing a "clean" URL here would test something the visitor never
     * clicks and report a false pass.
     *
     * @return array<int, string>
     */
    private function extractRedirectLinks(string $html, string $pageUrl, string $pattern): array
    {
        if (! preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches)) {
            return [];
        }

        $base = parse_url($pageUrl);

        if ($base === false || empty($base['host'])) {
            return [];
        }

        $origin = ($base['scheme'] ?? 'https').'://'.$base['host'];
        $links = [];

        foreach ($matches[1] as $href) {
            $href = trim($href);

            if ($href === '' || ! preg_match($pattern, $href)) {
                continue;
            }

            // Resolve relative hrefs; keep absolute ones only when they stay on
            // this site (an external /go/ URL belongs to someone else).
            if (str_starts_with($href, '/')) {
                $links[] = $origin.$href;

                continue;
            }

            $parsed = parse_url($href);

            if ($parsed !== false && ($parsed['host'] ?? null) === $base['host']) {
                $links[] = $href;
            }
        }

        return $links;
    }

    /**
     * Probe a single redirect and decide whether it hands off to a merchant.
     *
     * PROBE AS A VISITOR, NOT AS A CRAWLER
     * ────────────────────────────────────
     * Affiliate redirects sit behind bot protection, because every bot that
     * follows one is counted by the merchant as a click and wrecks the
     * click/conversion ratio. This fleet's own /go/ handler answers 204 to any
     * request that lacks a same-site Referer AND same-site Fetch Metadata.
     *
     * A bare GET therefore looks exactly like the traffic the guard exists to
     * stop, and every healthy link reports as broken — which is precisely what
     * happened: four sites alerted "25 of 25 broken" while a real click on the
     * same URL returned a correct 302 to the merchant.
     *
     * So the probe reproduces a genuine click: the source page as Referer plus
     * the Sec-Fetch-* triplet a browser sends on a same-origin navigation. This
     * still never reaches the merchant — withoutRedirecting() stops at the hop.
     * A small 200 page may first ask a browser to set a cookie and revisit the
     * /go/ URL. We recognise that narrow proof, replay it once on the same host,
     * and still stop before following any merchant redirect.
     *
     * @param  string  $sourcePage  Page the link was found on, sent as Referer.
     * @return array{url: string, status: int|null, location: string|null, reason: string, broken: bool}|null
     *                                                                                                        Null when the verdict is indeterminate: unsafe URL, transport failure we
     *                                                                                                        cannot attribute, or a bot-protection refusal (see the 204 case below).
     */
    private function probeRedirect(string $url, Monitor $monitor, string $sourcePage): ?array
    {
        if (! UrlSafetyValidator::isSafe($url)) {
            return null;
        }

        try {
            // withoutRedirecting(): we want to inspect the hop, not resolve it.
            // See the class docblock for why.
            $response = Http::withoutRedirecting()
                ->timeout(15)
                ->connectTimeout(10)
                ->withHeaders($this->probeHeaders($sourcePage))
                ->get($url);

            $status = $response->status();

            if ($status === 200 && ($proof = $this->extractBrowserProof($response->body())) !== null) {
                $target = $this->resolveProofTarget($proof['target'], $url);

                if ($target === null || ! $this->hasSameHost($url, $target) || ! UrlSafetyValidator::isSafe($target)) {
                    return null;
                }

                $proofResponse = Http::withoutRedirecting()
                    ->timeout(15)
                    ->connectTimeout(10)
                    ->withHeaders($this->probeHeaders(
                        $url,
                        $proof['cookie_name'].'='.$proof['cookie_value'],
                    ))
                    ->get($target);

                // Never solve recursively: repeated browser proof is not enough
                // evidence to call the affiliate redirect healthy or broken.
                if ($proofResponse->status() === 200
                    && $this->extractBrowserProof($proofResponse->body()) !== null) {
                    return null;
                }

                return $this->verdictForResponse($proofResponse, $url, $monitor);
            }

            return $this->verdictForResponse($response, $url, $monitor);
        } catch (ConnectionException $e) {
            Log::info('BrokenRedirectService: redirect probe connection failed', [
                'monitor_id' => $monitor->id,
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('BrokenRedirectService: unexpected error probing redirect', [
                'monitor_id' => $monitor->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build browser-like headers for an affiliate redirect probe.
     *
     * @return array<string, string>
     */
    private function probeHeaders(string $referer, ?string $cookie = null): array
    {
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Referer' => $referer,
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Site' => 'same-origin',
        ];

        $monitorToken = config('monitoring.affiliate_redirects.monitor_token');

        if (is_string($monitorToken) && $monitorToken !== '') {
            $headers['X-Wk-Monitor'] = $monitorToken;
        }

        if ($cookie !== null) {
            $headers['Cookie'] = $cookie;
        }

        return $headers;
    }

    /**
     * Extract the deliberately narrow JavaScript cookie-and-revisit proof.
     *
     * @return array{cookie_name: string, cookie_value: string, target: string}|null
     */
    private function extractBrowserProof(string $body): ?array
    {
        if (strlen($body) > self::MAX_BROWSER_PROOF_BYTES) {
            return null;
        }

        $cookiePattern = <<<'REGEX'
~document\s*\.\s*cookie\s*=\s*(["'])([A-Za-z0-9_.-]+)=\1\s*\+\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*(?:\+\s*(["'])[^"'\r\n]*\4)?\s*;~
REGEX;

        if (! preg_match($cookiePattern, $body, $cookieMatch)) {
            return null;
        }

        $variable = preg_quote($cookieMatch[3], '~');
        $valuePattern = str_replace('%VARIABLE%', $variable, <<<'REGEX'
~(?:var|let|const)\s+%VARIABLE%\s*=\s*(["'])([A-Za-z0-9._\~-]+)\1\s*(\.\s*split\(\s*(?:''|"")\s*\)\s*\.\s*reverse\(\s*\)\s*\.\s*join\(\s*(?:''|"")\s*\))?\s*;~
REGEX);

        if (! preg_match($valuePattern, $body, $valueMatch)) {
            return null;
        }

        $locationPattern = <<<'REGEX'
~location\s*\.\s*replace\s*\(\s*(["'])([^"'\r\n]+)\1\s*\)~
REGEX;

        if (! preg_match($locationPattern, $body, $locationMatch)) {
            return null;
        }

        $target = str_replace('\/', '/', $locationMatch[2]);

        // Only escaped slashes are part of the recognised JavaScript form.
        if (str_contains($target, '\\')) {
            return null;
        }

        $value = $valueMatch[2];

        if (($valueMatch[3] ?? '') !== '') {
            $value = strrev($value);
        }

        return [
            'cookie_name' => $cookieMatch[2],
            'cookie_value' => $value,
            'target' => $target,
        ];
    }

    /** Resolve an absolute or relative proof target against the probed URL. */
    private function resolveProofTarget(string $target, string $probedUrl): ?string
    {
        $base = parse_url($probedUrl);

        if ($base === false || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        if (preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://~', $target)) {
            return $target;
        }

        if (str_starts_with($target, '//')) {
            return $base['scheme'].':'.$target;
        }

        $origin = $base['scheme'].'://'.$base['host'];

        if (isset($base['port'])) {
            $origin .= ':'.$base['port'];
        }

        if (str_starts_with($target, '/')) {
            return $origin.$target;
        }

        $basePath = $base['path'] ?? '/';

        if (str_starts_with($target, '?') || str_starts_with($target, '#')) {
            return $origin.$basePath.$target;
        }

        $lastSlash = strrpos($basePath, '/');
        $directory = $lastSlash === false ? '/' : substr($basePath, 0, $lastSlash + 1);

        return $origin.$directory.$target;
    }

    /** True when both URLs name the exact same host, case-insensitively. */
    private function hasSameHost(string $first, string $second): bool
    {
        $firstHost = parse_url($first, PHP_URL_HOST);
        $secondHost = parse_url($second, PHP_URL_HOST);

        return is_string($firstHost)
            && is_string($secondHost)
            && strcasecmp($firstHost, $secondHost) === 0;
    }

    /**
     * Apply the redirect probe's existing status and Location rules.
     *
     * @return array{url: string, status: int|null, location: string|null, reason: string, broken: bool}|null
     */
    private function verdictForResponse(Response $response, string $url, Monitor $monitor): ?array
    {
        $status = $response->status();
        $location = $response->header('Location') ?: null;

        // 3xx with a merchant Location is the healthy path.
        if ($status >= 300 && $status < 400 && $location !== null) {
            if ($this->pointsAtMerchant($location, $monitor)) {
                return [
                    'url' => $url,
                    'status' => $status,
                    'location' => $location,
                    'reason' => 'ok',
                    'broken' => false,
                ];
            }

            return [
                'url' => $url,
                'status' => $status,
                'location' => $location,
                'reason' => 'redirects_to_non_merchant',
                'broken' => true,
            ];
        }

        // These are bot-protection, throttling, or transient availability
        // responses. They tell us nothing reliable about the human redirect,
        // so let the sample shrink instead of manufacturing an alert.
        if (in_array($status, [204, 403, 429, 503], true)) {
            Log::info('BrokenRedirectService: probe refused by bot protection', [
                'monitor_id' => $monitor->id,
                'url' => $url,
                'status' => $status,
            ]);

            return null;
        }

        // Only known failure modes are actionable. Any other status is
        // indeterminate rather than proof that the merchant hand-off is broken.
        if (in_array($status, [200, 404, 500, 502], true)) {
            return [
                'url' => $url,
                'status' => $status,
                'location' => $location,
                'reason' => $status === 200 ? 'no_redirect_issued' : 'http_error',
                'broken' => true,
            ];
        }

        return null;
    }

    /**
     * True when a Location header points at one of the site's declared merchants.
     *
     * Matches on the registrable-ish host suffix rather than an exact string so
     * "amazon" covers amazon.fr, amazon.com, amzn.to and the like — the same
     * loose contract merchant_domains already uses elsewhere.
     */
    private function pointsAtMerchant(string $location, Monitor $monitor): bool
    {
        $host = parse_url($location, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);
        $merchants = $monitor->site?->merchant_domains ?? [];

        foreach ($merchants as $merchant) {
            $merchant = strtolower(trim((string) $merchant));

            if ($merchant !== '' && str_contains($host, $merchant)) {
                return true;
            }
        }

        return false;
    }
}
