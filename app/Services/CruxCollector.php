<?php

namespace App\Services;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Collects Core Web Vitals as REAL USERS experienced them, from the Chrome UX
 * Report.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Up's only performance signal is Lighthouse — a synthetic run, from one
 * machine, on one connection, at one moment. It is reproducible and useful for
 * catching regressions, and it is not what Google ranks on. Google ranks on
 * field data: what actual Chrome users, on actual phones and networks, actually
 * waited for.
 *
 * The two diverge routinely and in the direction that matters: a page can score
 * a comfortable 90 in the lab while its 75th-percentile LCP in the field is
 * several seconds, because real visitors are on mid-range Android over mobile
 * data rather than a datacentre connection. Optimising against the lab number
 * alone can therefore leave the ranking signal untouched.
 *
 * WHAT IT STORES
 * ──────────────
 * The three Core Web Vitals at the 75th percentile — the threshold Google
 * itself uses, where a page is "good" only if three quarters of real
 * experiences are good — plus the share of good experiences per metric, which
 * is what tells you whether a bad p75 is a long tail or a broad problem.
 *
 * NOTE ON INP: it replaced FID as a Core Web Vital in 2024. FID is not
 * collected.
 *
 * COVERAGE IS NOT GUARANTEED
 * ──────────────────────────
 * CrUX only reports origins with enough traffic to be statistically sound.
 * Smaller sites in a portfolio legitimately return 404, which this treats as a
 * normal absence of data rather than an error — an important distinction, since
 * "no field data" and "the API is broken" would otherwise look identical.
 *
 * QUOTA: 150 requests/minute, free, and not purchasable beyond that. One
 * request per site per day is nowhere near it.
 */
class CruxCollector
{
    private const API_URL = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';

    /** CrUX aggregates over a rolling 28-day window — matched here for consistency. */
    private const PERIOD_DAYS = 28;

    /**
     * Collect field metrics for a site and persist them as KpiSnapshots.
     *
     * @return int Number of snapshots written (0 when no API key is configured
     *             or the origin has insufficient field data).
     */
    public function collectForSite(Site $site): int
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            return 0;
        }

        $domain = $site->resolvedPrimaryDomain();

        if ($domain === '') {
            return 0;
        }

        // Origin-level rather than URL-level: an origin aggregates every page,
        // so it has the traffic to clear CrUX's reporting threshold on sites
        // where no single URL would.
        $origin = 'https://'.rtrim($domain, '/');

        try {
            $response = Http::timeout(30)
                ->post(self::API_URL.'?key='.urlencode($apiKey), [
                    'origin' => $origin,
                    // PHONE: this fleet's traffic is mobile-dominant, and mobile
                    // is the harder and the ranked case. Collecting both form
                    // factors would double the rows to say the same thing twice.
                    'formFactor' => 'PHONE',
                    'metrics' => [
                        'largest_contentful_paint',
                        'interaction_to_next_paint',
                        'cumulative_layout_shift',
                    ],
                ]);

            // 404 is the documented answer for an origin without enough field
            // data. That is a fact about the site, not a failure of the call.
            if ($response->status() === 404) {
                Log::info('CruxCollector: origin has insufficient field data', [
                    'site_id' => $site->id,
                    'origin' => $origin,
                ]);

                return 0;
            }

            if (! $response->successful()) {
                Log::warning('CruxCollector: API error', [
                    'site_id' => $site->id,
                    'origin' => $origin,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return 0;
            }

            $metrics = $this->extractMetrics($response->json());
        } catch (\Throwable $e) {
            Log::warning('CruxCollector: collection failed', [
                'site_id' => $site->id,
                'origin' => $origin,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if ($metrics === []) {
            return 0;
        }

        $capturedAt = now();
        $written = 0;

        foreach ($metrics as $metric => $value) {
            KpiSnapshot::create([
                'site' => $site->primary_domain,
                'source' => KpiSource::CRUX->value,
                'metric' => $metric,
                'value' => $value,
                'period_days' => self::PERIOD_DAYS,
                'captured_at' => $capturedAt,
                'meta' => ['origin' => $origin, 'form_factor' => 'PHONE'],
            ]);

            $written++;
        }

        Log::info('CruxCollector: persisted field metrics', [
            'site_id' => $site->id,
            'origin' => $origin,
            'metrics' => $written,
        ]);

        return $written;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The API key to use, reusing the PageSpeed credentials.
     *
     * CrUX and PageSpeed Insights accept the same Google Cloud API key, so
     * there is nothing new to configure. The multi-key rotation list is
     * supported too — its first entry is taken, since CrUX's 150/minute quota
     * makes rotation pointless at this volume.
     */
    private function apiKey(): ?string
    {
        $keys = config('services.google.pagespeed_api_keys');

        if (is_string($keys) && trim($keys) !== '') {
            $first = trim(explode(',', $keys)[0]);

            if ($first !== '') {
                return $first;
            }
        }

        $single = config('services.google.pagespeed_api_key');

        return is_string($single) && trim($single) !== '' ? trim($single) : null;
    }

    /**
     * Map a CrUX record onto metric names.
     *
     * Two values are kept per vital:
     *  - p75: the percentile Google evaluates against its thresholds;
     *  - good_pct: the share of experiences in the "good" bucket, which
     *    distinguishes a long tail from a broadly slow site — the same p75 can
     *    mean either, and the fix differs.
     *
     * @param  array<string, mixed>|null  $json
     * @return array<string, float>
     */
    private function extractMetrics(?array $json): array
    {
        $record = $json['record']['metrics'] ?? [];

        if ($record === []) {
            return [];
        }

        $map = [
            'largest_contentful_paint' => 'lcp_p75',
            'interaction_to_next_paint' => 'inp_p75',
            'cumulative_layout_shift' => 'cls_p75',
        ];

        $metrics = [];

        foreach ($map as $cruxName => $metricName) {
            $entry = $record[$cruxName] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $p75 = $entry['percentiles']['p75'] ?? null;

            if ($p75 !== null) {
                // CLS arrives as a decimal string, timings as integers — cast
                // rather than assume, and keep enough precision for CLS.
                $metrics[$metricName] = round((float) $p75, 4);
            }

            // histogram[0] is the "good" bucket by CrUX's own ordering.
            $goodDensity = $entry['histogram'][0]['density'] ?? null;

            if ($goodDensity !== null) {
                $metrics[str_replace('_p75', '_good_pct', $metricName)] = round((float) $goodDensity * 100, 2);
            }
        }

        return $metrics;
    }
}
