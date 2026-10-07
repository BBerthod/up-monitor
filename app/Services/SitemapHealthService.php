<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Support\SitemapChildSelector;
use App\Support\UrlSafetyValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Audits a site's sitemap for the two failure modes that starve discovery
 * without breaking anything visible: stale content and dead entries.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Sitemaps are the most repeatedly broken thing in this fleet, and every break
 * was found by hand, weeks late:
 *
 *  - A cached sitemap served <lastmod>2023-09-22</lastmod> in 2026 because the
 *    generated file had been committed to the repo and the deploy restored it
 *    on every push. Google was told nothing had changed for two and a half years.
 *  - Another site served a sitemap where 83% of the URLs answered 301 after a
 *    slug migration: the file was valid, well-formed, and pointed almost
 *    entirely at redirects.
 *
 * The existing SitemapChecker cannot catch either. It is opt-in per check, no
 * cron runs it across the fleet, it emits no insight, and — decisively — its
 * `urls_accessible` rule counts 301 and 302 as healthy, which is exactly the
 * signal that mattered in the second case.
 *
 * WHY 301 IS NOT HEALTHY HERE
 * ───────────────────────────
 * A redirect is a perfectly good response for a visitor and a bad one for a
 * sitemap. A sitemap is a statement of canonical URLs: every 301 in it means
 * Google is being handed an address the site itself says is wrong, spending
 * crawl budget to be told so. One or two are noise; a large share means the
 * sitemap was generated before a migration and never regenerated.
 *
 * SAMPLING
 * ────────
 * URLs are sampled, not exhaustively fetched: the ratio is what matters and a
 * sample of a few dozen answers it. HEAD is used to avoid pulling page bodies,
 * with a GET fallback for the servers that mishandle HEAD.
 */
class SitemapHealthService
{
    private const USER_AGENT = 'Up-Monitor/1.0 (+https://github.com/BBerthod/up)';

    /** Maximum recursion depth into sitemap index files. */
    private const MAX_DEPTH = 2;

