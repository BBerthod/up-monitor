<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Services\CmpDetector;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for CmpDetector::detectForSite().
 *
 * Covers the two production situations: an AdSense site shipping no consent
 * platform at all (months of non-personalised ads, found by hand), and a site
 * shipping two, which is worse than none because they race for __tcfapi.
 */
class CmpDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        UrlSafetyValidator::setResolver(function (string $host, int $type): array {
            if ($type === DNS_A) {
                return [['ip' => '8.8.8.8']];
            }

            return [];
        });
    }

    protected function tearDown(): void
    {
        UrlSafetyValidator::setResolver(null);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function makeSite(?array $adNetworks = ['adsense'], ?Team $team = null): Site
    {
        return Site::factory()->create([
            'team_id' => ($team ?? Team::factory()->create())->id,
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'ad_networks' => $adNetworks,
            'is_active' => true,
        ]);
    }

    private function page(string $body = ''): string
    {
        return '<html><head>'.$body.'</head><body>content</body></html>';
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. The production case: AdSense running with no CMP at all
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_missing_cmp_on_ad_monetised_site(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/' => Http::response($this->page(
                '<script src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>',
            ), 200),
        ]);

        $count = (new CmpDetector)->detectForSite($site);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::CMP_MISSING->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertSame('no_cmp_detected', $insight->payload['reason']);
        $this->assertSame([], $insight->payload['vendors_found']);
    }

    public function test_consent_mode_v2_is_reported_as_a_warning_not_as_nothing_at_all(): void
    {
        // garden-site-a.fr and garden-site-b.fr gate adsbygoogle.js behind an
        // explicit choice and push gtag('consent', …). Verified in a browser with
        // cleared storage: no ad script loads until the visitor accepts. Calling
        // that "no consent platform, ads can only be non-personalised" was wrong
        // on both counts — but it is still not a certified CMP, which Google
        // requires to serve ads in the EEA, so it stays a finding.
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/' => Http::response($this->page(
                '<script>window.dataLayer=window.dataLayer||[];'
                ."function gtag(){dataLayer.push(arguments);}gtag('consent','default',{ad_storage:'denied'});"
                .'</script>',
            ), 200),
        ]);

        $this->assertSame(1, (new CmpDetector)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::CMP_MISSING->value)
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
        $this->assertSame('consent_mode_without_cmp', $insight->payload['reason']);
    }

    public function test_a_certified_cmp_still_wins_over_consent_mode(): void
    {
        // Both signals on one page is the normal shape for a Funding Choices
        // site: the vendor branch must take priority and stay silent.
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/' => Http::response($this->page(
                '<script src="https://fundingchoicesmessages.google.com/i/pub-1?ers=1"></script>'
                ."<script>gtag('consent','default',{ad_storage:'denied'});</script>",
            ), 200),
        ]);

        $this->assertSame(0, (new CmpDetector)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Funding Choices present → healthy, nothing raised
    // ──────────────────────────────────────────────────────────────────────

    public function test_funding_choices_present_creates_no_insight(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/' => Http::response($this->page(
                '<script src="https://fundingchoicesmessages.google.com/i/pub-123?ers=1"></script>',
            ), 200),
        ]);

        $this->assertSame(0, (new CmpDetector)->detectForSite($site));
        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::CMP_MISSING->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Two CMPs is a fault: they cancel each other out
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_multiple_consent_platforms(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/' => Http::response($this->page(
                '<script src="https://fundingchoicesmessages.google.com/i/pub-123"></script>'
                .'<script src="https://cdn.cookielaw.org/scripttemplates/otSDKStub.js"></script>',
            ), 200),
        ]);

        $this->assertSame(1, (new CmpDetector)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()->where('site_id', $site->id)->first();

        $this->assertSame('multiple_cmp_detected', $insight->payload['reason']);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertCount(2, $insight->payload['vendors_found']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Sites that run no ads are out of scope entirely
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_without_ad_networks_is_skipped(): void
    {
        $site = $this->makeSite(adNetworks: null);

        Http::fake();

        $this->assertSame(0, (new CmpDetector)->detectForSite($site));
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. One vendor matching several of its own needles is still one vendor
    // ──────────────────────────────────────────────────────────────────────

    public function test_single_vendor_matching_twice_is_not_reported_as_multiple(): void
    {
        $site = $this->makeSite();

        // Funding Choices has two fingerprints; a real page contains both.
        Http::fake([
            'https://example.com/' => Http::response($this->page(
                '<script src="https://fundingchoicesmessages.google.com/i/pub-123"></script>'
                .'<script>window.googlefc = window.googlefc || {};</script>',
            ), 200),
        ]);

        $this->assertSame(0, (new CmpDetector)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. An unreachable homepage says nothing about consent — stay silent
    // ──────────────────────────────────────────────────────────────────────

    public function test_unreachable_homepage_creates_no_insight(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/' => Http::response('Server Error', 500),
        ]);

        $this->assertSame(0, (new CmpDetector)->detectForSite($site));
        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::CMP_MISSING->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. Idempotence: fixing the site clears the previous finding
    // ──────────────────────────────────────────────────────────────────────

    public function test_previous_insight_is_cleared_once_cmp_is_installed(): void
    {
        $site = $this->makeSite();

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'type' => InsightType::CMP_MISSING->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'title' => 'stale finding',
            'payload' => [],
            'impact_score' => 100,
            'detected_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://example.com/' => Http::response($this->page(
                '<script src="https://fundingchoicesmessages.google.com/i/pub-123"></script>',
            ), 200),
        ]);

        (new CmpDetector)->detectForSite($site);

        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::CMP_MISSING->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. Non-Google vendors are recognised too
    // ──────────────────────────────────────────────────────────────────────

    public function test_recognises_third_party_cmp_vendors(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/' => Http::response($this->page(
                '<script src="https://consent.cookiebot.com/uc.js" data-cbid="abc"></script>',
            ), 200),
        ]);

        $this->assertSame(0, (new CmpDetector)->detectForSite($site));
    }
}
