<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies that a multi-locale site's hreflang annotations are reciprocal.
 *
 * WHY THIS EXISTS
 * ───────────────
 * One site in this fleet served hreflang tags that pointed only at themselves —
 * every locale declared "I am the German version" and named no siblings. Google
 * treats a one-way annotation as no annotation, so the locales competed instead
 * of clustering, and the wrong one surfaced in the wrong market. It was found by
 * hand, months later, and nothing in Up would have caught it: the pages were up,
 * fast, and individually well formed.
 *
 * THE RULES CHECKED
 * ─────────────────
 * Google's contract for hreflang is reciprocity: if page A names B as its
 * French version, B must name A back. Three failure modes follow from it, and
 * this service checks each:
 *
 *  1. MISSING — a locale ships no hreflang at all.
 *  2. SELF-ONLY — a locale names itself and nothing else (the case above).
 *  3. NON-RECIPROCAL — A names B, but B does not name A.
 *
 * x-default is reported when absent but never treated as a fault: it is a
 * recommendation, not a requirement, and plenty of correct setups omit it.
 *
 * WHY THE HOMEPAGE ONLY
 * ─────────────────────
 * hreflang is emitted by the same template on every page, so a homepage that
 * gets it right almost always gets it right everywhere — and a homepage that
 * gets it wrong is broken everywhere. Auditing one page per locale keeps the
 * cost at one request per locale per day while catching the systemic case,
 * which is the only case that has ever occurred here.
 */
class HreflangAuditService
{
    private const USER_AGENT = 'Up-Monitor/1.0 (+https://github.com/BBerthod/up)';

    /**
     * Audit a site's hreflang reciprocity and create at most one insight.
     *
     * @return int Number of insights created (0 or 1).
     */
    public function detectForSite(Site $site): int
    {
        // Clear stale findings first so a fixed site stops being reported even
        // when this run has nothing to say.
        Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::HREFLANG_BROKEN->value)
            ->whereNull('acknowledged_at')
            ->delete();

        $locales = $this->localeUrls($site);

        // A single-locale site has nothing to reciprocate with.
        if (count($locales) < 2) {
            return 0;
        }

        $observed = [];

        foreach ($locales as $locale => $url) {
            $annotations = $this->fetchAnnotations($url);

            if ($annotations === null) {
                // Unreachable: an availability problem, already someone else's
                // alert, and no evidence either way about hreflang.
                continue;
            }

            $observed[$locale] = $annotations;
        }

        if (count($observed) < 2) {
            return 0;
        }

        $problems = $this->findProblems($observed, $locales);

        if ($problems === []) {
            Log::info('HreflangAuditService: hreflang is reciprocal', [
                'site_id' => $site->id,
                'locales' => array_keys($observed),
            ]);

            return 0;
        }

        // Missing and self-only annotations mean the cluster does not exist at
        // all; a one-way link between otherwise annotated pages is a narrower
        // defect that degrades rather than disables.
        $systemic = collect($problems)
            ->contains(fn (array $p): bool => in_array($p['type'], ['missing', 'self_only'], true));

