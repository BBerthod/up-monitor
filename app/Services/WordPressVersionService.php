<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Detects WordPress sites running an outdated — or insecure — core release.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Most of this fleet is WordPress, and nothing in Up ever knew which version
 * any of it ran. Updates were tracked by hand and drifted for months at a time;
 * one site was found serving a core several minor versions behind, with no
 * signal anywhere that it had fallen behind at all.
 *
 * WHY IT READS THE LIVE SITE RATHER THAN THE REPO
 * ───────────────────────────────────────────────
 * These deployments vendor the WordPress core into the git repository, and the
 * container copies the repo over the webroot on boot. That makes the SERVED
 * version the only one that matters — updating in production without
 * re-vendoring is silently reverted by the next deploy, and the repo can just
 * as easily be ahead of production between a merge and a redeploy. Fingerprint
 * the live site and there is no gap to reason about.
 *
 * HOW THE VERSION IS FOUND
 * ────────────────────────
 * Three signals, in decreasing reliability, stopping at the first one that
 * passes the plausibility guard below:
 *  1. the generator meta tag, when the theme has not stripped it;
 *  2. the ver= query string on a SPECIFIC, known set of core assets
 *     (wp-emoji-release.min.js, wp-embed.min.js) — never a blanket match on
 *     everything under wp-includes/, which used to also catch bundled
 *     third-party libraries (jQuery, block-library dist bundles, …) served
 *     from the same directory and read THEIR version as the core's;
 *  3. the readme.html shipped at the docroot on default installs, read only
 *     from its header rather than anywhere in the page — the licence
 *     paragraph ("GNU General Public License) version 2") reads as a
 *     version number too, and used to be misread as one.
 *
 * None requires authentication, a plugin, or anything installed on the site.
 * A site that hides all three simply yields no verdict, which is reported as
 * an unknown rather than guessed at.
 *
 * PLAUSIBILITY GUARD
 * ───────────────────
 * Every candidate — from any of the three sources — is checked against the
 * upstream release list before being trusted: it must look like a WordPress
 * version, and either match a release WordPress.org still lists, or be no
 * older than the oldest release it still lists. This is what stops a
 * coincidental match (jQuery happening to be versioned "3.7.1", which was
 * really a WordPress release once, just never one this fleet could be
 * running) from becoming a false "outdated" finding. It does not fully
 * cover source #2 staying fresh — the ver= fallback still reads whatever the
 * server last rendered, which a page cache can serve stale — so it is kept
 * as the least reliable of the three, tried only when the generator tag is
 * gone.
 *
 * SEVERITY
 * ────────
 * Taken from WordPress.org's own classification rather than from version
 * arithmetic: their version-check endpoint states whether a release is
 * "insecure", "outdated" or current. Being three minors behind is not a
 * security problem by itself, and being one minor behind can be.
 */
class WordPressVersionService
{
    private const USER_AGENT = 'Up-Monitor/1.0 (+https://github.com/BBerthod/up-monitor)';

    /** WordPress.org core release feed. Free, unauthenticated. */
    private const CORE_VERSION_URL = 'https://api.wordpress.org/core/version-check/1.7/';

    /** Cache key for the upstream release list, shared across all sites. */
    private const RELEASES_CACHE_KEY = 'wordpress:core-releases';

    /**
     * Audit one site's WordPress version and create at most one insight.
     *
     * @return int Number of insights created (0 or 1).
     */
    public function detectForSite(Site $site): int
    {
        // Clear stale findings first so an updated site stops being reported.
        Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::OUTDATED_CMS->value)
            ->whereNull('acknowledged_at')
            ->delete();

        if ($site->type !== 'wordpress') {
            return 0;
        }

        $domain = $site->resolvedPrimaryDomain();

        if ($domain === '') {
            return 0;
        }

        $url = 'https://'.rtrim($domain, '/').'/';

        if (! UrlSafetyValidator::isSafe($url)) {
            return 0;
        }

        // Fetched first: every fingerprint candidate is validated against this
        // list before being trusted, so there is nothing to fingerprint towards
        // until it is available.
        $releases = $this->coreReleases();

        if ($releases === []) {
            return 0;
        }

        $installed = $this->detectInstalledVersion($url, $releases);

        if ($installed === null) {
            // Nothing to say: a hardened site that strips every fingerprint, or
            // one that only yields implausible candidates, is not a finding —
            // guessing would be worse than silence.
            Log::info('WordPressVersionService: version not detectable', [
                'site_id' => $site->id,
                'url' => $url,
            ]);

            return 0;
        }

        $latest = $releases['latest'];
        $status = $this->classify($installed, $releases);

        if ($status === 'current') {
            Log::info('WordPressVersionService: core is current', [
                'site_id' => $site->id,
                'version' => $installed,
            ]);

            return 0;
        }

        $severity = $status === 'insecure'
            ? InsightSeverity::CRITICAL
            : InsightSeverity::WARNING;

