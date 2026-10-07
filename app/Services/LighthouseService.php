<?php

namespace App\Services;

use App\DTOs\LighthouseResult;
use App\Exceptions\GooglePSI400Exception;
use App\Exceptions\GooglePSI403Exception;
use App\Exceptions\GooglePSI429Exception;
use App\Models\Monitor;
use App\Models\MonitorLighthouseScore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LighthouseService
{
    /**
     * Daily quota cap per key. Google's documented limit is 25,000 queries/day
     * for authenticated keys, but 400 is a conservative safe threshold when
     * managing a small pool of keys to avoid surprise quota exhaustion.
     */
    private const DAILY_QUOTA_CAP = 400;

    public function audit(Monitor $monitor): MonitorLighthouseScore
    {
        $keys = $this->resolveApiKeys();

        // Try each key in rotation until one succeeds or all are exhausted.
        $lastException = null;

        foreach ($this->keyRotationOrder($keys) as $idx => $key) {
            if ($this->isKeyDisabled($idx)) {
                continue;
            }

            if ($this->isDailyQuotaExhausted($idx)) {
                Log::info('PSI key daily quota exhausted, rotating', ['key_index' => $idx]);

                continue;
            }

            try {
                $result = $this->callPsi($monitor, $key, $idx);
                $this->incrementDailyQuota($idx);

                return $result;
            } catch (GooglePSI403Exception $e) {
                Log::critical('PSI API key invalid or revoked — disabling', [
                    'key_index' => $idx,
                    'error' => $e->getMessage(),
                ]);
                $this->disableKey($idx);
                $lastException = $e;
            } catch (GooglePSI429Exception $e) {
                Log::warning('PSI API key quota exceeded — rotating', [
                    'key_index' => $idx,
                    'error' => $e->getMessage(),
                ]);
                $this->markKeyQuotaExhausted($idx);
                $lastException = $e;
            }
        }

        // All keys failed.
        $message = $lastException
            ? 'All PSI API keys exhausted or disabled: '.$lastException->getMessage()
            : 'No PSI API keys available.';

        throw new RuntimeException($message, 0, $lastException);
    }

    /**
     * Resolve the ordered list of API keys from config.
     * Priority: GOOGLE_PAGESPEED_API_KEYS (CSV) → GOOGLE_PAGESPEED_API_KEY → [null] (keyless).
     *
     * @return list<string|null>
     */
    private function resolveApiKeys(): array
    {
        $multiKeys = config('services.google.pagespeed_api_keys');

        if ($multiKeys) {
            $parsed = array_values(array_filter(
                array_map('trim', explode(',', $multiKeys))
            ));

            if (! empty($parsed)) {
                return $parsed;
            }
        }

        // Fall back to single key or null (keyless call).
        return [config('services.google.pagespeed_api_key') ?: null];
    }

    /**
     * Yield key-index pairs in a round-robin order anchored to today's date
     * so the starting key rotates daily, distributing load evenly.
     *
     * @param  list<string|null>  $keys
     * @return iterable<int, string|null>
     */
    private function keyRotationOrder(array $keys): iterable
    {
        $count = count($keys);
        $offset = (int) date('z') % $count; // day-of-year % key count

        for ($i = 0; $i < $count; $i++) {
            $idx = ($offset + $i) % $count;
            yield $idx => $keys[$idx];
        }
    }

    /**
     * Execute the actual PSI HTTP call for a given key.
     *
     * @throws GooglePSI400Exception
     * @throws GooglePSI403Exception
     * @throws GooglePSI429Exception
     * @throws RuntimeException
     */
    private function callPsi(Monitor $monitor, ?string $apiKey, int $keyIdx): MonitorLighthouseScore
    {
        $query = http_build_query([
            'url' => $monitor->url,
            'strategy' => 'mobile',
        ]).'&category=performance&category=accessibility&category=best-practices&category=seo';

        if ($apiKey) {
            $query .= '&key='.urlencode($apiKey);
        }

        try {
            $response = Http::timeout(120)->get(
                'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?'.$query
            );
        } catch (\Throwable $e) {
            Log::error('Lighthouse API connection error', [
                'monitor_id' => $monitor->id,
                'url' => $monitor->url,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Lighthouse API connection error: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $body = $response->body();
            $context = [
                'monitor_id' => $monitor->id,
                'url' => $monitor->url,
                'status' => $status,
                'key_index' => $keyIdx,
            ];

            if ($status === 400) {
                // Not a key/quota problem — the URL itself is not auditable
                // by PSI (e.g. a JSON endpoint or a redirect target). Do not
                // rotate keys for this: every key will fail the same way.
                Log::warning('Lighthouse API rejected URL as unauditable (400)', $context);
                throw new GooglePSI400Exception((string) $keyIdx, substr($body, 0, 500));
            }

            if ($status === 403) {
                Log::critical('Lighthouse API key invalid (403)', $context);
                throw new GooglePSI403Exception((string) $keyIdx, substr($body, 0, 500));
            }

            if ($status === 429) {
                Log::warning('Lighthouse API quota exceeded (429)', $context);
                throw new GooglePSI429Exception((string) $keyIdx, substr($body, 0, 500));
            }

            Log::error('Lighthouse API request failed', array_merge($context, ['body' => substr($body, 0, 500)]));
            throw new RuntimeException('Lighthouse API request failed (HTTP '.$status.')');
        }

        $data = $response->json();
        $lighthouseResult = $data['lighthouseResult'] ?? null;

        if (! $lighthouseResult) {
            Log::error('Invalid Lighthouse API response', [
                'monitor_id' => $monitor->id,
                'url' => $monitor->url,
                'key_index' => $keyIdx,
            ]);
            throw new RuntimeException('Invalid Lighthouse API response');
        }

        $categories = $lighthouseResult['categories'] ?? [];
        $audits = $lighthouseResult['audits'] ?? [];

        $result = new LighthouseResult(
            performance: $this->parseScore($categories, 'performance'),
            accessibility: $this->parseScore($categories, 'accessibility'),
            bestPractices: $this->parseScore($categories, 'best-practices'),
            seo: $this->parseScore($categories, 'seo'),
            lcp: $this->parseAudit($audits, 'largest-contentful-paint', 1),
            fcp: $this->parseAudit($audits, 'first-contentful-paint', 1),
            cls: $this->parseAudit($audits, 'cumulative-layout-shift', 4),
            tbt: $this->parseAudit($audits, 'total-blocking-time', 1),
            speedIndex: $this->parseAudit($audits, 'speed-index', 1),
            lcpObserved: $this->parseNumeric(
                data_get($audits, 'metrics.details.items.0.observedLargestContentfulPaint'),
                1,
            ),
            benchmarkIndex: $this->parseNumeric(
                data_get($lighthouseResult, 'environment.benchmarkIndex'),
                1,
            ),
        );

        $field = $this->parseFieldData($data);

        return MonitorLighthouseScore::create([
            'monitor_id' => $monitor->id,
            'performance' => $result->performance,
            'accessibility' => $result->accessibility,
            'best_practices' => $result->bestPractices,
            'seo' => $result->seo,
            'lcp' => $result->lcp,
            'lcp_observed' => $result->lcpObserved,
            'fcp' => $result->fcp,
            'cls' => $result->cls,
            'tbt' => $result->tbt,
            'benchmark_index' => $result->benchmarkIndex,
            'speed_index' => $result->speedIndex,
            ...$field,
            'scored_at' => now(),
        ]);
    }

    /**
     * Real-user (CrUX) metrics from the PSI response already received: the URL's
     * `loadingExperience`, else the origin's `originLoadingExperience`, else all null
     * (site too small for CrUX). The lab values lie on pages where e.g. a consent
     * dialog is counted as the LCP; the field data is what users actually experienced.
     *
     * TO CONFIRM against a real PSI response: the CrUX keys CUMULATIVE_LAYOUT_SHIFT_SCORE
     * and INTERACTION_TO_NEXT_PAINT, and that the CLS percentile is x100 (e.g. 8 => 0.08).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|float|string|null>
     */
    private function parseFieldData(array $data): array
    {
        $field = array_fill_keys([
            'field_lcp_ms', 'field_lcp_category', 'field_cls', 'field_cls_category',
            'field_inp_ms', 'field_inp_category', 'field_source',
        ], null);

        foreach (['url' => 'loadingExperience', 'origin' => 'originLoadingExperience'] as $source => $key) {
            $metrics = $data[$key]['metrics'] ?? null;

            if (! is_array($metrics) || $metrics === []) {
                continue;
            }

            $lcp = $metrics['LARGEST_CONTENTFUL_PAINT_MS'] ?? [];
            $cls = $metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE'] ?? [];
            $inp = $metrics['INTERACTION_TO_NEXT_PAINT'] ?? [];

            return [
                'field_lcp_ms' => is_numeric($lcp['percentile'] ?? null) ? (int) round((float) $lcp['percentile']) : null,
                'field_lcp_category' => $lcp['category'] ?? null,
                'field_cls' => is_numeric($cls['percentile'] ?? null) ? round((float) $cls['percentile'] / 100, 4) : null,
                'field_cls_category' => $cls['category'] ?? null,
                'field_inp_ms' => is_numeric($inp['percentile'] ?? null) ? (int) round((float) $inp['percentile']) : null,
                'field_inp_category' => $inp['category'] ?? null,
                'field_source' => $source,
            ];
        }

        return $field;
    }

    // -------------------------------------------------------------------------
    // Quota & disable helpers (Redis-backed via Cache)
    // -------------------------------------------------------------------------

    private function isDailyQuotaExhausted(int $keyIdx): bool
    {
        return (int) Cache::get($this->quotaKey($keyIdx), 0) >= self::DAILY_QUOTA_CAP;
    }

    private function incrementDailyQuota(int $keyIdx): void
    {
        $key = $this->quotaKey($keyIdx);
        $count = Cache::increment($key);

        if ($count === 1) {
            // Set TTL to end of day (seconds until midnight UTC).
            $secondsUntilMidnight = strtotime('tomorrow midnight UTC') - time();
            Cache::put($key, 1, $secondsUntilMidnight);
        }
    }

    private function markKeyQuotaExhausted(int $keyIdx): void
    {
        $secondsUntilMidnight = strtotime('tomorrow midnight UTC') - time();
        Cache::put($this->quotaKey($keyIdx), self::DAILY_QUOTA_CAP, $secondsUntilMidnight);
    }

    private function isKeyDisabled(int $keyIdx): bool
    {
        return Cache::has($this->disabledKey($keyIdx));
    }

    private function disableKey(int $keyIdx): void
    {
        // Disable for 24 hours to avoid hammering a revoked key.
        Cache::put($this->disabledKey($keyIdx), true, now()->addDay());
    }

    private function quotaKey(int $keyIdx): string
    {
        return 'psi:quota:'.$keyIdx.':'.now()->format('Y-m-d');
    }

    private function disabledKey(int $keyIdx): string
    {
        return 'psi:disabled:'.$keyIdx;
    }

    // -------------------------------------------------------------------------
    // Parsing helpers
    // -------------------------------------------------------------------------

    private function parseScore(array $categories, string $key): int
    {
        return isset($categories[$key]['score'])
            ? (int) round($categories[$key]['score'] * 100)
            : 0;
    }

    private function parseAudit(array $audits, string $key, int $precision): ?float
    {
        return isset($audits[$key]['numericValue'])
            ? round((float) $audits[$key]['numericValue'], $precision)
            : null;
    }

    private function parseNumeric(mixed $value, int $precision): ?float
    {
        return is_numeric($value)
            ? round((float) $value, $precision)
            : null;
    }
}
