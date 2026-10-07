<?php

namespace App\Services\Checkers\Functional;

use App\DTOs\FunctionalResult;
use App\Models\FunctionalCheck;
use App\Support\SitemapChildSelector;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\Http;

class SitemapChecker
{
    private const USER_AGENT = 'Up-Monitor/1.0 (+https://github.com/BBerthod/up)';

    /**
     * How many index levels to follow before giving up.
     *
     * Two is what this fleet actually uses (examplestore: root → locale → part),
     * three leaves room without letting a malformed or self-referential index
     * walk the checker down forever.
     */
    private const MAX_INDEX_DEPTH = 3;

    public function check(FunctionalCheck $check): FunctionalResult
    {
        $startTime = microtime(true);
        $url = $check->resolveUrl();

        try {
            $response = Http::timeout(30)->connectTimeout(10)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($url);

            $body = $response->body();
            $xml = null;
            $urls = [];
            $urlCount = 0;

            try {
                $xml = new \SimpleXMLElement($body);
                ['urls' => $urls, 'count' => $urlCount] = $this->extractUrls($xml);
            } catch (\Throwable) {
                // handled by is_valid_xml rule
            }

            $details = [];
            $passed = true;

            foreach ($check->rules as $rule) {
                $detail = $this->applyRule($rule, $body, $xml, $urls, $urlCount, $check);
                $details[] = $detail;
                if (! $detail['passed']) {
                    $passed = false;
                }
            }

            return new FunctionalResult(
                passed: $passed,
                durationMs: (int) ((microtime(true) - $startTime) * 1000),
                details: $details,
            );
        } catch (\Throwable $e) {
            return new FunctionalResult(
                passed: false,
                durationMs: (int) ((microtime(true) - $startTime) * 1000),
                details: [],
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Extract page URLs from a sitemap, following one level of
     * <sitemapindex> if that's what the root turns out to be.
     *
     * WHY THIS EXISTS
     * ────────────────
     * A <sitemapindex> holds no <url> elements at all — only <sitemap><loc>
     * entries pointing at the real, per-section sitemaps. Reading it with a
     * <url>/<loc> xpath (the only thing this checker used to try) always
     * finds zero URLs, so `min_urls` failed permanently on any site using an
     * index — which is the common case for anything past a few thousand
     * pages. fr.examplestore.com and us.examplestore.com sat on two incidents open
     * since 2026-03-22 for exactly this: a perfectly healthy, daily-refreshed
     * index that this checker was structurally unable to see past.
     *
     * @return array{urls: list<string>, count: int}
     */
    private function extractUrls(\SimpleXMLElement $xml): array
    {
        $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $sitemapElements = $xml->xpath('//sm:sitemap/sm:loc') ?: $xml->xpath('//sitemap/loc') ?: [];

        if ($sitemapElements !== []) {
            return $this->extractUrlsFromIndex($sitemapElements);
        }

        $urlElements = $xml->xpath('//sm:url/sm:loc') ?: $xml->xpath('//url/loc') ?: [];
        $urls = array_map(fn ($el) => (string) $el, $urlElements);

        return ['urls' => $urls, 'count' => count($urls)];
    }

    /**
     * Follow the child sitemaps of an index and sum their URLs.
     *
     * Bounded on two axes so a check never turns into a crawl: at most
     * `max_child_sitemaps` children are fetched (shared config with
     * SitemapHealthService, which follows the same index structure), each
     * with a short timeout. Image sitemaps are skipped entirely — they list
     * media assets, not pages, so counting them would misrepresent the site.
     *
     * If every child fetch fails, the index itself is still proof the
     * sitemap is alive: fall back to counting its (non-image) child entries
     * rather than reporting zero, which would fail `min_urls` on a sitemap
     * that is actually fine and simply unreachable from wherever this ran.
     *
     * NESTING IS NOT HYPOTHETICAL
     * ───────────────────────────
     * An index may point at another index. Every examplestore locale does exactly
     * that — sitemap.xml lists sitemap-{locale}.xml, which lists
     * sitemap-{locale}-N.xml, and only that third file holds any <url>. A
     * single-level descent reads zero there and fails a healthy sitemap, which
     * is the same false negative this method exists to remove, one level down.
     *
     * @param  \SimpleXMLElement[]  $sitemapElements
     * @param  int|null  $budget  Fetches left for the whole descent, by reference.
     * @return array{urls: list<string>, count: int}
     */
    private function extractUrlsFromIndex(array $sitemapElements, int $depth = 0, ?int &$budget = null): array
    {
        // One budget for the entire tree, not per level: nesting multiplies
        // levels together, so a per-level cap is how a check becomes a crawl.
        $budget ??= (int) config('monitoring.sitemap_health.max_child_sitemaps', 1000);
        $childLocs = SitemapChildSelector::select($sitemapElements, $budget);

        $urls = [];
        $fetchedAny = false;

        foreach ($childLocs as $childUrl) {
            if ($budget <= 0) {
                break;
            }

            if (! UrlSafetyValidator::isSafe($childUrl)) {
                continue;
            }

            $budget--;
            $childUrls = $this->fetchChildSitemapUrls($childUrl, $depth, $budget);

            if ($childUrls === null) {
                continue;
            }

            $fetchedAny = true;
            $urls = array_merge($urls, $childUrls);
        }

        if (! $fetchedAny) {
            return ['urls' => [], 'count' => count($childLocs)];
        }

        return ['urls' => $urls, 'count' => count($urls)];
    }

    /**
     * Fetch one child sitemap, descending again when it turns out to be
     * another index rather than a list of pages.
     *
     * @param  int|null  $budget  Shared fetch budget for the descent, by reference.
     * @return list<string>|null Null when the child sitemap could not be fetched or parsed.
     */
    private function fetchChildSitemapUrls(string $url, int $depth = 0, ?int &$budget = null): ?array
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $childXml = new \SimpleXMLElement($response->body());
            $childXml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

            $urlElements = $childXml->xpath('//sm:url/sm:loc') ?: $childXml->xpath('//url/loc') ?: [];

            if ($urlElements !== []) {
                return array_map(fn ($el) => (string) $el, $urlElements);
            }

            // No <url> here: either another index to descend into, or an
            // genuinely empty sitemap, which returns an empty list either way.
            $nested = $childXml->xpath('//sm:sitemap/sm:loc') ?: $childXml->xpath('//sitemap/loc') ?: [];

            if ($nested !== [] && $depth + 1 < self::MAX_INDEX_DEPTH) {
                return $this->extractUrlsFromIndex($nested, $depth + 1, $budget)['urls'];
            }

            return [];
        } catch (\Throwable) {
            return null;
        }
    }

    private function applyRule(array $rule, string $body, mixed $xml, array $urls, int $urlCount, FunctionalCheck $check): array
    {
        return match ($rule['type']) {
            'is_valid_xml' => [
                'rule' => 'is_valid_xml',
                'passed' => $xml !== null,
                'message' => $xml !== null ? 'Valid XML' : 'Invalid XML or empty response',
            ],
            'min_urls' => [
                'rule' => 'min_urls',
                'value' => $rule['value'],
                'passed' => $urlCount >= (int) $rule['value'],
                'message' => $urlCount.' URLs found (min: '.$rule['value'].')',
            ],
            'urls_accessible' => [
                'rule' => 'urls_accessible',
                'value' => $rule['value'] ?? 20,
                'passed' => $this->checkUrlsSample($urls, (int) ($rule['value'] ?? 20)),
                'message' => 'Sample of '.min(count($urls), (int) ($rule['value'] ?? 20)).' URLs checked',
            ],
            'track_changes' => $this->trackChanges($urls, $check),
            default => [
                'rule' => $rule['type'],
                'passed' => false,
                'message' => "Unknown rule type: {$rule['type']}",
            ],
        };
    }

    /**
     * Are the sampled sitemap URLs reachable AND canonical?
     *
     * WHY 3xx IS NOT ACCEPTED
     * ───────────────────────
     * This rule used to treat 301 and 302 as healthy, which made it structurally
     * blind to the most common sitemap failure in this fleet: after a slug
     * migration a site served a sitemap whose URLs were 83% redirects, and this
     * check reported it green the whole time.
     *
     * A redirect is a fine answer to a visitor and a bad one inside a sitemap.
     * A sitemap declares canonical URLs; a 3xx entry hands the crawler an address
     * the site itself says is wrong. 304 stays accepted — it is a cache
     * revalidation of a resource that does exist at that URL, not a relocation.
     *
     * A single redirect is normal churn, so the rule tolerates a configurable
     * share before failing rather than failing on the first one.
     */
    private function checkUrlsSample(array $urls, int $sample): bool
    {
        $slice = array_slice($urls, 0, $sample);

        if ($slice === []) {
            return true;
        }

        $checked = 0;
        $bad = 0;

        foreach ($slice as $url) {
            try {
                // withoutRedirecting(): observe the hop rather than resolving it,
                // otherwise a 301 is silently reported as the 200 it lands on.
                $status = Http::withoutRedirecting()->timeout(10)->head($url)->status();
                $checked++;

                if (! in_array($status, [200, 204, 304], true)) {
                    $bad++;
                }
            } catch (\Throwable) {
                $checked++;
                $bad++;
            }
        }

        if ($checked === 0) {
            return true;
        }

        $maxBadRatio = (float) config('monitoring.sitemap_health.max_redirect_ratio', 0.2);

        return ($bad / $checked) <= $maxBadRatio;
    }

    /**
     * Report what changed in the sitemap since the previous run.
     *
     * INFORMATIONAL, NEVER FAILING
     * ─────────────────────────────
     * This rule used to return `passed: !$changed`, i.e. ANY diff failed the check.
     * On an editorial site that publishes regularly the sitemap changes on nearly
     * every run, so the check failed continuously: three consecutive failures opened
     * a FUNCTIONAL incident, and because the sitemap kept moving it never returned to
     * PASSED, so the incident never resolved. altipiani-corse.com sat "active" for
     * 14 days this way. Nothing was broken — the site was simply publishing.
     *
     * A growing sitemap is healthy; it is the opposite signal that matters, and that
     * is already covered by the sibling rules (`min_urls` catches mass disappearance,
     * `urls_accessible` catches dead entries, `is_valid_xml` catches corruption).
     * So this rule now always passes and only records the diff, which stays visible
     * in the check details for auditing.
     */
    private function trackChanges(array $currentUrls, FunctionalCheck $check): array
    {
        $lastResult = $check->results()->latest('checked_at')->first();

        if (! $lastResult) {
            return [
                'rule' => 'track_changes',
                'passed' => true,
                'message' => 'Baseline established ('.count($currentUrls).' URLs)',
                'urls' => $currentUrls,
            ];
        }

        $previousUrls = collect($lastResult->details)
            ->firstWhere('rule', 'track_changes')['urls'] ?? [];

        $added = array_values(array_diff($currentUrls, $previousUrls));
        $removed = array_values(array_diff($previousUrls, $currentUrls));
        $changed = count($added) > 0 || count($removed) > 0;

        return [
            'rule' => 'track_changes',
            // Always true: see the docblock. A sitemap diff is an observation, not a fault.
            'passed' => true,
            'changed' => $changed,
            'message' => $changed
                ? count($added).' URL(s) added, '.count($removed).' URL(s) removed'
                : 'No changes ('.count($currentUrls).' URLs)',
            'urls' => $currentUrls,
            'added' => $added,
            'removed' => $removed,
        ];
    }
}