        return $this->raise(
            $site,
            $systemic ? InsightSeverity::CRITICAL : InsightSeverity::WARNING,
            $problems,
            array_keys($observed),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the homepage URL for each of the site's locales.
     *
     * Only template domains (containing "{locale}") can be expanded per locale;
     * a site whose locales live on paths rather than subdomains is out of scope
     * here and returns fewer than two URLs, which short-circuits the audit.
     *
     * @return array<string, string> locale => absolute URL
     */
    private function localeUrls(Site $site): array
    {
        $locales = $site->locales;

        if (! is_array($locales) || count($locales) < 2) {
            return [];
        }

        $template = $site->domains[0] ?? null;

        if (! is_string($template) || ! str_contains($template, '{locale}')) {
            return [];
        }

        $urls = [];

        foreach ($locales as $locale) {
            $locale = (string) $locale;
            $host = str_replace('{locale}', $locale, $template);
            $urls[$locale] = 'https://'.rtrim($host, '/').'/';
        }

        return $urls;
    }

    /**
     * Fetch a page and return its hreflang annotations.
     *
     * @return array<string, string>|null hreflang value => href, or null when
     *                                    the page could not be fetched.
     */
    private function fetchAnnotations(string $url): ?array
    {
        if (! UrlSafetyValidator::isSafe($url)) {
            return null;
        }

        try {
            $response = Http::timeout(20)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            return $this->extractHreflang($response->body());
        } catch (\Throwable $e) {
            Log::debug('HreflangAuditService: fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract hreflang annotations from HTML.
     *
     * Matches <link> tags carrying both rel="alternate" and hreflang, in either
     * attribute order — templates emit both and a fixed-order regex would miss
     * half the real world.
     *
     * @return array<string, string>
     */
    private function extractHreflang(string $html): array
    {
        if (! preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
            return [];
        }

        $annotations = [];

        foreach ($tags[0] as $tag) {
            if (! preg_match('/\brel\s*=\s*["\']?alternate["\']?/i', $tag)) {
                continue;
            }

            if (! preg_match('/\bhreflang\s*=\s*["\']([^"\']+)["\']/i', $tag, $langMatch)) {
                continue;
            }

            if (! preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                continue;
            }

            $annotations[strtolower(trim($langMatch[1]))] = trim($hrefMatch[1]);
        }

        return $annotations;
    }

    /**
     * Compare the observed annotations against the reciprocity contract.
     *
     * @param  array<string, array<string, string>>  $observed  locale => (hreflang => href)
     * @param  array<string, string>  $localeUrls  locale => canonical URL
     * @return list<array{type: string, locale: string, detail: string}>
     */
    private function findProblems(array $observed, array $localeUrls): array
    {
        $problems = [];

        foreach ($observed as $locale => $annotations) {
            if ($annotations === []) {
                $problems[] = [
                    'type' => 'missing',
                    'locale' => $locale,
                    'detail' => 'no hreflang annotations found',
                ];

                continue;
            }

            // Strip x-default before counting siblings: it is a fallback
            // pointer, not a locale, and counting it would make a self-only
            // page look like it names two.
            $siblings = array_diff_key($annotations, ['x-default' => true]);

            if (count($siblings) <= 1) {
                $problems[] = [
                    'type' => 'self_only',
                    'locale' => $locale,
                    'detail' => 'declares only itself — Google treats a one-way annotation as none',
                ];

                continue;
            }

            // Reciprocity: every sibling this locale names must name it back.
            foreach ($observed as $otherLocale => $otherAnnotations) {
                if ($otherLocale === $locale) {
                    continue;
                }

                $namesOther = $this->annotationsPointAt($annotations, $localeUrls[$otherLocale] ?? '');
                $otherNamesUs = $this->annotationsPointAt($otherAnnotations, $localeUrls[$locale] ?? '');

                if ($namesOther && ! $otherNamesUs) {
                    $problems[] = [
                        'type' => 'non_reciprocal',
                        'locale' => $locale,
                        'detail' => "names {$otherLocale}, but {$otherLocale} does not name it back",
                    ];
                }
            }
        }

        return $problems;
    }

    /**
     * Do these annotations point at the given URL?
     *
     * Compared on host alone: templates differ on trailing slashes, http vs
     * https and www prefixes without any of it changing which page is meant,
     * and treating those as mismatches would report reciprocity failures that
     * do not exist.
     *
     * @param  array<string, string>  $annotations
     */
    private function annotationsPointAt(array $annotations, string $targetUrl): bool
    {
        $targetHost = $this->normalisedHost($targetUrl);

        if ($targetHost === '') {
            return false;
        }

        foreach ($annotations as $hreflang => $href) {
            if ($hreflang === 'x-default') {
                continue;
            }

            if ($this->normalisedHost($href) === $targetHost) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase host without a www. prefix.
     *
     * NOTE: uses str_starts_with + substr rather than ltrim — ltrim strips a
     * character SET, so ltrim($host, 'www.') turns "webcompare.com" into
     * "ebcompare.com". This exact trap is documented elsewhere in Up.
     */
    private function normalisedHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return '';
        }

        $host = strtolower($host);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Persist a single HREFLANG_BROKEN insight.
     *
     * @param  list<array{type: string, locale: string, detail: string}>  $problems
     * @param  list<string>  $audited
     */
    private function raise(Site $site, InsightSeverity $severity, array $problems, array $audited): int
    {
        $first = $problems[0];

        $title = count($problems) === 1
            ? sprintf('Hreflang issue on %s (%s): %s', $site->primary_domain, $first['locale'], $first['detail'])
            : sprintf(
                'Hreflang issues on %s across %d locales — worst: %s %s',
                $site->primary_domain,
                count(array_unique(array_column($problems, 'locale'))),
                $first['locale'],
                $first['detail'],
            );

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'monitor_id' => null,
            'type' => InsightType::HREFLANG_BROKEN->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => [
                'locales_audited' => $audited,
                'problems' => $problems,
            ],
            // Scale with how much of the cluster is affected: a single one-way
            // link is a smaller problem than every locale being isolated.
            'impact_score' => round(
                min(100, count(array_unique(array_column($problems, 'locale'))) / max(1, count($audited)) * 100),
                2,
            ),
            'detected_at' => now(),
        ]);

        Log::warning('HreflangAuditService: hreflang problems detected', [
            'site_id' => $site->id,
            'problems' => count($problems),
        ]);

        return 1;
    }
}
