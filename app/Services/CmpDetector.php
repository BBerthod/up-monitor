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
 * Verifies that ad-monetised sites actually ship a consent management platform,
 * and only one.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Three sites in this fleet ran AdSense for months with no published consent
 * message. Ads kept rendering, so nothing looked broken — but without consent
 * they can only be non-personalised, which is a large and permanent haircut on
 * revenue. It was found by hand, long after the fact.
 *
 * Up already knew which sites run ads: Site::ad_networks is captured in the UI,
 * persisted, and exposed through the API. No service had ever read it. This is
 * the first consumer of that field.
 *
 * WHY TWO CMPs IS ALSO A FAULT
 * ────────────────────────────
 * Stacking two consent platforms on one page is worse than having none: they
 * race to install the same __tcfapi global and the survivor reports
 * cmpStatus:null, so downstream ad code sees no consent signal at all. This was
 * observed on one site in this fleet. The detector therefore flags both the
 * absence of a CMP and the presence of more than one.
 *
 * WHAT THIS CAN AND CANNOT SEE
 * ────────────────────────────
 * A plain HTTP fetch never executes JavaScript, so we cannot call __tcfapi and
 * ask for its status — the DOM check documented as authoritative elsewhere is a
 * browser-side technique. What we CAN do reliably is detect the loader scripts
 * that install those APIs, which is a strictly weaker but still decisive signal:
 * if no known CMP loader appears anywhere in the served HTML, no CMP can
 * possibly initialise. False negatives are possible (a CMP injected by another
 * script we do not recognise); false positives are not, which is the right way
 * round for something that raises alerts.
 */
class CmpDetector
{
    private const USER_AGENT = 'Up-Monitor/1.0 (+https://github.com/BBerthod/up-monitor)';

    /**
     * Known CMP loader fingerprints, keyed by vendor.
     *
     * Matched against the raw HTML. Each needle is a script path or global that
     * only appears when that vendor's loader is present.
     *
     * @var array<string, array<int, string>>
     */
    private const CMP_FINGERPRINTS = [
        // Google Funding Choices / Privacy & Messaging — what AdSense sites use.
        'funding_choices' => [
            'fundingchoicesmessages.google.com',
            'googlefc',
        ],
        'quantcast' => ['quantcast.mgr.consensu.org', 'cmp.quantcast'],
        'cookiebot' => ['consent.cookiebot.com'],
        'onetrust' => ['cdn.cookielaw.org', 'onetrust'],
        'didomi' => ['sdk.privacy-center.org', 'didomi'],
        'axeptio' => ['static.axept.io'],
        'complianz' => ['complianz'],
        'cookieyes' => ['cdn-cookieyes.com'],
        'iubenda' => ['cdn.iubenda.com'],
        'tarteaucitron' => ['tarteaucitron'],
    ];

    /**
     * Audit a site's consent setup and create at most one CMP_MISSING insight.
     *
     * @return int Number of insights created (0 or 1).
     */
    public function detectForSite(Site $site): int
    {
        // Clear stale findings first so a fixed site stops being reported even
        // when this run has nothing to say.
        Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::CMP_MISSING->value)
            ->whereNull('acknowledged_at')
            ->delete();

