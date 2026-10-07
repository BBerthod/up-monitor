<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\PageMetric;
use App\Models\Site;
use App\Support\SitemapChildSelector;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds published pages that search has never seen.
 *
 * WHY CONTENT DECAY CANNOT FIND THESE
 * ───────────────────────────────────
 * ContentDecayService compares a page against its own past and skips anything
 * whose baseline is zero — deliberately, since a page going from 0 to 5 clicks
 * is noise rather than decay. The consequence is that a page which has NEVER
 * had traffic is invisible to it by construction: there is nothing to compare.
 *
 * So the pages that get attention are the ones that used to work. The ones that
 * never worked at all — written, published, indexed or not, and silently
 * generating nothing — are exactly the ones nobody hears about. On sites with
 * hundreds of product pages that is where the wasted effort accumulates.
 *
 * HOW THEY ARE FOUND
 * ──────────────────
 * The sitemap is the site's own claim about what it publishes; page_metrics is
 * what Search Console reports. A URL present in the first and absent from the
 * second, over a window long enough to rule out a page that is merely new, is a
 * zombie.
 *
 * WHY THE ABSENCE IS TRUSTWORTHY
 * ──────────────────────────────
 * page_metrics only stores pages above an impressions floor, so absence means
 * "below the floor" rather than a literal zero. That is the right reading here:
 * a page nobody sees and a page five people see are the same problem, and the
 * fix is the same too.
 *
 * NOT AN ERROR, NOT URGENT
 * ────────────────────────
 * A zombie page is wasted effort, not lost revenue: nothing is broken and no
 * money stops. It is reported as a single WARNING listing the worst offenders,
 * never per page — a hundred separate insights would bury everything else in
 * the inbox to say one thing.
 */
class ZombiePageService
{
    private const USER_AGENT = 'Up-Monitor/1.0 (+https://github.com/BBerthod/up)';

