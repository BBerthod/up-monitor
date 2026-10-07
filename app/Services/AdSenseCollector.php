<?php

namespace App\Services;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls real AdSense earnings per site and stores them as KPI snapshots.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Until this service, no monetary figure entered Up at all. Every
 * "revenue at risk" signal was a proxy built from GSC clicks, which cannot
 * distinguish a page that earns well from one that earns nothing, and cannot
 * see a revenue collapse that leaves traffic untouched — the exact shape of the
 * consent-platform outage that cost this fleet months of personalised ads.
 *
 * WHAT IT READS
 * ─────────────
 * accounts.reports:generate on the AdSense Management API v2, filtered to one
 * domain, over the same 28-day window every other collector uses so the numbers
 * line up in the trends view.
 *
 * Four metrics, chosen because each answers a different question:
 *  - ESTIMATED_EARNINGS  — what the site made.
 *  - IMPRESSIONS         — how much inventory was actually served.
 *  - AD_REQUESTS         — how much was asked for. The gap between requests and
 *                          impressions is unfilled inventory: a site can look
 *                          healthy on earnings while quietly failing to fill.
 *  - IMPRESSIONS_RPM     — revenue per thousand impressions, the one number that
 *                          exposes a consent problem: RPM collapses while
 *                          traffic and impressions stay flat.
 *
 * QUOTAS
 * ──────
 * 100 requests/minute and 10,000/day per project. One request per site per day
 * against a fleet of a dozen sites is far inside that, so no throttling logic
 * is warranted here.
 *
 * AUTH
 * ────
 * Reuses the service-account JWT flow already used for GSC and GA4, with the
 * adsense.readonly scope. NOTE: the service account must be added as a user on
 * the AdSense account — unlike Search Console, AdSense has no per-property
 * sharing, so access is account-wide.
 */
class AdSenseCollector
{
    private const API_BASE = 'https://adsense.googleapis.com/v2';

    private const SCOPE = 'https://www.googleapis.com/auth/adsense.readonly';

    /** Same 28-day window as the GSC/GA4/Bing collectors. */
    private const PERIOD_DAYS = 28;

    public function __construct(private readonly KpiCollector $kpiCollector) {}

    /**
     * Collect AdSense metrics for one site and persist them as KpiSnapshots.
     *
     * @return int Number of snapshots written (0 when the site is not on
     *             AdSense, or credentials/API are unavailable).
     */
    public function collectForSite(Site $site): int
    {
        if (! $this->runsAdSense($site)) {
            return 0;
        }

        $accountId = $site->adsense_account_id
            ?: config('services.adsense.account_id');

        if (empty($accountId)) {
            Log::info('AdSenseCollector: no AdSense account configured', [
                'site_id' => $site->id,
            ]);

            return 0;
        }

        $token = $this->kpiCollector->googleAccessTokenFor([self::SCOPE]);

        if ($token === null) {
            return 0;
        }

        $domain = $site->adsense_domain ?: $site->resolvedPrimaryDomain();

        if ($domain === '') {
            return 0;
        }

        // Strip a leading www.: the DOMAIN_NAME dimension reports the bare host,
        // so filtering on "www.example.com" silently matches nothing and looks
        // exactly like a site that earned zero.
        $domain = preg_replace('/^www\./i', '', $domain);

        $endDate = now()->subDay();
        $startDate = now()->subDays(self::PERIOD_DAYS + 1);

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->get(self::API_BASE."/accounts/{$accountId}/reports:generate", [
                    'startDate.year' => (int) $startDate->format('Y'),
                    'startDate.month' => (int) $startDate->format('n'),
                    'startDate.day' => (int) $startDate->format('j'),
                    'endDate.year' => (int) $endDate->format('Y'),
                    'endDate.month' => (int) $endDate->format('n'),
                    'endDate.day' => (int) $endDate->format('j'),
                    'metrics' => [
                        'ESTIMATED_EARNINGS',
                        'IMPRESSIONS',
                        'AD_REQUESTS',
                        'IMPRESSIONS_RPM',
                    ],
                    'dimensions' => ['DOMAIN_NAME'],
                    'filters' => ["DOMAIN_NAME=={$domain}"],
                ]);

            if (! $response->successful()) {
                Log::warning('AdSenseCollector: API error', [
                    'site_id' => $site->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return 0;
            }

            $metrics = $this->extractMetrics($response->json());
        } catch (\Throwable $e) {
            Log::warning('AdSenseCollector: collection failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if ($metrics === []) {
            // A site that served nothing returns no rows. That is a real and
            // interesting state, so record explicit zeroes rather than silence:
            // "no data" and "earned nothing" must not look the same downstream.
            $metrics = [
                'earnings_28d' => 0.0,
                'ad_impressions_28d' => 0.0,
                'ad_requests_28d' => 0.0,
                'rpm_28d' => 0.0,
            ];
        }

        // Fill rate is derived rather than requested: it is the ratio the API
        // does not return directly, and the one that exposes empty inventory.
        if (($metrics['ad_requests_28d'] ?? 0.0) > 0) {
            $metrics['ad_fill_rate_28d'] = round(
                $metrics['ad_impressions_28d'] / $metrics['ad_requests_28d'] * 100,
                2,
            );
        }

        $capturedAt = now();
        $written = 0;

        foreach ($metrics as $metric => $value) {
            KpiSnapshot::create([
                'site' => $site->primary_domain,
                'source' => KpiSource::ADSENSE->value,
                'metric' => $metric,
                'value' => $value,
                'period_days' => self::PERIOD_DAYS,
                'captured_at' => $capturedAt,
                'meta' => ['domain' => $domain],
            ]);

            $written++;
        }

        Log::info('AdSenseCollector: persisted snapshots', [
            'site_id' => $site->id,
            'domain' => $domain,
            'metrics' => $written,
            'earnings' => $metrics['earnings_28d'] ?? null,
        ]);

        return $written;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * True when this site declares AdSense among its ad networks.
     */
    private function runsAdSense(Site $site): bool
    {
        $networks = $site->ad_networks;

        if (! is_array($networks)) {
            return false;
        }

        foreach ($networks as $network) {
            if (str_contains(strtolower((string) $network), 'adsense')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map an AdSense report response onto metric names.
     *
     * The API returns headers and rows separately, with every cell as a string,
     * so values are matched by header position rather than assumed order.
     *
     * @param  array<string, mixed>|null  $json
     * @return array<string, float>
     */
    private function extractMetrics(?array $json): array
    {
        $rows = $json['rows'] ?? [];

        if ($rows === []) {
            return [];
        }

        $headers = array_map(
            static fn (array $header): string => $header['name'] ?? '',
            $json['headers'] ?? [],
        );

        $cells = $rows[0]['cells'] ?? [];

        $map = [
            'ESTIMATED_EARNINGS' => 'earnings_28d',
            'IMPRESSIONS' => 'ad_impressions_28d',
            'AD_REQUESTS' => 'ad_requests_28d',
            'IMPRESSIONS_RPM' => 'rpm_28d',
        ];

        $metrics = [];

        foreach ($headers as $index => $header) {
            if (! isset($map[$header])) {
                continue;
            }

            $value = $cells[$index]['value'] ?? '0';
            $metrics[$map[$header]] = round((float) $value, 4);
        }

        return $metrics;
    }
}
