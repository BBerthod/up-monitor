<?php

namespace App\Services;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects business KPI snapshots for a given site.
 *
 * Data sources
 * ─────────────
 * - TTFB  : two curl-style HTTP requests (cold + warm) to the site URL to sample
 *           p50/p95 response time. Uses Laravel Http facade — no external dependency.
 * - GSC   : Google Search Console Data API v3. Requires GOOGLE_GSC_CLIENT_EMAIL /
 *           GOOGLE_GSC_PRIVATE_KEY env vars (service-account JSON key split into two
 *           env vars). Returns impressions, clicks, CTR, and a *cleaned* average
 *           position over 28 days (see cleanedAveragePosition()). Falls back gracefully
 *           when credentials are absent.
 * - GA4   : Google Analytics Data API v1beta. Requires GOOGLE_GA4_PROPERTY_ID and the
 *           same service-account credentials. Returns users, sessions, pageviews (28d).
 *           Falls back gracefully when credentials are absent.
 *
 * Trade-offs
 * ──────────
 * - MCP google-search-console / google-analytics are not reachable from a Docker
 *   container at runtime. We therefore call the REST APIs directly via Http facade.
 * - OAuth2 service-account JWTs are generated locally — no external token server needed.
 * - If API credentials are missing the source is silently skipped. Operators configure
 *   the credentials via env vars and the collector starts working automatically.
 */
class KpiCollector
{
    /** Number of warm + cold samples to average for TTFB. */
    private const TTFB_SAMPLE_COUNT = 5;

    /** HTTP timeout (seconds) for TTFB probes. */
    private const TTFB_TIMEOUT_S = 15;

    /** GSC / GA4 JWT audience. */
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** GSC lookback window (days). */
    private const GSC_PERIOD_DAYS = 28;

    /** GA4 lookback window (days). */
    private const GA4_PERIOD_DAYS = 28;

    /** Bing Webmaster lookback window (days). Endpoint returns daily rows; we aggregate them. */
    private const BING_PERIOD_DAYS = 28;

    /** Base URL for the Bing Webmaster Tools REST API. */
    private const BING_API_BASE = 'https://ssl.bing.com/webmaster/api.svc/json';

    // ──────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────

    /**
     * Collect all available KPI snapshots for the given site URL and persist them.
     *
     * @return list<KpiSnapshot>
     */
    public function collect(string $site): array
    {
        $snapshots = [];

        $snapshots = array_merge($snapshots, $this->collectTtfb($site));
        $snapshots = array_merge($snapshots, $this->collectGsc($site));
        $snapshots = array_merge($snapshots, $this->collectGa4($site));

        return $snapshots;
    }

    /**
     * Collect all available KPI snapshots for a Site entity and persist them.
     *
     * This is the preferred entry-point when the Site record is available,
     * because it can use the explicit gsc_property / ga4_property from the Site
     * instead of guessing them from a monitor URL.
     *
     * Site key convention
     * ───────────────────
     * The `site` key stored in kpi_snapshots MUST match the key that
     * HealthScoreService and WhatChangedService derive via
     * siteNameFromUrl($monitor->url). Both services strip "www." from the
     * hostname. We therefore always call siteNameFromUrl() on the resolved
     * primary domain URL so the key is identical regardless of call path.
     *
     * Example: primary_domain "www.examplestore.com" → resolvedPrimaryDomain()
     * returns "examplestore.com" → siteNameFromUrl("https://examplestore.com") = "examplestore.com"
     * Example: domains ["{locale}.examplestore.com"], primary_locale "fr" →
     * resolvedPrimaryDomain() = "fr.examplestore.com" → site key = "fr.examplestore.com"
     *
     * @return list<KpiSnapshot>
     */
    public function collectForSite(Site $site): array
    {
        // Canonical URL used both for TTFB probing and as the basis for the
        // site key derivation in collectGsc/collectGa4. Using the same URL
        // guarantees that siteNameFromUrl() produces a consistent key.
        $primaryUrl = 'https://'.$site->resolvedPrimaryDomain();

        $snapshots = [];

        // TTFB — prefer the first active HTTP monitor attached to this site
        // because that URL may be more specific (e.g. forces www-redirect) than
        // the bare primary domain. Fall back to primaryUrl when no monitor exists.
        $httpMonitor = $site->monitors()
            ->where('type', 'http')
            ->where('is_active', true)
            ->first();

        $ttfbUrl = $httpMonitor ? $httpMonitor->url : $primaryUrl;
        $snapshots = array_merge($snapshots, $this->collectTtfb($ttfbUrl));

        // GSC — use the explicit property from the Site record if set;
        // otherwise skip (we don't want to silently query the wrong property).
        if (! empty($site->gsc_property)) {
            // Pass $primaryUrl so siteNameFromUrl() derives the correct key,
            // and pass gsc_property as the explicit GSC property string.
            $snapshots = array_merge($snapshots, $this->collectGsc($primaryUrl, $site->gsc_property));
        }

        // GA4 — same rationale: explicit property ID or skip.
        if (! empty($site->ga4_property)) {
            $snapshots = array_merge($snapshots, $this->collectGa4($primaryUrl, $site->ga4_property));
        }

        // Bing — requires an explicit bing_url on the Site record (the URL-prefix
        // form "https://host/"). Unlike GSC there is no per-credential fallback
        // because the key is global; we still need bing_url to identify the right
        // property in the Bing Webmaster account.
        if (! empty($site->bing_url)) {
            $snapshots = array_merge($snapshots, $this->collectBing($primaryUrl, $site->bing_url));
        }

        return $snapshots;
    }

