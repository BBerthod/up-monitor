<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\PageAuditState;
use App\Models\PageMetric;
use App\Support\UrlSafetyValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Detects affiliate commission leaks on high-traffic pages by crawling their
 * HTML and inspecting every Amazon link's associate tag.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Sites monetised via Amazon Associates (AAWP, WP-Lasso, manual links) embed a
 * "tag=" parameter in every /dp/, /gp/product/, and amzn.to URL.  Over time,
 * plugin updates, copy-paste from third-party sources, or misconfigured themes
 * can silently introduce links with the wrong tag (foreign commission leak) or no
 * tag at all (commission loss).  This detector automates what would otherwise be a
 * manual, error-prone audit on every deploy.
 *
 * DESIGN DECISIONS
 * ────────────────
 * • Pages are selected by impressions >= min_impressions — an indexed, shown
 *   page already exposes its affiliate links, so a wrong/missing tag should be
 *   caught before the page earns clicks. Low-impression pages are skipped to
 *   avoid wasting queue budget.
 * • HTML is entity-decoded before parsing because WordPress/AAWP mix &amp; and
 *   &#038; in href attributes.  Without html_entity_decode() the query string is
 *   split at the wrong boundary and tag= is not extracted reliably.
 * • Image CDN hosts (m.media-amazon.com, images-amazon.com, images-na.ssl-
 *   images-amazon.com) are filtered out early — they appear as href targets in
 *   some themes but carry no associate tag and are not product links.
 * • Only amazon.<tld> and amzn.to hosts are matched — not ".amazon." substrings
 *   that could appear in product description text.
 * • We perform a TWO-PASS analysis when no reference tag is configured on the
 *   Site model:
 *     Pass 1 — collect all tags from ALL crawled pages.
 *     Pass 2 — the most-frequent tag is the reference; classify leaks/losses.
 *   This allows the service to work without manual configuration.
 * • One insight per anomalous page (not per anomalous link) to avoid flooding the
 *   insight list on pages with dozens of Amazon links.
 * • Idempotence: unacknowledged AFFILIATE_LEAK insights are replaced on each run
 *   so the list always reflects the current state of the site.
 * • Per-page try/catch: a crawl failure or unexpected DOM structure on one page
 *   must never abort the full audit loop.
 * • SSRF guard via UrlSafetyValidator before every outbound GET.
 *
 * SEVERITY HEURISTIC
 * ──────────────────
 * CRITICAL when the monitor is marked is_priority OR the page has >= high_traffic_clicks
 * clicks per month (default 50).  Everything else is WARNING.
 */