        if (! $this->runsAds($site)) {
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

        try {
            $response = Http::timeout(20)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($url);

            if (! $response->successful()) {
                // An unreachable homepage is somebody else's alert (uptime already
                // covers it) and says nothing about consent, so stay silent.
                return 0;
            }

            $found = $this->detectVendors($response->body());
        } catch (\Throwable $e) {
            Log::warning('CmpDetector: fetch failed', [
                'site_id' => $site->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if (count($found) === 1) {
            Log::info('CmpDetector: consent platform present', [
                'site_id' => $site->id,
                'vendor' => $found[0],
            ]);

            return 0;
        }

        if ($found === []) {
            // A hand-rolled banner wired to Consent Mode v2 is not nothing, and
            // calling it nothing was wrong: garden-site-a.fr and garden-site-b.fr
            // both gate adsbygoogle.js behind an explicit choice and push
            // gtag('consent', …) — verified in a browser with cleared storage, no
            // ad script loads until the visitor accepts. Reporting that as "no
            // consent platform, ads can only be non-personalised" was false on both
            // counts, and a CRITICAL that cannot be actioned is the kind of alert
            // people learn to scroll past.
            //
            // It is still not a certified CMP, which Google requires to serve ads
            // in the EEA and UK, so this stays a finding — just an accurate one at
            // a severity that matches what is actually at stake.
            if ($this->hasConsentModeV2($response->body())) {
                return $this->raise(
                    $site,
                    InsightSeverity::WARNING,
                    sprintf(
                        'Consent Mode v2 on %s but no certified CMP — Google requires one to serve ads in the EEA',
                        $domain,
                    ),
                    [
                        'url' => $url,
                        'reason' => 'consent_mode_without_cmp',
                        'ad_networks' => $site->ad_networks,
                        'vendors_found' => [],
                    ],
                    50.0,
                );
            }

            return $this->raise(
                $site,
                InsightSeverity::CRITICAL,
                sprintf(
                    'No consent platform detected on %s — ads served here can only be non-personalised',
                    $domain,
                ),
                [
                    'url' => $url,
                    'reason' => 'no_cmp_detected',
                    'ad_networks' => $site->ad_networks,
                    'vendors_found' => [],
                ],
                100.0,
            );
        }

        return $this->raise(
            $site,
            InsightSeverity::CRITICAL,
            sprintf(
                'Multiple consent platforms on %s (%s) — they race for __tcfapi and cancel each other out',
                $domain,
                implode(', ', $found),
            ),
            [
                'url' => $url,
                'reason' => 'multiple_cmp_detected',
                'ad_networks' => $site->ad_networks,
                'vendors_found' => $found,
            ],
            100.0,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * True when this site declares at least one advertising network, i.e. it has
     * inventory whose revenue depends on consent.
     */
    private function runsAds(Site $site): bool
    {
        return is_array($site->ad_networks) && $site->ad_networks !== [];
    }

    /**
     * Return the CMP vendors whose loader fingerprints appear in the HTML.
     *
     * @return array<int, string>
     */
    private function detectVendors(string $html): array
    {
        $haystack = strtolower($html);
        $found = [];

        foreach (self::CMP_FINGERPRINTS as $vendor => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, strtolower($needle))) {
                    $found[] = $vendor;

                    // One hit is enough to establish this vendor; move on so a
                    // vendor matching two of its own needles is not double-counted
                    // and mistaken for a second platform.
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Is Google Consent Mode v2 wired into the page?
     *
     * The signature is a gtag('consent', 'default'|'update', …) call, which the
     * seedr sites emit inline before gtag.js loads — quoting varies, and the
     * argument may be minified onto one line, so match the call shape rather
     * than any one spelling of it.
     *
     * This says the page speaks Google's consent protocol. It does not say a
     * certified CMP is installed, and the caller must not treat it as one.
     */
    private function hasConsentModeV2(string $html): bool
    {
        return preg_match(
            '/gtag\s*\(\s*[\'"]consent[\'"]\s*,\s*[\'"](?:default|update)[\'"]/i',
            $html,
        ) === 1;
    }

    /**
     * Persist a single CMP_MISSING insight.
     *
     * @param  array<string, mixed>  $payload
     */
    private function raise(
        Site $site,
        InsightSeverity $severity,
        string $title,
        array $payload,
        float $impactScore,
    ): int {
        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'monitor_id' => null,
            'type' => InsightType::CMP_MISSING->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => $payload,
            'impact_score' => $impactScore,
            'detected_at' => now(),
        ]);

        Log::warning('CmpDetector: consent issue detected', [
            'site_id' => $site->id,
            'reason' => $payload['reason'] ?? null,
        ]);

        return 1;
    }
}