    /**
     * Audit the site's sitemap and create at most one SITEMAP_HEALTH insight.
     *
     * @return int Number of insights created (0 or 1).
     */
    public function detectForSite(Site $site): int
    {
        $sitemapUrl = $site->resolvedSitemapUrl();

        if ($sitemapUrl === null) {
            return 0;
        }

        $siteScope = fn ($query) => $query->where('site_id', $site->id);

        $firstDetectedAt = Insight::firstDetectedAtForOpen(InsightType::SITEMAP_HEALTH, $siteScope);

        // Clear stale findings up-front so a fixed sitemap stops being reported
        // even when this run has nothing to say.
        Insight::openUnacknowledgedOfType(InsightType::SITEMAP_HEALTH, $siteScope)
            ->delete();

        if (! UrlSafetyValidator::isSafe($sitemapUrl)) {
            return 0;
        }

        $sampleSize = (int) config('monitoring.sitemap_health.sample_size', 40);

        // The accumulator replaces a flat array of every {loc, lastmod} pair: a
        // fleet sitemap with 577k URLs (407MB of XML across 63 leaf files) blew
        // through the 256MB worker memory limit in prod on 2026-09-15, because
        // array_merge() on each recursive step copies the whole array again.
        // Only three things are ever read back: the total count, the newest
        // lastmod, and a bounded random sample of URLs — so those are the only
        // three things kept, computed in one streaming pass over the tree.
        $stats = ['total' => 0, 'newest' => null, 'sample' => []];
        $budget = null;
        $this->streamEntries($sitemapUrl, $sampleSize, 0, $budget, $stats);

        if ($stats['total'] === 0) {
            return $this->raise(
                $site,
                InsightSeverity::CRITICAL,
                sprintf('Sitemap unreachable or empty: %s', $sitemapUrl),
                [
                    'sitemap' => $sitemapUrl,
                    'reason' => 'empty_or_unreachable',
                ],
                // A sitemap that yields nothing is a total discovery outage,
                // so it takes the top of the scale.
                100.0,
                $firstDetectedAt,
            );
        }

        $problems = [];
        $payload = [
            'sitemap' => $sitemapUrl,
            'total_urls' => $stats['total'],
        ];

        // ── Freshness ────────────────────────────────────────────────────────
        $staleness = $this->assessStaleness($stats['newest']);
        $payload = array_merge($payload, $staleness['payload']);

        if ($staleness['stale']) {
            $problems[] = $staleness['message'];
        }

        // ── Status ratio ─────────────────────────────────────────────────────
        $statuses = $this->sampleStatuses($stats['sample']);
        $payload = array_merge($payload, $statuses['payload']);

        if ($statuses['unhealthy']) {
            $problems[] = $statuses['message'];
        }

        if ($problems === []) {
            Log::info('SitemapHealthService: sitemap healthy', [
                'site_id' => $site->id,
                'sitemap' => $sitemapUrl,
                'urls' => $stats['total'],
            ]);

            return 0;
        }

        // An old lastmod on its own is not a fault: it can be exact on a site
        // whose content has not changed. Only report it as INFO while the
        // sampled URLs are healthy; redirects/broken URLs keep the real severity.
        if (! $statuses['unhealthy']) {
            $severity = InsightSeverity::INFO;
        } else {
            // Broken URLs (4xx/5xx) are a harder failure than redirects or
            // staleness: those entries lead nowhere at all.
            $severity = ($statuses['payload']['broken_ratio'] ?? 0.0) > 0.0
                || ($staleness['payload']['lastmod_age_days'] ?? 0) > (int) config('monitoring.sitemap_health.critical_age_days', 180)
                    ? InsightSeverity::CRITICAL
                    : InsightSeverity::WARNING;
        }

        return $this->raise(
            $site,
            $severity,
            sprintf('Sitemap issue on %s: %s', $site->primary_domain, implode(' — ', $problems)),
            $payload,
            // Rank on the share of the sitemap that is not answering canonically,
            // so a wholesale break outranks a handful of stragglers.
            round(max(
                ($statuses['payload']['redirect_ratio'] ?? 0.0) + ($statuses['payload']['broken_ratio'] ?? 0.0),
                // Staleness alone is a hint, ranked low.
                $staleness['stale'] ? 0.1 : 0.0,
            ) * 100, 2),
            $firstDetectedAt,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Persist a single SITEMAP_HEALTH insight.
     *
     * @param  array<string, mixed>  $payload
     */
    private function raise(
        Site $site,
        InsightSeverity $severity,
        string $title,
        array $payload,
        float $impactScore,
        ?\Illuminate\Support\Carbon $firstDetectedAt = null,
    ): int {
        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'monitor_id' => null,
            'type' => InsightType::SITEMAP_HEALTH->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => $payload,
            'impact_score' => $impactScore,
            'detected_at' => $firstDetectedAt ?? now(),
        ]);

        Log::warning('SitemapHealthService: issue detected', [
            'site_id' => $site->id,
            'severity' => $severity->value,
            'title' => $title,
        ]);

        return 1;
    }

    /**
     * Decide whether the sitemap's declared freshness is credible.
     *
     * Uses the NEWEST lastmod in the document: if the most recently modified
     * page in the whole sitemap is old, the file itself is stale. Looking at an
     * average or the oldest entry would flag any site with an archive.
     *
     * The newest date is now computed while streaming through leaf sitemaps
     * (see streamEntries()) instead of by re-scanning a collected array, so it
     * arrives here already resolved.
     *
     * @return array{stale: bool, message: string, payload: array<string, mixed>}
     */
    private function assessStaleness(?CarbonImmutable $newest): array
    {
        $maxAgeDays = (int) config('monitoring.sitemap_health.max_lastmod_age_days', 90);

        if ($newest === null) {
            // No lastmod anywhere is legal per the spec and common on hand-written
            // sitemaps, so it is reported but never treated as a fault.
            return [
                'stale' => false,
                'message' => '',
                'payload' => ['lastmod_present' => false],
            ];
        }

        // diffInDays() is signed; a future-dated lastmod would otherwise read as
        // a large negative age and silently pass. Clamp at zero.
        $ageDays = max(0, (int) $newest->diffInDays(now()));

        return [
            'stale' => $ageDays > $maxAgeDays,
            'message' => sprintf('newest lastmod is %d days old', $ageDays),
            'payload' => [
                'lastmod_present' => true,
                'newest_lastmod' => $newest->toIso8601String(),
                'lastmod_age_days' => $ageDays,
            ],
        ];
    }