        $title = $status === 'insecure'
            ? sprintf(
                'WordPress %s on %s is flagged insecure upstream (latest: %s)',
                $installed,
                $site->primary_domain,
                $latest,
            )
            : sprintf(
                'WordPress %s on %s is behind the latest release (%s)',
                $installed,
                $site->primary_domain,
                $latest,
            );

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'monitor_id' => null,
            'type' => InsightType::OUTDATED_CMS->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => [
                'cms' => 'wordpress',
                'installed_version' => $installed,
                'latest_version' => $latest,
                'status' => $status,
                'url' => $url,
                // Named explicitly because it decides HOW to fix this: on a
                // vendored core, updating in production is reverted by the next
                // deploy, so the repo has to be re-vendored instead.
                'remediation' => 'If the core is vendored in the repository, re-vendor it there — '
                    .'updating in production alone will be reverted by the next deploy.',
            ],
            // Insecure outranks merely outdated; both stay below a hard outage.
            'impact_score' => $status === 'insecure' ? 90.0 : 40.0,
            'detected_at' => now(),
        ]);

        Log::warning('WordPressVersionService: outdated core detected', [
            'site_id' => $site->id,
            'installed' => $installed,
            'latest' => $latest,
            'status' => $status,
        ]);

        return 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Core assets whose ver= query string reliably tracks the exact core
     * version, because WordPress enqueues them with get_bloginfo('version')
     * rather than their own release number. Deliberately narrow: a blanket
     * match on wp-includes/…?ver= also catches bundled third-party libraries
     * served from the same directory (jQuery, Underscore, the block-library
     * dist bundles, …), whose OWN version has nothing to do with the core's.
     */
    private const CORE_ASSET_VERSION_PATTERN =
        '#wp-includes/js/wp-(?:emoji-release|embed(?:-template)?)\.min\.js\?ver=([0-9]+\.[0-9]+(?:\.[0-9]+)?)#i';

    /**
     * Fingerprint the WordPress version actually being served.
     *
     * @param  array{latest: string, insecure: list<string>, known: list<string>}  $releases
     */
    private function detectInstalledVersion(string $url, array $releases): ?string
    {
        try {
            $response = Http::timeout(20)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
        } catch (\Throwable $e) {
            Log::debug('WordPressVersionService: fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // 1. Generator meta tag — explicit and unambiguous when present.
        if (preg_match(
            '/<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress\s+([0-9.]+)["\']/i',
            $html,
            $match,
        ) && ($version = $this->plausible($match[1], $releases)) !== null) {
            return $version;
        }

        // Same tag with the attributes the other way round: both orders occur
        // in the wild and a single-order regex would miss half of them.
        if (preg_match(
            '/<meta[^>]+content=["\']WordPress\s+([0-9.]+)["\'][^>]+name=["\']generator["\']/i',
            $html,
            $match,
        ) && ($version = $this->plausible($match[1], $releases)) !== null) {
            return $version;
        }

        // 2. A specific, known set of core asset query strings — see the
        //    constant above for why this cannot be a blanket wp-includes/ match.
        //
        //    Narrowing the pattern was not enough to make this source usable.
        //    Every site in this fleet sits behind a page cache, so the HTML
        //    carries whatever ver= was current when the page was stored, not
        //    what the core is now: campsite.fr runs 7.0.3 and still serves
        //    an emoji asset stamped 6.9.4. Worse, a stale stamp is always a
        //    real past release, so the plausibility guard waves it through and
        //    the reading looks trustworthy while being a lie about the one
        //    thing this detector exists to answer.
        //
        //    So it stays a hint, never a verdict: it can confirm the generator
        //    tag, never stand in for it. Alerting on a cached asset would
        //    reintroduce the false "outdated" this whole change removes.
        if (preg_match(self::CORE_ASSET_VERSION_PATTERN, $html, $match)) {
            Log::debug('WordPressVersionService: core asset ver= seen but not trusted', [
                'url' => $url,
                'asset_version' => $match[1],
            ]);
        }

        // 3. readme.html, present on default installs.
        $fromReadme = $this->versionFromReadme($url);

        return $fromReadme !== null ? $this->plausible($fromReadme, $releases) : null;
    }

    /**
     * Read the version from the docroot readme.html header, when it is still
     * served — never from anywhere else in the document.
     *
     * WHY THE HEADER ONLY
     * ────────────────────
     * The rest of the readme is prose. Its licence paragraph reads
     * "released under the terms of the GPL (GNU General Public License)
     * version 2 or (at your option) any later version" — an unanchored
     * search for "Version N" matches that "2" before it ever reaches
     * anything meaningful, which is exactly the false "WordPress 2" this
     * fallback used to produce.
     */
    private function versionFromReadme(string $baseUrl): ?string
    {
        $readmeUrl = rtrim($baseUrl, '/').'/readme.html';

        if (! UrlSafetyValidator::isSafe($readmeUrl)) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($readmeUrl);

            if (! $response->successful()) {
                return null;
            }

            return $this->extractVersionFromReadme($response->body());
        } catch (\Throwable) {
            // Absent readme is the norm on hardened installs — not an error.
        }

        return null;
    }

    /**
     * Extract a version from the readme's masthead ("<h1>WordPress</h1>"
     * followed shortly by "Version X.Y.Z"), rejecting anything caught near
     * mentions of the GPL — belt and suspenders for installs whose readme
     * layout does not match the anchor above.
     */
    private function extractVersionFromReadme(string $html): ?string
    {
        if (! preg_match(
            '/<h1[^>]*>\s*WordPress\s*<\/h1>.{0,200}?Version\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)\b/is',
            $html,
            $match,
        )) {
            return null;
        }

        if (preg_match('/GNU|General Public License|GPL/i', $match[0])) {
            return null;
        }

        return $this->normaliseVersion($match[1]);
    }

    /**
     * Validate a candidate version against the upstream release list before
     * trusting it. Returns the normalised version when plausible, null
     * otherwise — the caller falls through to the next, less reliable source.
     *
     * @param  array{latest: string, insecure: list<string>, known: list<string>}  $releases
     */
    private function plausible(string $candidate, array $releases): ?string
    {
        $version = $this->normaliseVersion($candidate);

        if (! preg_match('/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $version)) {
            return null;
        }

        if (in_array($version, $releases['known'], true)) {
            return $version;
        }

        // Not an exact match against the (short) list of releases
        // WordPress.org currently offers upgrades for — still plausible if
        // it is not older than the oldest branch that list covers. This is
        // what lets a genuinely outdated site (several minors behind, so
        // absent from the short upstream list) still get flagged, while
        // rejecting a stray number with no relation to any real release
        // WordPress.org still remembers (a bundled library's own version,
        // a licence number, …).
        $oldestKnown = $this->oldestMajorMinor($releases['known']);

        return $oldestKnown !== null && version_compare($version, $oldestKnown, '>=')
            ? $version
            : null;
    }

    /**
     * Oldest major.minor branch present in the upstream release list.
     *
     * @param  list<string>  $known
     */
    private function oldestMajorMinor(array $known): ?string
    {
        if ($known === []) {
            return null;
        }

        $majorMinors = array_map(
            static fn (string $version): string => implode('.', array_slice(explode('.', $version), 0, 2)),
            $known,
        );

        usort($majorMinors, 'version_compare');

        return $majorMinors[0];
    }

    /**
     * Fetch the upstream release list.
     *
     * Cached for a day and shared across every site: the answer is identical
     * for all of them, and WordPress ships releases on the order of weeks.
     *
     * @return array{latest: string, insecure: list<string>, known: list<string>}|array{}
     */
    private function coreReleases(): array
    {
        return Cache::remember(self::RELEASES_CACHE_KEY, now()->addDay(), function (): array {
            try {
                $response = Http::timeout(20)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->get(self::CORE_VERSION_URL);

                if (! $response->successful()) {
                    return [];
                }

                $offers = $response->json('offers') ?? [];

                if ($offers === []) {
                    return [];
                }

                $latest = null;
                $insecure = [];
                $known = [];

                foreach ($offers as $offer) {
                    $version = $offer['current'] ?? $offer['version'] ?? null;

                    if (! is_string($version)) {
                        continue;
                    }

                    $known[] = $version;

                    // The endpoint's own classification, which is the point of
                    // calling it: "insecure" is a security judgement we cannot
                    // make from version numbers alone.
                    if (($offer['response'] ?? null) === 'insecure') {
                        $insecure[] = $version;
                    }

                    // The first offer is the current release.
                    $latest ??= $version;
                }

                if ($latest === null) {
                    return [];
                }

                return [
                    'latest' => $latest,
                    'insecure' => array_values(array_unique($insecure)),
                    'known' => array_values(array_unique($known)),
                ];
            } catch (\Throwable $e) {
                Log::warning('WordPressVersionService: release list fetch failed', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Classify an installed version against the upstream release list.
     *
     * @param  array{latest: string, insecure: list<string>, known: list<string>}  $releases
     * @return 'current'|'outdated'|'insecure'
     */
    private function classify(string $installed, array $releases): string
    {
        if (in_array($installed, $releases['insecure'], true)) {
            return 'insecure';
        }

        // version_compare handles WordPress's scheme correctly, including the
        // "6.9" vs "6.9.1" case where a naive string compare gets it backwards.
        if (version_compare($installed, $releases['latest'], '>=')) {
            return 'current';
        }

        return 'outdated';
    }

    /**
     * Trim a version string to its numeric core.
     */
    private function normaliseVersion(string $version): string
    {
        return trim(rtrim(trim($version), '.'));
    }
}
