<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Support\CircuitBreaker;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks domain registration expiry via RDAP and persists DOMAIN_EXPIRY insights.
 *
 * RDAP PROTOCOL
 * ─────────────
 * GET https://rdap.org/domain/{domain}
 * rdap.org acts as a bootstrapper: it follows the IANA registry to redirect the
 * request to the correct TLD registry's RDAP endpoint. Laravel's Http client
 * follows redirects transparently.
 *
 * The JSON response contains an "events" array. We look for the entry whose
 * "eventAction" is "expiration" and parse "eventDate" (ISO 8601).
 *
 * NOT ALL TLDs EXPOSE EXPIRY
 * ──────────────────────────
 * Some registries (notably AFNIC for .fr, some ccTLD operators) either omit the
 * expiration event entirely or return a non-standard date format. When the
 * expiration event is absent, checkDomain() returns null and the site's
 * domain_expires_at is left as-is (domain_expiry_checked_at is still updated
 * so we do not re-probe too soon).
 *
 * DOMAIN EXTRACTION
 * ─────────────────
 * We extract the registrable domain from primary_domain by stripping a leading
 * "www." prefix. More complex subdomains (e.g. fr.example.com → example.com) are
 * NOT handled: we assume Radiank sites use flat domains (webcompare.fr, jokes.example).
 * A proper Public Suffix List implementation would be needed for arbitrary subdomain
 * depths — out of scope here.
 *
 * IDEMPOTENCE
 * ───────────
 * Before inserting a new DOMAIN_EXPIRY insight we delete all existing
 * unacknowledged ones for the same site, so each daily run produces a clean,
 * current picture without accumulating duplicates.
 *
 * CIRCUIT BREAKER
 * ───────────────
 * rdap.org is an external dependency. We wrap each call with CircuitBreaker
 * (key "rdap_{domain}") so a failing TLD endpoint does not hammer the service.
 */