class AffiliateAuditService
{
    /**
     * User-Agent that mimics a real browser so WordPress and CDN edge caches
     * serve the same HTML as a regular visitor (rather than a simplified bot
     * response or a 403).
     */
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) '
        .'Chrome/120.0.0.0 Safari/537.36';

    /**
     * Maximum sample URLs stored in the insight payload to keep it human-readable
     * without storing hundreds of raw hrefs.
     */
    private const MAX_SAMPLE_URLS = 5;

    /**
     * Regex pattern matching Amazon product link hosts.
     * Matches www.amazon.fr, amazon.co.uk, amazon.com.br, amazon.com, amzn.to, etc.
     * Does NOT match image CDN hosts (m.media-amazon.com, images-amazon.com, etc.)
     * which are explicitly excluded after extraction.
     *
     * Capture groups:
     *   (none) — we only need the full host to decide if the href is a product link.
     */
    private const AMAZON_HOST_PATTERN = '/^(?:www\.)?amazon\.[a-z]{2,3}(?:\.[a-z]{2})?$|^amzn\.to$/i';

    /**
     * Hosts that are Amazon-owned but are image CDNs, not affiliate product links.
     * Any href whose host matches one of these is skipped.
     */
    private const IMAGE_CDN_HOSTS = [
        'm.media-amazon.com',
        'images-amazon.com',
        'images-na.ssl-images-amazon.com',
        'images-eu.ssl-images-amazon.com',
        'images-fe.ssl-images-amazon.com',
        'ws-eu.amazon-adsystem.com',
        'ws-na.amazon-adsystem.com',
        'z-ecx.images-amazon.com',
        'ecx.images-amazon.com',
    ];

    public function __construct(private readonly KpiCollector $kpiCollector) {}

    /**
     * Crawl high-traffic pages for this monitor and create AFFILIATE_LEAK insights
     * for any page that has Amazon links with a foreign tag or no tag at all.
     *
     * @return int Number of AFFILIATE_LEAK insights created.
     */
    public function detectForMonitor(Monitor $monitor): int
    {
        // ── Guard: only audit monitors linked to a site that has Amazon in merchant_domains ──
        //
        // We load the site via the relation (site_id is nullable on Monitor).
        // If there is no site, or the site does not list 'amazon' in merchant_domains,
        // there is nothing to audit — skip early to avoid wasting crawl budget.
        $site = $monitor->site;

        if ($site === null) {
            Log::info('AffiliateAuditService: monitor has no linked site — skipping', [
                'monitor_id' => $monitor->id,
            ]);

            return 0;
        }

        $merchantDomains = (array) ($site->merchant_domains ?? []);
        $hasAmazon = collect($merchantDomains)
            ->contains(fn (string $d) => str_contains(strtolower($d), 'amazon'));

        if (! $hasAmazon) {
            Log::info('AffiliateAuditService: site has no amazon merchant domain — skipping', [
                'monitor_id' => $monitor->id,
                'site_alias' => $site->alias,
                'merchant_domains' => $merchantDomains,
            ]);

            return 0;
        }

        // ── Config ──────────────────────────────────────────────────────────────
        // Pages are selected by impressions, not clicks: a page indexed and shown
        // by Google already exposes its affiliate links, so a leaked/missing tag
        // should be caught before the page earns clicks. Many product pages on
        // these sites have impressions but few clicks, so a clicks filter would
        // leave almost everything unaudited.
        $minImpressions = (int) config('monitoring.affiliate.min_impressions', 10);
        $maxPages = (int) config('monitoring.affiliate.max_pages_per_monitor', 30);
        $highTrafficClicks = (int) config('monitoring.affiliate.high_traffic_clicks', 50);

        // ── Page selection ───────────────────────────────────────────────────────
        // Derive the site key using the same siteNameFromUrl() logic as KpiCollector
        // and BrokenPageService so the page_metrics rows are found correctly.
        $siteName = $this->kpiCollector->siteNameFromUrl($monitor->url);

        // Latest snapshot per page. Impressions decide which pages QUALIFY;
        // rotation decides which of them are DUE this run (see below), so there
        // is deliberately no LIMIT on this query.
        $candidates = PageMetric::where('site', $siteName)
            ->whereIn('id', function ($sub) use ($siteName): void {
                $sub->selectRaw('MAX(id)')
                    ->from('page_metrics')
                    ->where('site', $siteName)
                    ->groupBy('page');
            })
            ->where('impressions', '>=', $minImpressions)
            ->orderByDesc('impressions')
            ->get();

        // Rotation: taking the top N by impressions on every run meant the tail
        // of the catalogue was never audited. One site here had ~40% of its
        // product pages rendering empty placeholders and the audit could not
        // have seen it, because those pages were never in the top 30.
        //
        // NOTE ON TAG AUTO-DETECTION: when site->amazon_tag is unset, the
        // reference tag is elected by majority across the crawled pages. Rotating
        // the sample means that election runs on a different subset each time,
        // which is an improvement rather than a risk — a fixed head sample let a
        // migration-induced leak become "the majority" permanently. The election
        // remains only a fallback; configuring amazon_tag explicitly is what
        // makes it deterministic.
        $duePages = PageAuditState::selectDue(
            $siteName,
            PageAuditState::TYPE_AFFILIATE,
            $candidates->pluck('page'),
            $maxPages,
        );

        $pages = $candidates->whereIn('page', $duePages)->values();

        if ($candidates->count() > $pages->count()) {
            Log::info('AffiliateAuditService: rotation applied', [
                'monitor_id' => $monitor->id,
                'site' => $siteName,
                'qualifying_pages' => $candidates->count(),
                'audited_this_run' => $pages->count(),
                'deferred' => $candidates->count() - $pages->count(),
            ]);
        }

        if ($pages->isEmpty()) {
            Log::info('AffiliateAuditService: no qualifying pages', [
                'monitor_id' => $monitor->id,
                'site' => $siteName,
                'min_impressions' => $minImpressions,
            ]);

            return 0;
        }

        // ── Idempotence ──────────────────────────────────────────────────────────
        // Delete all previous unacknowledged AFFILIATE_LEAK insights for this monitor
        // so each run produces a fresh picture of the current affiliate state.
        // withoutGlobalScopes() is mandatory in job context where auth() is null
        // and the ScopedByTeam global scope is inactive.
        Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::AFFILIATE_LEAK->value)
            ->whereNull('acknowledged_at')
            ->delete();

        // ── Pass 1: crawl all pages and collect raw link data ────────────────────
        //
        // We crawl every page regardless of whether we know the reference tag yet,
        // because auto-detection (when site->amazon_tag is blank) requires observing
        // ALL tags across ALL pages before we can elect the dominant/reference tag.
        //
        // Each entry: ['page' => string, 'metric' => PageMetric, 'links' => [...]]
        // where 'links' is an array of ['url' => string, 'tag' => string|null]
        $crawlResults = [];

        $httpTimeout = 15;
        $connectTimeout = 10;

        foreach ($pages as $pageMetric) {
            $pageUrl = $pageMetric->page;

            try {
                // SSRF guard — skip URLs that resolve to private/reserved IPs.
                // GSC page URLs should always be public, but a misconfigured GSC
                // property pointing at an intranet URL must not become an SSRF vector.
                if (! UrlSafetyValidator::isSafe($pageUrl)) {
                    Log::info('AffiliateAuditService: skipping unsafe URL', [
                        'monitor_id' => $monitor->id,
                        'page' => $pageUrl,
                    ]);

                    continue;
                }

                try {
                    $response = Http::timeout($httpTimeout)
                        ->connectTimeout($connectTimeout)
                        ->withHeaders(['User-Agent' => self::USER_AGENT])
                        ->get($pageUrl);

                    if (! $response->successful()) {
                        // Non-2xx — page might be broken (DetectBrokenPages handles that);
                        // we cannot extract links from an error page so skip.
                        Log::debug('AffiliateAuditService: non-2xx response, skipping link extraction', [
                            'monitor_id' => $monitor->id,
                            'page' => $pageUrl,
                            'status' => $response->status(),
                        ]);

                        continue;
                    }

                    // Entity-decode the full HTML before link extraction.
                    // WordPress and AAWP render query strings with &amp; or &#038;
                    // instead of bare &, which breaks parse_str() if not decoded first.
                    $html = html_entity_decode($response->body(), ENT_HTML5 | ENT_QUOTES, 'UTF-8');

                    $links = $this->extractAmazonLinks($html);
                } catch (ConnectionException $e) {
                    // Network-level failure — skip this page but stay in the loop.
                    Log::debug('AffiliateAuditService: connection failed, skipping page', [
                        'monitor_id' => $monitor->id,
                        'page' => $pageUrl,
                        'exception' => $e->getMessage(),
                    ]);

                    continue;
                }

                $crawlResults[] = [
                    'page' => $pageUrl,
                    'metric' => $pageMetric,
                    'links' => $links,
                ];
            } catch (\Throwable $e) {
                // Per-page isolation: an unexpected crash on one page must not
                // abort the audit loop for the entire monitor.
                Log::error('AffiliateAuditService: unexpected error while crawling page', [
                    'monitor_id' => $monitor->id,
                    'page' => $pageUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($crawlResults)) {
            Log::info('AffiliateAuditService: no pages successfully crawled', [
                'monitor_id' => $monitor->id,
                'site' => $siteName,
            ]);

            return 0;
        }

        // ── Determine the reference tag ──────────────────────────────────────────
        //
        // Priority order:
        //   1. site->amazon_tag if explicitly configured — most reliable, zero ambiguity.
        //   2. Auto-detection: the most-frequent tag observed across all crawled pages.
        //      Rationale: the dominant tag is almost certainly the correct associate tag;
        //      minority tags are leaks to third-party accounts or stale configurations.
        //   3. No reference tag found at all — we can still flag "untagged" links
        //      (commission loss) but cannot identify leaks.
        $referenceTag = ! empty($site->amazon_tag) ? trim($site->amazon_tag) : null;

        if ($referenceTag === null) {
            // Tally every tag seen across all pages; the winner becomes the reference.
            $tagFrequency = [];
            foreach ($crawlResults as $result) {
                foreach ($result['links'] as $link) {
                    if ($link['tag'] !== null) {
                        $tagFrequency[$link['tag']] = ($tagFrequency[$link['tag']] ?? 0) + 1;
                    }
                }
            }

            if (! empty($tagFrequency)) {
                // arsort keeps keys intact while sorting by value descending.
                arsort($tagFrequency);
                $referenceTag = array_key_first($tagFrequency);

                Log::info('AffiliateAuditService: auto-detected reference tag', [
                    'monitor_id' => $monitor->id,
                    'reference_tag' => $referenceTag,
                    'tag_frequency' => $tagFrequency,
                ]);
            } else {
                Log::info('AffiliateAuditService: no Amazon tags found across all pages — '
                    .'will flag untagged links only (no leak detection possible)', [
                        'monitor_id' => $monitor->id,
                        'site' => $siteName,
                    ]);
            }
        }

        // ── Pass 2: classify anomalies and create insights ───────────────────────
        $created = 0;

        foreach ($crawlResults as $result) {
            $pageUrl = $result['page'];
            $pageMetric = $result['metric'];
            $links = $result['links'];

            if (empty($links)) {
                // No Amazon links on this page — nothing to audit.
                continue;
            }

            $foreignTags = [];
            $untaggedCount = 0;
            $leakCount = 0;
            $sampleUrls = [];

            foreach ($links as $link) {
                $tag = $link['tag'];

                if ($tag === null) {
                    // No tag at all — commission loss regardless of reference tag.
                    $untaggedCount++;
                    if (count($sampleUrls) < self::MAX_SAMPLE_URLS) {
                        $sampleUrls[] = $link['url'];
                    }
                } elseif ($referenceTag !== null && $tag !== $referenceTag) {
                    // Tag present but different from reference — commission leak.
                    $leakCount++;
                    $foreignTags[] = $tag;
                    if (count($sampleUrls) < self::MAX_SAMPLE_URLS) {
                        $sampleUrls[] = $link['url'];
                    }
                }
                // tag === referenceTag → correct, no anomaly.
            }

            // No anomalies on this page → skip.
            if ($leakCount === 0 && $untaggedCount === 0) {
                continue;
            }

            $clicks = (float) $pageMetric->clicks;
            $impressions = (float) $pageMetric->impressions;

            // CRITICAL when monitor is priority OR page has heavy traffic.
            $severity = ($monitor->is_priority || $clicks >= $highTrafficClicks)
                ? InsightSeverity::CRITICAL
                : InsightSeverity::WARNING;

            // Build a human-readable title summarising the anomalies.
            // We intentionally avoid multi-line sprintf calls — keep it concise.
            $title = $this->buildTitle($pageUrl, $leakCount, $untaggedCount, (int) $clicks);

            // Deduplicate foreign tags (same tag on multiple links is one "leaking source").
            $uniqueForeignTags = array_values(array_unique($foreignTags));

            try {
                Insight::create([
                    'team_id' => $monitor->team_id,
                    'site' => $siteName,
                    'site_id' => $monitor->site_id,
                    'monitor_id' => $monitor->id,
                    'type' => InsightType::AFFILIATE_LEAK->value,
                    'severity' => $severity->value,
                    'title' => $title,
                    'payload' => [
                        'page' => $pageUrl,
                        'reference_tag' => $referenceTag,
                        'foreign_tags' => $uniqueForeignTags,
                        'untagged_count' => $untaggedCount,
                        'leak_count' => $leakCount,
                        'clicks' => (int) $clicks,
                        'impressions' => (int) $impressions,
                        'sample_urls' => $sampleUrls,
                    ],
                    // Raw monthly clicks = revenue-at-risk proxy for prioritisation.
                    'impact_score' => max(0, round($clicks, 2)),
                    'detected_at' => now(),
                ]);

                $created++;
            } catch (\Throwable $e) {
                Log::error('AffiliateAuditService: failed to create insight', [
                    'monitor_id' => $monitor->id,
                    'page' => $pageUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Advance the rotation cursor over the pages actually crawled, not the
        // ones selected: a page that could not be fetched was never examined and
        // must stay due, otherwise a persistently unreachable page would be
        // skipped for a full rotation every time.
        PageAuditState::markAudited(
            $siteName,
            PageAuditState::TYPE_AFFILIATE,
            array_column($crawlResults, 'page'),
        );

        Log::info('AffiliateAuditService: audit complete', [
            'monitor_id' => $monitor->id,
            'site' => $siteName,
            'pages_crawled' => count($crawlResults),
            'pages_with_anomalies' => $created,
            'reference_tag' => $referenceTag,
        ]);

        return $created;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extract all Amazon product links from the given HTML string and return
     * their resolved URL and associate tag (or null when absent).
     *
     * WHY REGEX OVER DOM PARSING
     * ──────────────────────────
     * DOMDocument::loadHTML() emits warnings on malformed markup (common in
     * WordPress pages), requires libxml, and is slower than a targeted regex on a
     * real-world page where we only care about one attribute.  The regex approach
     * is intentionally narrow: it only matches href="…" and href='…' attributes
     * so it is not misled by Amazon URLs in text nodes or data- attributes.
     *
     * ENTITY DECODING
     * ───────────────
     * The caller must call html_entity_decode() on the full HTML BEFORE passing it
     * here.  This method assumes clean ampersands in URLs so parse_str() works.
     *
     * @return array<int, array{url: string, tag: string|null}>
     */
    private function extractAmazonLinks(string $html): array
    {
        $links = [];

        // Match href="..." (double or single quotes, or unquoted up to whitespace/>).
        // We capture the raw attribute value; the HTML was already entity-decoded
        // by the caller so &amp; → & and &#038; → & are already resolved.
        if (! preg_match_all('/href=["\']([^"\']+)["\']|href=(\S+)/i', $html, $matches)) {
            return $links;
        }

        // Merge the two capture groups (double-quoted vs single-quoted/unquoted).
        $hrefs = array_filter(array_merge($matches[1], $matches[2]));

        foreach ($hrefs as $rawHref) {
            $rawHref = trim($rawHref, " \t\n\r\0\x0B\"'");

            $parsed = parse_url($rawHref);
            if ($parsed === false || empty($parsed['host'])) {
                continue;
            }

            $host = strtolower($parsed['host']);

            // Filter out image CDN hosts — they look like amazon.* URLs but are
            // not product links and never carry an associate tag.
            if ($this->isImageCdnHost($host)) {
                continue;
            }

            // Only process genuine Amazon product / affiliate hosts.
            if (! preg_match(self::AMAZON_HOST_PATTERN, $host)) {
                continue;
            }

            // Extract the associate tag from the query string.
            // We use parse_str() rather than a tag= regex because the query string
            // may contain the tag at any position and parse_str() handles encoding.
            $tag = null;
            if (! empty($parsed['query'])) {
                parse_str($parsed['query'], $queryParams);
                if (isset($queryParams['tag']) && $queryParams['tag'] !== '') {
                    $tag = $queryParams['tag'];
                }
            }

            $links[] = [
                'url' => $rawHref,
                'tag' => $tag,
            ];
        }

        return $links;
    }

    /**
     * Return true when the given host belongs to an Amazon image CDN and should
     * therefore be excluded from affiliate link analysis.
     */
    private function isImageCdnHost(string $host): bool
    {
        foreach (self::IMAGE_CDN_HOSTS as $cdnHost) {
            if ($host === $cdnHost || str_ends_with($host, '.'.$cdnHost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a concise, human-readable insight title summarising all anomalies
     * found on a single page.
     *
     * Examples:
     *   "Affiliate leak: /best-vacuum-cleaners has 3 link(s) with a foreign tag and 2 untagged — 120 monthly clicks at risk"
     *   "Affiliate leak: /best-vacuum-cleaners has 2 untagged link(s) — 80 monthly clicks at risk"
     *   "Affiliate leak: /best-vacuum-cleaners has 5 link(s) with a foreign tag — 200 monthly clicks at risk"
     */
    private function buildTitle(string $pageUrl, int $leakCount, int $untaggedCount, int $clicks): string
    {
        // Use the path only to keep the title from being excessively long.
        $path = parse_url($pageUrl, PHP_URL_PATH) ?: $pageUrl;

        $parts = [];

        if ($leakCount > 0) {
            $parts[] = sprintf('%d link(s) with a foreign tag', $leakCount);
        }

        if ($untaggedCount > 0) {
            $parts[] = sprintf('%d untagged link(s)', $untaggedCount);
        }

        $anomalySummary = implode(' and ', $parts);

        return sprintf(
            'Affiliate leak: %s has %s — %d monthly clicks at risk',
            $path,
            $anomalySummary,
            $clicks,
        );
    }
}