    /**
     * Collect TTFB snapshot for a monitor's URL directly.
     * Used by DispatchKpiCollection to collect TTFB from the monitor list.
     *
     * @return list<KpiSnapshot>
     */
    public function collectForMonitor(Monitor $monitor): array
    {
        if (! $monitor->is_active || $monitor->type->value !== 'http') {
            return [];
        }

        return $this->collectTtfb($monitor->url);
    }

    /**
     * Fetch raw GSC query+page rows for the given monitor over the last 28 days.
     *
     * Used by StrikingDistanceService — returns data only, never persists.
     * Persisting one KpiSnapshot per (query, page) row would produce thousands of
     * records per site per day; raw rows are processed in-memory instead.
     *
     * @return list<array{query: string, page: string, clicks: float, impressions: float, ctr: float, position: float}>
     */
    public function collectGscQueries(Monitor $monitor, int $rowLimit = 1000): array
    {
        $token = $this->getGoogleAccessToken(['https://www.googleapis.com/auth/webmasters.readonly']);

        if ($token === null) {
            return [];
        }

        // Prefer the explicit gsc_property from the linked Site (e.g.
        // "sc-domain:webcompare.fr") when available — the URL-prefix heuristic
        // derived from the monitor URL only works for URL-prefix GSC properties,
        // not domain (sc-domain) properties. Without this, sites registered as
        // sc-domain return zero rows, leaving page_metrics empty and starving the
        // broken-page / content-decay / affiliate detectors. Same routing logic
        // as collectGsc().
        $gscProperty = $monitor->site?->gsc_property;
        $siteUrl = ! empty($gscProperty)
            ? urlencode($gscProperty)
            : $this->gscSiteUrl($monitor->url);
        // Same 28-day window as collectGsc() — endDate is yesterday, startDate is 29 days ago.
        $endDate = now()->subDay()->format('Y-m-d');
        $startDate = now()->subDays(self::GSC_PERIOD_DAYS + 1)->format('Y-m-d');

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->post("https://www.googleapis.com/webmasters/v3/sites/{$siteUrl}/searchAnalytics/query", [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'dimensions' => ['query', 'page'],
                    'rowLimit' => $rowLimit,
                    // No aggregationType — per-row breakdown is the point here.
                ]);

            if (! $response->successful()) {
                Log::warning('KpiCollector GSC queries API error', [
                    'monitor_id' => $monitor->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $rows = $response->json('rows') ?? [];

            // Normalise each GSC row to a typed array.
            // GSC returns ctr as 0–1 fraction; multiply by 100 for consistency
            // with collectGsc() which also stores CTR as a percentage.
            return array_map(static function (array $row): array {
                return [
                    'query' => $row['keys'][0] ?? '',
                    'page' => $row['keys'][1] ?? '',
                    'clicks' => (float) ($row['clicks'] ?? 0),
                    'impressions' => (float) ($row['impressions'] ?? 0),
                    'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 4),
                    'position' => round((float) ($row['position'] ?? 0), 2),
                ];
            }, $rows);

        } catch (Throwable $e) {
            Log::warning('KpiCollector GSC queries collection failed', [
                'monitor_id' => $monitor->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // TTFB collection
    // ──────────────────────────────────────────────────────────

    /**
     * @return list<KpiSnapshot>
     */
    private function collectTtfb(string $url): array
    {
        $siteName = $this->siteNameFromUrl($url);
        $samples = [];

        for ($i = 0; $i < self::TTFB_SAMPLE_COUNT; $i++) {
            try {
                $start = microtime(true);
                Http::timeout(self::TTFB_TIMEOUT_S)
                    ->withHeaders(['Cache-Control' => 'no-cache'])
                    ->get($url);
                $samples[] = (microtime(true) - $start) * 1000; // ms
            } catch (Throwable $e) {
                Log::debug('KpiCollector TTFB sample failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($samples)) {
            return [];
        }

        sort($samples);
        $count = count($samples);
        $p50 = $samples[(int) floor($count * 0.5)];
        $p95Index = (int) ceil($count * 0.95) - 1;
        $p95 = $samples[max(0, $p95Index)];

        $capturedAt = now();
        $snapshots = [];

        $snapshots[] = KpiSnapshot::create([
            'site' => $siteName,
            'source' => KpiSource::TTFB->value,
            'metric' => 'ttfb_p50_ms',
            'value' => round($p50, 2),
            'period_days' => 1,
            'captured_at' => $capturedAt,
            'meta' => ['samples' => $samples, 'url' => $url],
        ]);

        $snapshots[] = KpiSnapshot::create([
            'site' => $siteName,
            'source' => KpiSource::TTFB->value,
            'metric' => 'ttfb_p95_ms',
            'value' => round($p95, 2),
            'period_days' => 1,
            'captured_at' => $capturedAt,
            'meta' => ['samples' => $samples, 'url' => $url],
        ]);

        return $snapshots;
    }

    // ──────────────────────────────────────────────────────────
    // GSC collection
    // ──────────────────────────────────────────────────────────

    /**
     * Collect GSC aggregate metrics for the site identified by $url.
     *
     * Metrics stored
     * ──────────────
     * impressions_28d, clicks_28d, ctr_28d — taken verbatim from the byProperty
     * aggregate row (these are totals; phantom queries do not distort them).
     *
     * position_28d — NOT taken from the aggregate row directly. The byProperty
     * aggregate position is the weighted average across ALL queries, including
     * thousands of phantom/spam queries in position 60-98 with zero clicks. On
     * sites like webcompare.com this drags position_28d to 60+ and produces an
     * unjustified health grade of F. cleanedAveragePosition() fixes this by
     * issuing a second GSC request (dimensions=['query'], rowLimit=1000) and
     * computing a weighted mean only over queries with clicks>0 OR
     * impressions≥N (config: health_score.seo.position_min_query_impressions).
     * If the filtered set is empty or the second request fails, it falls back
     * to the aggregate row position so the metric is never null or zero.
     *
     * @param  string  $url  Canonical site URL — used ONLY to derive the
     *                       `site` key for kpi_snapshots (via siteNameFromUrl).
     * @param  string|null  $gscProperty  Explicit GSC property string, e.g.
     *                                    "sc-domain:example.com" or "https://example.com/".
     *                                    When provided it is URL-encoded and used directly
     *                                    as the GSC API property identifier instead of the
     *                                    URL-prefix form derived from $url. Passing null
     *                                    preserves the original "derive from $url" behaviour
     *                                    for backwards compatibility.
     * @return list<KpiSnapshot>
     */
    private function collectGsc(string $url, ?string $gscProperty = null): array
    {
        $siteName = $this->siteNameFromUrl($url);
        $token = $this->getGoogleAccessToken(['https://www.googleapis.com/auth/webmasters.readonly']);

        if ($token === null) {
            return [];
        }

        // When an explicit GSC property is supplied (e.g. "sc-domain:examplestore.com")
        // use it directly; otherwise fall back to building the URL-prefix form from
        // $url. This lets operators register sc-domain properties in the Site record
        // instead of relying on the URL-prefix heuristic that only works for some GSC
        // property types.
        $siteUrl = $gscProperty !== null ? urlencode($gscProperty) : $this->gscSiteUrl($url);
        $endDate = now()->subDay()->format('Y-m-d');
        $startDate = now()->subDays(self::GSC_PERIOD_DAYS + 1)->format('Y-m-d');

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->post("https://www.googleapis.com/webmasters/v3/sites/{$siteUrl}/searchAnalytics/query", [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'dimensions' => [],
                    'rowLimit' => 1,
                    'aggregationType' => 'byProperty',
                ]);

            if (! $response->successful()) {
                Log::warning('KpiCollector GSC API error', [
                    'site' => $siteName,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json();
            $row = $data['rows'][0] ?? null;

            if ($row === null) {
                return [];
            }

            $capturedAt = now();
            $snapshots = [];

            $snapshots[] = KpiSnapshot::create([
                'site' => $siteName,
                'source' => KpiSource::GSC->value,
                'metric' => 'impressions_28d',
                'value' => (float) ($row['impressions'] ?? 0),
                'period_days' => self::GSC_PERIOD_DAYS,
                'captured_at' => $capturedAt,
                'meta' => ['raw_row' => $row, 'start_date' => $startDate, 'end_date' => $endDate],
            ]);

            $snapshots[] = KpiSnapshot::create([
                'site' => $siteName,
                'source' => KpiSource::GSC->value,
                'metric' => 'clicks_28d',
                'value' => (float) ($row['clicks'] ?? 0),
                'period_days' => self::GSC_PERIOD_DAYS,
                'captured_at' => $capturedAt,
                'meta' => ['raw_row' => $row],
            ]);

            $snapshots[] = KpiSnapshot::create([
                'site' => $siteName,
                'source' => KpiSource::GSC->value,
                'metric' => 'ctr_28d',
                'value' => round((float) ($row['ctr'] ?? 0) * 100, 4), // store as percentage
                'period_days' => self::GSC_PERIOD_DAYS,
                'captured_at' => $capturedAt,
                'meta' => ['raw_row' => $row],
            ]);

            // Compute a cleaned average position that excludes phantom/spam queries.
            // cleanedAveragePosition() proxies the raw aggregate as fallback when the
            // second request fails or no queries survive the filter, so the metric is
            // never missing. See method docblock for full rationale.
            $minImpressions = (int) config('monitoring.health_score.seo.position_min_query_impressions', 10);
            $positionResult = $this->cleanedAveragePosition(
                token: $token,
                siteUrl: $siteUrl,
                startDate: $startDate,
                endDate: $endDate,
                fallbackPosition: (float) ($row['position'] ?? 0),
                minImpressions: $minImpressions,
            );

            $snapshots[] = KpiSnapshot::create([
                'site' => $siteName,
                'source' => KpiSource::GSC->value,
                'metric' => 'position_28d',
                'value' => round($positionResult['position'], 2),
                'period_days' => self::GSC_PERIOD_DAYS,
                'captured_at' => $capturedAt,
                'meta' => [
                    'raw_row' => $row,
                    'position_source' => $positionResult['source'],
                    'queries_considered' => $positionResult['queries_considered'],
                ],
            ]);

            return $snapshots;

        } catch (Throwable $e) {
            Log::warning('KpiCollector GSC collection failed', [
                'site' => $siteName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // GA4 collection
    // ──────────────────────────────────────────────────────────

    /**
     * Collect GA4 aggregate metrics for the site identified by $url.
     *
     * @param  string  $url  Canonical site URL — used ONLY to derive the
     *                       `site` key for kpi_snapshots (via siteNameFromUrl).
     * @param  string|null  $ga4Property  Explicit GA4 property ID, e.g. "p528945610".
     *                                    When provided it overrides the global
     *                                    GOOGLE_GA4_PROPERTY_ID config value so each site
     *                                    can report to its own GA4 property. Passing null
     *                                    preserves the original single-property behaviour.
     * @return list<KpiSnapshot>
     */
    private function collectGa4(string $url, ?string $ga4Property = null): array
    {
        $siteName = $this->siteNameFromUrl($url);

        // Explicit per-site property takes precedence; fall back to the global
        // config value for backwards compatibility with single-site setups.
        $propertyId = $ga4Property ?? config('services.google.ga4_property_id');

        if (empty($propertyId)) {
            return [];
        }

        // Sites imported from the YAML config store the GA4 property with a "p"
        // prefix (e.g. "p528945610"), but the GA4 Data API path expects the bare
        // numeric id ("properties/528945610"). Strip a single leading "p" so both
        // "p528945610" and "528945610" are accepted.
        $propertyId = preg_replace('/^p(?=\d)/', '', (string) $propertyId);

        $token = $this->getGoogleAccessToken(['https://www.googleapis.com/auth/analytics.readonly']);

        if ($token === null) {
            return [];
        }

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->post("https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport", [
                    // GA4 requires startDate <= endDate. The window is the last 28 days:
                    // start is 28 days ago, end is today.
                    'dateRanges' => [['startDate' => self::GA4_PERIOD_DAYS.'daysAgo', 'endDate' => 'today']],
                    'metrics' => [
                        ['name' => 'activeUsers'],
                        ['name' => 'sessions'],
                        ['name' => 'screenPageViews'],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('KpiCollector GA4 API error', [
                    'site' => $siteName,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();
            $row = $data['rows'][0]['metricValues'] ?? null;

            if ($row === null) {
                return [];
            }

            $capturedAt = now();
            $snapshots = [];

            $metricNames = ['users_28d', 'sessions_28d', 'pageviews_28d'];

            foreach ($metricNames as $idx => $metricName) {
                $snapshots[] = KpiSnapshot::create([
                    'site' => $siteName,
                    'source' => KpiSource::GA4->value,
                    'metric' => $metricName,
                    'value' => (float) ($row[$idx]['value'] ?? 0),
                    'period_days' => self::GA4_PERIOD_DAYS,
                    'captured_at' => $capturedAt,
                    'meta' => null,
                ]);
            }

            return $snapshots;

        } catch (Throwable $e) {
            Log::warning('KpiCollector GA4 collection failed', [
                'site' => $siteName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // Bing Webmaster collection
    // ──────────────────────────────────────────────────────────

    /**
     * Collect Bing Webmaster aggregate metrics for the site identified by $url.
     *
     * The Bing Webmaster REST API returns one row per day; this method aggregates
     * the last BING_PERIOD_DAYS days into three snapshots: total impressions, total
     * clicks, and CTR (clicks / impressions × 100). The API provides no position or
     * query breakdown in this endpoint, so only these three metrics are stored.
     *
     * Authentication is via a plain API key passed as a query parameter — no OAuth.
     * The key is read from config('services.bing.api_key') (env BING_WEBMASTER_API_KEY).
     *
     * @param  string  $url  Canonical site URL — used ONLY to derive the `site` key
     *                       for kpi_snapshots (via siteNameFromUrl). Must be the same
     *                       URL that other collectors use so the key is consistent.
     * @param  string|null  $bingUrl  URL-prefix property registered in Bing Webmaster,
     *                                e.g. "https://example.com/". Stored in Site::bing_url.
     *                                When absent the method returns [] immediately.
     * @return list<KpiSnapshot>
     */
    private function collectBing(string $url, ?string $bingUrl = null): array
    {
        $siteName = $this->siteNameFromUrl($url);
        $apiKey = config('services.bing.api_key');

        // Both the API key and an explicit Bing property URL are required.
        // Missing either means Bing collection is not configured for this site.
        if (empty($apiKey) || empty($bingUrl)) {
            return [];
        }

        // Normalise the stored bing_url to the "https://host/" form that the
        // GetRankAndTrafficStats endpoint expects. Sites imported from Bing Webmaster
        // dashboard links carry the full dashboard URL
        // (e.g. "https://www.bing.com/webmasters/sitemaps?siteUrl=https://radiank.com"),
        // not the bare URL-prefix property. bingApiSiteUrl() handles both forms.
        $apiSiteUrl = $this->bingApiSiteUrl($bingUrl);

        if ($apiSiteUrl === null) {
            Log::warning('KpiCollector Bing: could not normalise bing_url to a valid API siteUrl', [
                'site' => $siteName,
                'bing_url' => $bingUrl,
            ]);

            return [];
        }

        // Aggregation window: [today-29 days, yesterday] inclusive — same convention as GSC.
        $endTs = now()->subDay()->startOfDay();
        $startTs = now()->subDays(self::BING_PERIOD_DAYS + 1)->startOfDay();

        try {
            $response = Http::timeout(30)
                ->get(self::BING_API_BASE.'/GetRankAndTrafficStats', [
                    'apikey' => $apiKey,
                    'siteUrl' => $apiSiteUrl,
                ]);

            if (! $response->successful()) {
                Log::warning('KpiCollector Bing API error', [
                    'site' => $siteName,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            // Response is OData: { "d": [ { "Clicks": N, "Impressions": N, "Date": "/Date(ms-tz)/" }, ... ] }
            $rows = $response->json('d') ?? [];

            // Filter to the aggregation window and sum clicks + impressions.
            $totalClicks = 0.0;
            $totalImpressions = 0.0;
            $daysAggregated = 0;
            $firstDate = null;
            $lastDate = null;

            foreach ($rows as $row) {
                // Extract milliseconds from the OData "/Date(<ms>-<tzoffset>)/" format.
                if (! preg_match('/\/Date\((\d+)/', (string) ($row['Date'] ?? ''), $m)) {
                    continue;
                }

                $rowTs = (int) $m[1];
                $rowDate = \Carbon\Carbon::createFromTimestampMs($rowTs)->startOfDay();

                if ($rowDate->lt($startTs) || $rowDate->gt($endTs)) {
                    continue;
                }

                $totalClicks += (float) ($row['Clicks'] ?? 0);
                $totalImpressions += (float) ($row['Impressions'] ?? 0);
                $daysAggregated++;

                if ($firstDate === null || $rowDate->lt($firstDate)) {
                    $firstDate = $rowDate->copy();
                }
                if ($lastDate === null || $rowDate->gt($lastDate)) {
                    $lastDate = $rowDate->copy();
                }
            }

            // Nothing in the window — API may return data for dates outside our range
            // (e.g. very new sites with only a few days of history). Treat as no data.
            if ($daysAggregated === 0) {
                return [];
            }

            // CTR as a percentage, consistent with how collectGsc() stores ctr_28d.
            $ctr = $totalImpressions > 0
                ? round($totalClicks / $totalImpressions * 100, 4)
                : 0.0;

            $capturedAt = now();
            $meta = [
                'days_aggregated' => $daysAggregated,
                'start_date' => $firstDate?->format('Y-m-d'),
                'end_date' => $lastDate?->format('Y-m-d'),
            ];

            $snapshots = [];

            $snapshots[] = KpiSnapshot::create([
                'site' => $siteName,
                'source' => KpiSource::BING->value,
                'metric' => 'bing_impressions_28d',
                'value' => $totalImpressions,
                'period_days' => self::BING_PERIOD_DAYS,
                'captured_at' => $capturedAt,
                'meta' => $meta,
            ]);

            $snapshots[] = KpiSnapshot::create([
                'site' => $siteName,
                'source' => KpiSource::BING->value,
                'metric' => 'bing_clicks_28d',
                'value' => $totalClicks,
                'period_days' => self::BING_PERIOD_DAYS,
                'captured_at' => $capturedAt,
                'meta' => $meta,
            ]);

            $snapshots[] = KpiSnapshot::create([
                'site' => $siteName,
                'source' => KpiSource::BING->value,
                'metric' => 'bing_ctr_28d',
                'value' => $ctr,
                'period_days' => self::BING_PERIOD_DAYS,
                'captured_at' => $capturedAt,
                'meta' => $meta,
            ]);

            return $snapshots;

        } catch (Throwable $e) {
            Log::warning('KpiCollector Bing collection failed', [
                'site' => $siteName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // Google OAuth2 service-account helper
    // ──────────────────────────────────────────────────────────

    /**
     * Obtain a short-lived access token for the given scopes using a service-account JWT.
     * Returns null when credentials are not configured (GOOGLE_GSC_CLIENT_EMAIL or
     * GOOGLE_GSC_PRIVATE_KEY missing).
     */
    /**
     * Public wrapper over the service-account token exchange, so sibling
     * collectors (AdSense and any future Google API) reuse this one
     * implementation rather than each re-deriving JWT signing and the
     * private-key newline handling that comes with it.
     *
     * @param  array<int, string>  $scopes
     */
    public function googleAccessTokenFor(array $scopes): ?string
    {
        return $this->getGoogleAccessToken($scopes);
    }

    private function getGoogleAccessToken(array $scopes): ?string
    {
        $clientEmail = config('services.google.client_email');
        $privateKey = config('services.google.private_key');

        if (empty($clientEmail) || empty($privateKey)) {
            return null;
        }

        // Replace escaped newlines that may come from .env file storage.
        $privateKey = str_replace('\\n', "\n", $privateKey);

        $now = time();
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'iss' => $clientEmail,
            'scope' => implode(' ', $scopes),
            'aud' => self::GOOGLE_TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signInput = $header.'.'.$payload;
        $signature = '';

        if (! openssl_sign($signInput, $signature, $privateKey, 'SHA256')) {
            Log::error('KpiCollector: failed to sign Google JWT — check GOOGLE_GSC_PRIVATE_KEY format');

            return null;
        }

        $jwt = $signInput.'.'.base64_encode($signature);

        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post(self::GOOGLE_TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (! $response->successful()) {
                Log::warning('KpiCollector: Google token exchange failed', ['status' => $response->status()]);

                return null;
            }

            return $response->json('access_token');

        } catch (Throwable $e) {
            Log::warning('KpiCollector: Google token exchange threw', ['error' => $e->getMessage()]);

            return null;
        }
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Normalise a stored bing_url value to the "https://host/" form that the
     * Bing Webmaster GetRankAndTrafficStats endpoint expects as its siteUrl parameter.
     *
     * Two input forms are handled:
     *
     *  1. Dashboard URL  — e.g. "https://www.bing.com/webmasters/sitemaps?siteUrl=https://radiank.com"
     *     Sites imported from Bing Webmaster dashboard links store the full UI URL. The
     *     actual property is carried in the `siteUrl` query parameter. We extract it with
     *     parse_url() + parse_str() so both url-encoded and literal `https://` forms work.
     *
     *  2. Direct URL  — e.g. "https://campsite.fr" or "https://campsite.fr/"
     *     Already a valid property URL; we just ensure it ends with a trailing slash.
     *
     * @return string|null Normalised "scheme://host/" string, or null when no host
     *                     can be derived (malformed input or bing_url not set).
     */
    private function bingApiSiteUrl(string $bingUrl): ?string
    {
        // Detect dashboard form: presence of a `siteUrl` query parameter whose value
        // is the actual property URL. Use parse_str() so both encoded and literal
        // `https://` values in the query string are handled uniformly.
        $query = parse_url($bingUrl, PHP_URL_QUERY) ?? '';
        $params = [];
        parse_str($query, $params);

        // When a siteUrl param is present, the stored URL is a dashboard link —
        // the real property is the param value. Otherwise treat the whole URL as
        // the property (direct form).
        $propertyUrl = isset($params['siteUrl']) && $params['siteUrl'] !== ''
            ? $params['siteUrl']
            : $bingUrl;

        // Reconstruct as "scheme://host/" — the canonical form for the Bing API.
        $scheme = parse_url($propertyUrl, PHP_URL_SCHEME) ?? 'https';
        $host = parse_url($propertyUrl, PHP_URL_HOST);

        if (empty($host)) {
            return null;
        }

        return "{$scheme}://{$host}/";
    }

    /**
     * Derive a canonical site name (hostname) from a full URL.
     * e.g. "https://www.example.de/foo" → "example.de"
     */
    public function siteNameFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        // Strip a leading "www." PREFIX only. ltrim($host, 'www.') is a bug: it
        // treats "www." as a character set and strips every leading w/./
        // character, so "webcompare.fr" becomes "ebcompare.fr". preg_replace with
        // an anchored pattern removes the prefix and nothing else.
        return preg_replace('/^www\./i', '', $host);
    }

    /**
     * Build the URL-encoded GSC site property string.
     * GSC accepts both "sc-domain:example.com" (domain property) and "https://example.com/"
     * (URL-prefix property). We use the URL-prefix form as it's more widely applicable.
     */
    private function gscSiteUrl(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?? 'https';
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        return urlencode("{$scheme}://{$host}/");
    }

    // ──────────────────────────────────────────────────────────
    // GSC position cleaning
    // ──────────────────────────────────────────────────────────

    /**
     * Compute a cleaned average GSC position, filtering phantom/spam queries.
     *
     * The problem
     * ───────────
     * The byProperty aggregate (no dimensions) weights ALL queries equally,
     * including thousands of spam / geotargeting queries ranked in position
     * 60-98 with zero clicks. On sites like webcompare.com these drag the
     * aggregated position_28d to 60+ even when genuine commercial queries sit
     * at position 5-15. This produces an unjustified health-score grade of F.
     *
     * The solution
     * ────────────
     * Issue a second GSC request (dimensions=['query'], rowLimit=1000) and
     * compute a weighted mean position over the subset of queries that have
     * meaningful engagement: clicks > 0 OR impressions >= $minImpressions.
     * Weighting by impressions gives higher-traffic queries more influence,
     * which matches how operators mentally assess site ranking.
     *
     * Fallback chain (in order of preference)
     * ─────────────────────────────────────────
     * 1. Weighted mean over the filtered query set  — "weighted_filtered" source.
     * 2. Weighted mean over ALL queries (no filter) — "weighted_all" source
     *    (used when no query passes the filter, which can happen for very new
     *    or very low-traffic sites; better than the aggregate fallback because
     *    at least it weights by impressions).
     * 3. Raw aggregate position from the byProperty row — "aggregate_fallback"
     *    source (used when the second request fails or returns no rows at all).
     *
     * This method NEVER returns 0 or null — it always produces a position value
     * so the health-score formula is not starved of data.
     *
     * @param  string  $token  Short-lived Google OAuth2 access token.
     * @param  string  $siteUrl  URL-encoded GSC property identifier.
     * @param  string  $startDate  ISO date string (YYYY-MM-DD).
     * @param  string  $endDate  ISO date string (YYYY-MM-DD).
     * @param  float  $fallbackPosition  Position from the aggregate byProperty row.
     * @param  int  $minImpressions  Minimum impressions for a query to be counted.
     * @return array{position: float, source: string, queries_considered: int}
     */
    private function cleanedAveragePosition(
        string $token,
        string $siteUrl,
        string $startDate,
        string $endDate,
        float $fallbackPosition,
        int $minImpressions,
    ): array {
        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->post("https://www.googleapis.com/webmasters/v3/sites/{$siteUrl}/searchAnalytics/query", [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'dimensions' => ['query'],
                    'rowLimit' => 1000,
                    'aggregationType' => 'byProperty',
                ]);

            if (! $response->successful()) {
                Log::warning('KpiCollector cleanedAveragePosition: GSC query breakdown failed', [
                    'site_url' => $siteUrl,
                    'status' => $response->status(),
                ]);

                return [
                    'position' => $fallbackPosition,
                    'source' => 'aggregate_fallback',
                    'queries_considered' => 0,
                ];
            }

            $rows = $response->json('rows') ?? [];

            if (empty($rows)) {
                return [
                    'position' => $fallbackPosition,
                    'source' => 'aggregate_fallback',
                    'queries_considered' => 0,
                ];
            }

            // Partition rows into two pools: engaged (clicks>0 OR impressions>=floor)
            // and all. We prefer the engaged pool; fall back to all when the filter
            // removes every row (extremely low-traffic / new sites).
            $engagedWeightedSum = 0.0;
            $engagedImprTotal = 0.0;
            $allWeightedSum = 0.0;
            $allImprTotal = 0.0;
            $engagedCount = 0;

            foreach ($rows as $row) {
                $clicks = (float) ($row['clicks'] ?? 0);
                $impressions = (float) ($row['impressions'] ?? 0);
                $position = (float) ($row['position'] ?? 0);

                if ($impressions <= 0.0) {
                    continue;
                }

                // Accumulate in the "all" pool unconditionally.
                $allWeightedSum += $position * $impressions;
                $allImprTotal += $impressions;

                // Accumulate in the "engaged" pool only for meaningful queries.
                if ($clicks > 0.0 || $impressions >= $minImpressions) {
                    $engagedWeightedSum += $position * $impressions;
                    $engagedImprTotal += $impressions;
                    $engagedCount++;
                }
            }

            // Use the engaged pool when at least one query qualifies.
            if ($engagedCount > 0 && $engagedImprTotal > 0.0) {
                return [
                    'position' => $engagedWeightedSum / $engagedImprTotal,
                    'source' => 'weighted_filtered',
                    'queries_considered' => $engagedCount,
                ];
            }

            // Fallback to unfiltered weighted average — still better than the raw
            // aggregate because it weights by impressions rather than query count.
            if ($allImprTotal > 0.0) {
                return [
                    'position' => $allWeightedSum / $allImprTotal,
                    'source' => 'weighted_all',
                    'queries_considered' => count($rows),
                ];
            }

            // Final fallback: return the aggregate row position unchanged.
            return [
                'position' => $fallbackPosition,
                'source' => 'aggregate_fallback',
                'queries_considered' => 0,
            ];

        } catch (Throwable $e) {
            Log::warning('KpiCollector cleanedAveragePosition: exception, using aggregate fallback', [
                'site_url' => $siteUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'position' => $fallbackPosition,
                'source' => 'aggregate_fallback',
                'queries_considered' => 0,
            ];
        }
    }
}