class DomainExpiryService
{
    /**
     * Look up the expiration date for a registrable domain via RDAP.
     *
     * Returns a Carbon instance (UTC) when the expiration event is present in the
     * RDAP response, or null when:
     *   - the circuit breaker is open for this domain
     *   - the HTTP request fails or times out
     *   - the registry does not include an expiration event
     *   - the date string cannot be parsed
     */
    public function checkDomain(string $domain): ?Carbon
    {
        $cbKey = "rdap_{$domain}";

        if (CircuitBreaker::isOpen($cbKey)) {
            Log::warning('DomainExpiryService: circuit open, skipping RDAP lookup', [
                'domain' => $domain,
            ]);

            return null;
        }

        try {
            $response = Http::timeout(10)
                ->get("https://rdap.org/domain/{$domain}");

            if (! $response->successful()) {
                Log::warning('DomainExpiryService: RDAP non-2xx response', [
                    'domain' => $domain,
                    'status' => $response->status(),
                ]);

                CircuitBreaker::recordFailure($cbKey);

                return null;
            }

            CircuitBreaker::recordSuccess($cbKey);

            $data = $response->json();
            $events = $data['events'] ?? [];

            foreach ($events as $event) {
                if (($event['eventAction'] ?? '') === 'expiration') {
                    $dateStr = $event['eventDate'] ?? null;

                    if ($dateStr === null) {
                        return null;
                    }

                    return Carbon::parse($dateStr)->utc();
                }
            }

            // Registry responded successfully but did not include an expiration
            // event — this is normal for certain TLDs (e.g. .fr via AFNIC).
            Log::info('DomainExpiryService: no expiration event in RDAP response', [
                'domain' => $domain,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('DomainExpiryService: RDAP request failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            CircuitBreaker::recordFailure($cbKey);

            return null;
        }
    }

    /**
     * Run expiry detection for a single site.
     *
     * Steps:
     *   1. Extract the registrable domain from primary_domain.
     *   2. Query RDAP for the expiry date and persist it on the site.
     *   3. Calculate days remaining (negative = already expired).
     *   4. Create or clear DOMAIN_EXPIRY insights based on thresholds.
     *
     * Thresholds:
     *   - ≤ 7 days OR already expired  → CRITICAL
     *   - ≤ 30 days                    → WARNING
     *   - > 30 days (healthy)          → delete any prior unacknowledged insight
     *
     * Returns 1 when an insight was created, 0 otherwise.
     */
    public function detectForSite(Site $site): int
    {
        $domain = $this->extractRegistrableDomain($site->primary_domain ?? '');

        if ($domain === '') {
            Log::warning('DomainExpiryService: cannot extract domain from site', [
                'site_id' => $site->id,
                'primary_domain' => $site->primary_domain,
            ]);

            return 0;
        }

        $expiresAt = $this->checkDomain($domain);

        // Always stamp the check time so the scheduler knows this site was visited,
        // even when the RDAP lookup returned null.
        $site->domain_expiry_checked_at = now();

        if ($expiresAt !== null) {
            $site->domain_expires_at = $expiresAt;
        }

        $site->save();

        // No expiry data available from RDAP — cannot make an insight decision.
        if ($expiresAt === null) {
            return 0;
        }

        // Use startOfDay() on both sides to get a clean integer day count.
        // Carbon::diffInDays() returns an unsigned integer, so we derive the sign
        // manually: negative means the expiry is in the past (already expired).
        $today = now()->startOfDay();
        $expiryDay = $expiresAt->copy()->startOfDay();
        $daysRemaining = $today->lte($expiryDay)
            ? (int) $today->diffInDays($expiryDay)
            : -(int) $expiryDay->diffInDays($today);

        $severity = $this->resolveSeverity($daysRemaining);

        if ($severity === null) {
            // Domain is healthy (> 30 days away) — remove any stale insight.
            Insight::withoutGlobalScopes()
                ->where('site_id', $site->id)
                ->where('type', InsightType::DOMAIN_EXPIRY->value)
                ->whereNull('acknowledged_at')
                ->delete();

            return 0;
        }

        // Idempotence: delete existing unacknowledged insight before re-creating.
        Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::DOMAIN_EXPIRY->value)
            ->whereNull('acknowledged_at')
            ->delete();

        $title = $daysRemaining <= 0
            ? "Domain {$domain} has EXPIRED"
            : "Domain {$domain} expires in {$daysRemaining} day".($daysRemaining === 1 ? '' : 's');

        // impact_score rises as the deadline approaches:
        //   - 100 days out  → ~70
        //   - 30 days out   → ~91
        //   - 7 days out    → ~97.9
        //   - 0 (expired)   → 100
        // Formula: min(100, max(0, 100 - days_remaining * 0.3))
        $impactScore = min(100, max(0, 100 - max($daysRemaining, 0) * 0.3));

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $domain,
            'site_id' => $site->id,
            'server_id' => null,
            'monitor_id' => null,
            'type' => InsightType::DOMAIN_EXPIRY->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => [
                'domain' => $domain,
                'expires_at' => $expiresAt->toIso8601String(),
                'days_remaining' => $daysRemaining,
                'threshold' => $daysRemaining <= 7 ? 'critical_7d' : 'warning_30d',
            ],
            'impact_score' => $impactScore,
            'detected_at' => now(),
        ]);

        Log::info('DomainExpiryService: insight created', [
            'site_id' => $site->id,
            'domain' => $domain,
            'days_remaining' => $daysRemaining,
            'severity' => $severity->value,
        ]);

        return 1;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the registrable domain from a raw domain string.
     *
     * Strips a leading "www." prefix and returns the remainder lowercased.
     *
     * LIMITATION: This does NOT implement a Public Suffix List (PSL).
     * For Radiank's flat-domain sites (example.fr, webcompare.com) this is
     * sufficient.  Nested subdomains (fr.example.co.uk) are left as-is, which
     * means the RDAP lookup will target the full subdomain — RDAP servers
     * typically respond with the parent domain's record anyway, but this is
     * not guaranteed for all registries.
     */
    private function extractRegistrableDomain(string $rawDomain): string
    {
        $domain = trim(strtolower($rawDomain));

        // Strip scheme and path if a full URL was accidentally stored.
        if (str_contains($domain, '://')) {
            $parsed = parse_url($domain);
            $domain = $parsed['host'] ?? $domain;
        }

        // Strip leading www.
        $domain = preg_replace('/^www\./i', '', $domain) ?? $domain;

        return $domain;
    }

    /**
     * Resolve the insight severity based on days remaining until expiry.
     *
     * Returns null when the domain is healthy (> 30 days).
     */
    private function resolveSeverity(int $daysRemaining): ?InsightSeverity
    {
        if ($daysRemaining <= 7) {
            return InsightSeverity::CRITICAL;
        }

        if ($daysRemaining <= 30) {
            return InsightSeverity::WARNING;
        }

        return null;
    }
}