    /**
     * Measure how many of an already-sampled set of URLs answer canonically.
     *
     * The sample is now built by reservoir sampling while streaming through
     * leaf sitemaps (see streamEntries()) so it never requires holding every
     * URL in memory at once to pick a uniform sample — it is already a
     * uniform sample of at most sample_size URLs by the time it gets here.
     *
     * @param  list<string>  $urls
     * @return array{unhealthy: bool, message: string, payload: array<string, mixed>}
     */
    private function sampleStatuses(array $urls): array
    {
        $ok = 0;
        $redirects = 0;
        $broken = 0;
        $checked = 0;
        $samples = [];

        foreach ($urls as $url) {
            if (! UrlSafetyValidator::isSafe($url)) {
                continue;
            }

            $status = $this->probeStatus($url);

            if ($status === null) {
                continue;
            }

            $checked++;

            if ($status >= 200 && $status < 300) {
                $ok++;
            } elseif ($status >= 300 && $status < 400) {
                $redirects++;
                $samples[] = ['url' => $url, 'status' => $status];
            } else {
                $broken++;
                $samples[] = ['url' => $url, 'status' => $status];
            }
        }

        if ($checked === 0) {
            return [
                'unhealthy' => false,
                'message' => '',
                'payload' => ['sampled' => 0],
            ];
        }

        $redirectRatio = $redirects / $checked;
        $brokenRatio = $broken / $checked;

        $maxRedirectRatio = (float) config('monitoring.sitemap_health.max_redirect_ratio', 0.2);
        $maxBrokenRatio = (float) config('monitoring.sitemap_health.max_broken_ratio', 0.05);

        $unhealthy = $redirectRatio > $maxRedirectRatio || $brokenRatio > $maxBrokenRatio;

        $parts = [];

        if ($redirectRatio > $maxRedirectRatio) {
            $parts[] = sprintf('%d%% of sampled URLs redirect', (int) round($redirectRatio * 100));
        }

        if ($brokenRatio > $maxBrokenRatio) {
            $parts[] = sprintf('%d%% of sampled URLs are broken', (int) round($brokenRatio * 100));
        }

        return [
            'unhealthy' => $unhealthy,
            'message' => implode(', ', $parts),
            'payload' => [
                'sampled' => $checked,
                'ok' => $ok,
                'redirects' => $redirects,
                'broken' => $broken,
                'redirect_ratio' => round($redirectRatio, 3),
                'broken_ratio' => round($brokenRatio, 3),
                'samples' => array_slice($samples, 0, 5),
            ],
        ];
    }

    /**
     * Probe a single URL without following redirects, so a 301 is observed as a
     * 301 rather than resolved into the 200 it eventually reaches.
     *
     * Falls back to GET when HEAD is refused: some servers (and several CDN
     * configurations in this fleet) answer 405 to HEAD while serving GET fine,
     * and treating that as a broken URL would be a false positive.
     */
    private function probeStatus(string $url): ?int
    {
        try {
            $status = Http::withoutRedirecting()
                ->timeout(10)
                ->connectTimeout(5)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->head($url)
                ->status();

            if ($status === 405 || $status === 501) {
                $status = Http::withoutRedirecting()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->get($url)
                    ->status();
            }

            return $status;
        } catch (\Throwable) {
            // Unreachable: counted as broken by the caller only if we return a
            // status, so return null to leave it out of the ratio rather than
            // let a transient network blip inflate the broken count.
            return null;
        }
    }