    /**
     * Detect zombie pages for a site and create at most one insight.
     *
     * @return int Number of insights created (0 or 1).
     */
    public function detectForSite(Site $site): int
    {
        $siteScope = fn ($query) => $query->where('site_id', $site->id);

        $firstDetectedAt = Insight::firstDetectedAtForOpen(InsightType::ZOMBIE_PAGE, $siteScope);

        // Clear stale findings first so a fixed site stops being reported.
        Insight::openUnacknowledgedOfType(InsightType::ZOMBIE_PAGE, $siteScope)
            ->delete();

        $sitemapUrl = $this->sitemapUrl($site);

        if ($sitemapUrl === null) {
            return 0;
        }

        $minHistoryDays = (int) config('monitoring.zombie_pages.min_history_days', 30);

        // A site whose tracking only started last week cannot distinguish a
        // zombie from a page that has not had time to rank yet.
        $oldestSnapshot = PageMetric::where('site', $site->primary_domain)->min('captured_at');

        // abs(): diffInDays() is SIGNED, so a snapshot 45 days in the past
        // returns -45, which is below any positive threshold and would reject
        // every site forever. This exact trap is documented elsewhere in Up.
        $historyDays = $oldestSnapshot === null
            ? 0
            : (int) abs(now()->diffInDays($oldestSnapshot));

        if ($oldestSnapshot === null || $historyDays < $minHistoryDays) {
            Log::info('ZombiePageService: not enough history yet', [
                'site_id' => $site->id,
                'history_days' => $historyDays,
                'min_history_days' => $minHistoryDays,
            ]);

            return 0;
        }

        $published = $this->sitemapUrls($sitemapUrl);

        if ($published === []) {
            return 0;
        }

        // Programmatic catalogues: de-indexation there is a known state, not a
        // finding per site.
        if (count($published) > (int) config('monitoring.zombie_pages.max_published', 1000)) {
            return 0;
        }

        // Every page GSC has reported on at any point in the window. Using the
        // whole window rather than the latest run matters: a page that ranked
        // last month and dropped out is content decay's business, not ours.
        $seen = PageMetric::where('site', $site->primary_domain)
            ->where('captured_at', '>=', now()->subDays($minHistoryDays))
            ->distinct()
            ->pluck('page')
            ->map(fn (string $page): string => $this->normalise($page))
            ->flip();

        $zombies = [];

        foreach ($published as $url) {
            if (! $seen->has($this->normalise($url))) {
                $zombies[] = $url;
            }
        }

        if ($zombies === []) {
            Log::info('ZombiePageService: every published page has visibility', [
                'site_id' => $site->id,
                'published' => count($published),
            ]);

            return 0;
        }

        $ratio = count($zombies) / count($published);
        $minRatio = (float) config('monitoring.zombie_pages.min_ratio', 0.5);
        $minCount = (int) config('monitoring.zombie_pages.min_count', 5);

        // Both floors must be cleared. Ratio alone would fire on a three-page
        // site with one quiet page; count alone would fire on a large site where
        // the same absolute number is a rounding error.
        if ($ratio < $minRatio || count($zombies) < $minCount) {
            return 0;
        }

        $sample = array_slice($zombies, 0, (int) config('monitoring.zombie_pages.sample_size', 10));

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'monitor_id' => null,
            'type' => InsightType::ZOMBIE_PAGE->value,
            // Never CRITICAL: nothing is broken and no revenue stops. This is
            // effort that produced nothing, which is worth a look and never a
            // page in the middle of the night.
            'severity' => InsightSeverity::WARNING->value,
            'title' => sprintf(
                '%d of %d published pages on %s have no search visibility',
                count($zombies),
                count($published),
                $site->primary_domain,
            ),
            'payload' => [
                'zombie_count' => count($zombies),
                'published_count' => count($published),
                'ratio' => round($ratio, 3),
                'window_days' => $minHistoryDays,
                'sample' => $sample,
            ],
            // Scale on the share of the site affected: half a catalogue earning
            // nothing is a different problem from a handful of stragglers.
            'impact_score' => round(min(100, $ratio * 100), 2),
            'detected_at' => $firstDetectedAt ?? now(),
        ]);

        Log::warning('ZombiePageService: zombie pages detected', [
            'site_id' => $site->id,
            'zombies' => count($zombies),
            'published' => count($published),
        ]);

        return 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Absolute sitemap URL for this site, or null when none is configured.
     */
    private function sitemapUrl(Site $site): ?string
    {
        $path = $site->sitemap_path;

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $domain = $site->resolvedPrimaryDomain();

        return $domain === ''
            ? null
            : 'https://'.rtrim($domain, '/').'/'.ltrim($path, '/');
    }

    /**
     * Collect page URLs from a sitemap, following index files one level.
     *
     * @return array<int, string>
     */
    private function sitemapUrls(string $sitemapUrl, int $depth = 0, ?int &$budget = null): array
    {
        if ($depth > 2 || ! UrlSafetyValidator::isSafe($sitemapUrl)) {
            return [];
        }

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($sitemapUrl);

            if (! $response->successful()) {
                return [];
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NONET);

            if ($xml === false) {
                return [];
            }

            $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

            $children = $xml->xpath('//sm:sitemap/sm:loc') ?: $xml->xpath('//sitemap/loc') ?: [];

            if (! empty($children)) {
                $urls = [];
                $budget ??= (int) config('monitoring.zombie_pages.max_child_sitemaps', 1000);
                $childUrls = SitemapChildSelector::select($children, $budget);

                foreach ($childUrls as $childUrl) {
                    if ($budget <= 0) {
                        break;
                    }

                    if (! UrlSafetyValidator::isSafe($childUrl)) {
                        continue;
                    }

                    $budget--;
                    $urls = array_merge($urls, $this->sitemapUrls($childUrl, $depth + 1, $budget));
                }

                return $urls;
            }

            $locs = $xml->xpath('//sm:url/sm:loc') ?: $xml->xpath('//url/loc') ?: [];

            return array_values(array_filter(array_map(
                static fn ($loc): string => trim((string) $loc),
                $locs,
            )));
        } catch (\Throwable $e) {
            Log::warning('ZombiePageService: sitemap fetch failed', [
                'url' => $sitemapUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Canonical form of a URL for comparison.
     *
     * GSC and sitemaps disagree about trailing slashes and www without either
     * meaning a different page, and treating those as distinct would report
     * every page on the site as a zombie.
     */
    private function normalise(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $host = strtolower($parts['host']);

        // str_starts_with + substr rather than ltrim: ltrim strips a character
        // SET, so ltrim($host, 'www.') turns "webcompare.com" into "ebcompare.com".
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $path = rtrim($parts['path'] ?? '/', '/');

        return $host.($path === '' ? '' : $path);
    }
}