    /**
     * Walk a sitemap (following index files) and fold every leaf entry into
     * $stats without ever holding the full URL list in memory.
     *
     * Rationale: a fleet sitemap with 577k URLs across 63 leaf files (407MB of
     * XML) was previously collected into one flat PHP array — and array_merge()
     * on every recursive step copies the accumulated array again, doubling the
     * peak — which exhausted the 256MB worker memory_limit in prod on
     * 2026-09-15. Only three facts about the whole tree are ever used
     * downstream: the total URL count, the newest <lastmod>, and a bounded
     * random sample of <loc> values to probe — so those are the only things
     * kept, updated incrementally as each leaf sitemap is parsed and then
     * discarded. Each child's SimpleXMLElement/response body goes out of scope
     * (and is GC-eligible) as soon as its recursive call returns, instead of
     * being retained via an accumulated entries array.
     *
     * The URL sample is built with reservoir sampling (Algorithm R): each
     * <loc> has a uniform 1/n chance of ending up in the final sample of
     * $sampleSize URLs, regardless of how many million URLs follow it — no
     * need to see the whole list before picking who's in.
     *
     * Child traversal still uses one shared budget for the complete tree; if
     * an index exceeds it, children are selected evenly across the index
     * rather than from a biased prefix. Image sitemaps are still skipped: they
     * inflate counts and their URLs are not pages.
     *
     * @param  array{total: int, newest: ?CarbonImmutable, sample: list<string>}  $stats
     */
    private function streamEntries(
        string $sitemapUrl,
        int $sampleSize,
        int $depth,
        ?int &$budget,
        array &$stats,
    ): void {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($sitemapUrl);

            if (! $response->successful()) {
                Log::warning('SitemapHealthService: sitemap fetch returned non-2xx', [
                    'url' => $sitemapUrl,
                    'status' => $response->status(),
                ]);

                return;
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NONET);

            if ($xml === false) {
                return;
            }

            $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

            // Sitemap index: recurse into children.
            $children = $xml->xpath('//sm:sitemap/sm:loc') ?: $xml->xpath('//sitemap/loc') ?: [];

            if (! empty($children)) {
                $budget ??= (int) config('monitoring.sitemap_health.max_child_sitemaps', 1000);
                $childUrls = SitemapChildSelector::select($children, $budget);

                foreach ($childUrls as $childUrl) {
                    if ($budget <= 0) {
                        break;
                    }

                    if (! UrlSafetyValidator::isSafe($childUrl)) {
                        continue;
                    }

                    $budget--;
                    $this->streamEntries($childUrl, $sampleSize, $depth + 1, $budget, $stats);
                }

                return;
            }

            // Leaf sitemap: fold each <loc>/<lastmod> pair into the accumulator
            // instead of collecting them.
            $urlNodes = $xml->xpath('//sm:url') ?: $xml->xpath('//url') ?: [];

            foreach ($urlNodes as $node) {
                $loc = trim((string) ($node->loc ?? ''));

                if ($loc === '') {
                    continue;
                }

                $lastmodRaw = isset($node->lastmod) ? trim((string) $node->lastmod) : '';

                if ($lastmodRaw !== '') {
                    try {
                        $date = CarbonImmutable::parse($lastmodRaw);

                        if ($stats['newest'] === null || $date->greaterThan($stats['newest'])) {
                            $stats['newest'] = $date;
                        }
                    } catch (\Throwable) {
                        // Unparsable lastmod: ignored for freshness, same as before.
                    }
                }

                $stats['total']++;
                $this->addToReservoir($stats['sample'], $loc, $stats['total'], $sampleSize);
            }
        } catch (\Throwable $e) {
            Log::warning('SitemapHealthService: exception while parsing sitemap', [
                'url' => $sitemapUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reservoir sampling (Algorithm R): maintain a uniform random sample of at
     * most $sampleSize items out of a stream of unknown total length, without
     * ever holding more than $sampleSize items at once.
     *
     * @param  list<string>  $sample
     */
    private function addToReservoir(array &$sample, string $item, int $seenSoFar, int $sampleSize): void
    {
        if ($sampleSize <= 0) {
            return;
        }

        if (count($sample) < $sampleSize) {
            $sample[] = $item;

            return;
        }

        $j = random_int(1, $seenSoFar);

        if ($j <= $sampleSize) {
            $sample[$j - 1] = $item;
        }
    }
}
