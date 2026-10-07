<?php

namespace Tests\Feature\Services;

use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\PageMetric;
use App\Models\Site;
use App\Models\Team;
use App\Services\AffiliateAuditService;
use App\Services\KpiCollector;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for AffiliateAuditService::detectForMonitor().
 *
 * Setup conventions
 * ─────────────────
 * • Monitor URL is always 'https://shop.example.com' → siteNameFromUrl() returns
 *   'shop.example.com' (ltrim www. from host).  All PageMetric rows use
 *   site = 'shop.example.com' so the query finds them.
 * • Monitor is linked to a Site via monitor->site_id.  The Site must have
 *   merchant_domains = ['amazon'] for the service to proceed past the guard.
 * • Site::factory() uses ScopedByTeam; we use withoutGlobalScopes() on
 *   Insight assertions as BrokenPageServiceTest does.
 *
 * DNS isolation
 * ─────────────
 * UrlSafetyValidator::isSafe() resolves hostnames. We inject a hermetic resolver
 * (any host → 8.8.8.8) in setUp() and restore it in tearDown(), identical to the
 * pattern used in BrokenPageServiceTest.
 *
 * HTML fakes
 * ──────────
 * Http::fake() returns minimal HTML strings containing <a href="..."> elements.
 * Domains are amazon.fr / amazon.com with fictional ASINs.  Page URLs are
 * https://shop.example.com/page-X.
 */
class AffiliateAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ──────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic DNS resolver: any hostname → public IP 8.8.8.8.
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
    // 1. Foreign-tag leak detected
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A page has 2 correct links (tag=correct-21) + 1 foreign link (tag=intrus-99).
     * The site->amazon_tag is 'correct-21' (explicit override).
     * Expect: 1 AFFILIATE_LEAK insight, foreign_tags contains 'intrus-99', leak_count=1.
     */
    public function test_detects_foreign_tag_leak(): void
    {
        [$team, $monitor] = $this->makeMonitor();

        $monitor->site->update(['amazon_tag' => 'correct-21']);

        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/page-1',
            'clicks' => 20,
            'impressions' => 500,
            'ctr' => 4.0,
            'position' => 5.0,
            'captured_at' => now(),
        ]);

        $html = '<html><body>'
            .'<a href="https://www.amazon.fr/dp/B001FAKEAA?tag=correct-21&linkCode=osi">Good 1</a>'
            .'<a href="https://www.amazon.fr/dp/B002FAKEAB?tag=correct-21&linkCode=osi">Good 2</a>'
            .'<a href="https://www.amazon.fr/dp/B003FAKEAC?tag=intrus-99&linkCode=osi">Leak</a>'
            .'</body></html>';

        Http::fake([
            'https://shop.example.com/page-1' => Http::response($html, 200),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::AFFILIATE_LEAK->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertContains('intrus-99', $insight->payload['foreign_tags']);
        $this->assertSame(1, $insight->payload['leak_count']);
        $this->assertSame(0, $insight->payload['untagged_count']);
        $this->assertSame('correct-21', $insight->payload['reference_tag']);
        $this->assertSame($team->id, $insight->team_id);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Untagged link detected
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A page has 1 Amazon link with NO tag= parameter.
     * Expect: 1 insight with untagged_count=1.
     */
    public function test_detects_untagged_link(): void
    {
        [, $monitor] = $this->makeMonitor();

        $monitor->site->update(['amazon_tag' => 'correct-21']);

        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/page-2',
            'clicks' => 10,
            'impressions' => 300,
            'ctr' => 3.33,
            'position' => 6.0,
            'captured_at' => now(),
        ]);

        $html = '<html><body>'
            .'<a href="https://www.amazon.fr/dp/B004FAKEAD">No tag here</a>'
            .'</body></html>';

        Http::fake([
            'https://shop.example.com/page-2' => Http::response($html, 200),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::AFFILIATE_LEAK->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame(1, $insight->payload['untagged_count']);
        $this->assertSame(0, $insight->payload['leak_count']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Auto-detection of dominant tag when site->amazon_tag is null
    // ──────────────────────────────────────────────────────────────────────

    /**
     * site->amazon_tag is null. One page has 3 links with 'dom-21' and 1 with 'other-21'.
     * The dominant tag 'dom-21' must become the reference, and 'other-21' is a leak.
     */
    public function test_auto_detects_dominant_tag_when_no_amazon_tag_set(): void
    {
        [, $monitor] = $this->makeMonitor();

        // Ensure amazon_tag is null (factory default is null, but be explicit).
        $monitor->site->update(['amazon_tag' => null]);

        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/page-3',
            'clicks' => 15,
            'impressions' => 400,
            'ctr' => 3.75,
            'position' => 4.0,
            'captured_at' => now(),
        ]);

        $html = '<html><body>'
            .'<a href="https://www.amazon.fr/dp/B005FAKEAE?tag=dom-21">Dom 1</a>'
            .'<a href="https://www.amazon.fr/dp/B006FAKEAF?tag=dom-21">Dom 2</a>'
            .'<a href="https://www.amazon.fr/dp/B007FAKEAG?tag=dom-21">Dom 3</a>'
            .'<a href="https://www.amazon.fr/dp/B008FAKEAH?tag=other-21">Leak</a>'
            .'</body></html>';

        Http::fake([
            'https://shop.example.com/page-3' => Http::response($html, 200),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::AFFILIATE_LEAK->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame('dom-21', $insight->payload['reference_tag']);
        $this->assertContains('other-21', $insight->payload['foreign_tags']);
        $this->assertSame(1, $insight->payload['leak_count']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Explicit amazon_tag overrides auto-detection
    // ──────────────────────────────────────────────────────────────────────

    /**
     * site->amazon_tag = 'forced-21'. The dominant tag in page HTML is 'dom-21'.
     * The reference must be 'forced-21' — so 'dom-21' links are treated as leaks.
     */
    public function test_explicit_amazon_tag_overrides_auto_detection(): void
    {
        [, $monitor] = $this->makeMonitor();

        $monitor->site->update(['amazon_tag' => 'forced-21']);

        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/page-4',
            'clicks' => 10,
            'impressions' => 200,
            'ctr' => 5.0,
            'position' => 3.0,
            'captured_at' => now(),
        ]);

        // 3 links with dom-21 (dominant by frequency) + 1 with forced-21 (the override).
        $html = '<html><body>'
            .'<a href="https://www.amazon.fr/dp/B009FAKEAI?tag=dom-21">Dom 1</a>'
            .'<a href="https://www.amazon.fr/dp/B010FAKEAJ?tag=dom-21">Dom 2</a>'
            .'<a href="https://www.amazon.fr/dp/B011FAKEAK?tag=dom-21">Dom 3</a>'
            .'<a href="https://www.amazon.fr/dp/B012FAKEAL?tag=forced-21">Correct</a>'
            .'</body></html>';

        Http::fake([
            'https://shop.example.com/page-4' => Http::response($html, 200),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::AFFILIATE_LEAK->value)
            ->first();

        $this->assertNotNull($insight);
        // Reference must be the explicit override, not the dominant frequency.
        $this->assertSame('forced-21', $insight->payload['reference_tag']);
        // All 3 'dom-21' links are leaks.
        $this->assertSame(3, $insight->payload['leak_count']);
        $this->assertContains('dom-21', $insight->payload['foreign_tags']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Amazon image CDN hosts are not counted as product links
    // ──────────────────────────────────────────────────────────────────────

    /**
     * HTML contains an <img src> from m.media-amazon.com (CDN) which happens to be
     * in an href on some themes, plus one real product link with a tag.
     * The image CDN href must NOT be counted as a product link.
     */
    public function test_excludes_media_amazon_images(): void
    {
        [, $monitor] = $this->makeMonitor();

        $monitor->site->update(['amazon_tag' => 'correct-21']);

        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/page-5',
            'clicks' => 10,
            'impressions' => 200,
            'ctr' => 5.0,
            'position' => 4.0,
            'captured_at' => now(),
        ]);

        // The CDN href has no tag and looks like an Amazon link — it must be skipped.
        // The product link has the correct tag — no anomaly should be created.
        $html = '<html><body>'
            .'<a href="https://m.media-amazon.com/images/I/71FAKEIMAGE.jpg">Product image</a>'
            .'<a href="https://www.amazon.fr/dp/B013FAKEAM?tag=correct-21">Real link</a>'
            .'</body></html>';

        Http::fake([
            'https://shop.example.com/page-5' => Http::response($html, 200),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        // No anomaly: the only real product link has the correct tag.
        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. HTML entities in href are decoded before tag extraction
    // ──────────────────────────────────────────────────────────────────────

    /**
     * WordPress renders query params with &amp; (and sometimes &#038;) instead of
     * bare &.  The service calls html_entity_decode() before extractAmazonLinks().
     * A tag in 'amazon.fr/dp/B0?tag=correct-21&amp;linkCode=osi&#038;th=1' must
     * be extracted as 'correct-21', not garbled.
     */
    public function test_decodes_html_entities_in_links(): void
    {
        [, $monitor] = $this->makeMonitor();

        $monitor->site->update(['amazon_tag' => 'correct-21']);

        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/page-6',
            'clicks' => 10,
            'impressions' => 200,
            'ctr' => 5.0,
            'position' => 4.0,
            'captured_at' => now(),
        ]);

        // Both &amp; and &#038; are used — this is real WordPress AAWP output.
        $html = '<html><body>'
            .'<a href="https://www.amazon.fr/dp/B014FAKEAN?tag=correct-21&amp;linkCode=osi&#038;th=1">WP Link</a>'
            .'</body></html>';

        Http::fake([
            'https://shop.example.com/page-6' => Http::response($html, 200),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        // Tag is correctly extracted as 'correct-21' → no anomaly.
        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. No anomaly → no insight
    // ──────────────────────────────────────────────────────────────────────

    /**
     * All Amazon links on all pages carry the correct reference tag.
     * Expect: return 0, no insights.
     */
    public function test_no_anomaly_creates_no_insight(): void
    {
        [, $monitor] = $this->makeMonitor();

        $monitor->site->update(['amazon_tag' => 'correct-21']);

        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/page-7',
            'clicks' => 10,
            'impressions' => 200,
            'ctr' => 5.0,
            'position' => 4.0,
            'captured_at' => now(),
        ]);

        $html = '<html><body>'
            .'<a href="https://www.amazon.fr/dp/B015FAKEAO?tag=correct-21">Link 1</a>'
            .'<a href="https://www.amazon.fr/dp/B016FAKEAP?tag=correct-21">Link 2</a>'
            .'</body></html>';

        Http::fake([
            'https://shop.example.com/page-7' => Http::response($html, 200),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. Pages below min_impressions threshold are skipped entirely
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A page with impressions < min_impressions must not be crawled and must not
     * produce any insight.  A second page with impressions >= min_impressions on
     * the same site IS crawled and produces the expected insight when a leak is
     * found.  This verifies the impressions-based selection criterion end-to-end.
     */
    public function test_skips_pages_below_min_impressions(): void
    {
        // Force the threshold to an explicit value so the test is not affected
        // by future changes to the env default.
        config(['monitoring.affiliate.min_impressions' => 10]);

        [$team, $monitor] = $this->makeMonitor();

        $monitor->site->update(['amazon_tag' => 'correct-21']);

        // Page A: impressions = 2 (below threshold) — must NOT be crawled.
        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/low-traffic',
            'clicks' => 0,
            'impressions' => 2,
            'ctr' => 0.0,
            'position' => 15.0,
            'captured_at' => now(),
        ]);

        // Page B: impressions = 100 (above threshold) — must BE crawled and
        // the foreign-tag leak on it must generate an AFFILIATE_LEAK insight.
        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/high-traffic',
            'clicks' => 30,
            'impressions' => 100,
            'ctr' => 30.0,
            'position' => 3.0,
            'captured_at' => now(),
        ]);

        Http::fake([
            // Low-traffic page must NEVER be requested.
            'https://shop.example.com/low-traffic' => Http::response('should not be called', 200),
            // High-traffic page has a foreign-tag leak.
            'https://shop.example.com/high-traffic' => Http::response(
                '<html><body>'
                .'<a href="https://www.amazon.fr/dp/B099FAKEBB?tag=intrus-99">Leak</a>'
                .'</body></html>',
                200,
            ),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        // Only the high-traffic page is audited; the low-traffic page is excluded.
        $this->assertSame(1, $count);

        // Confirm the low-traffic page URL was never requested.
        Http::assertNotSent(function ($request): bool {
            return str_contains((string) $request->url(), 'low-traffic');
        });

        // Confirm the insight relates to the high-traffic page, not the low one.
        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::AFFILIATE_LEAK->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame('https://shop.example.com/high-traffic', $insight->payload['page']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. Monitor without amazon in merchant_domains → skip
    // ──────────────────────────────────────────────────────────────────────

    /**
     * site->merchant_domains = ['awin'] — no amazon entry.
     * Expect: return 0, no HTTP request, no insight.
     */
    public function test_skips_monitor_without_amazon_merchant(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'merchant_domains' => ['awin'],
            'amazon_tag' => null,
        ]);
        $monitor = Monitor::factory()->create([
            'url' => 'https://shop.example.com',
            'team_id' => $team->id,
            'is_priority' => false,
            'site_id' => $site->id,
        ]);

        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/page-8',
            'clicks' => 20,
            'impressions' => 400,
            'ctr' => 5.0,
            'position' => 3.0,
            'captured_at' => now(),
        ]);

        Http::fake(); // No request must be sent.

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        Http::assertNothingSent();
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 11. Idempotence: second run replaces unacknowledged; acknowledged survives
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Two consecutive runs on the same monitor with the same leak must produce
     * exactly 1 unacknowledged AFFILIATE_LEAK insight (not 2).
     * A previously acknowledged insight must not be deleted.
     */
    public function test_idempotence_replaces_unacknowledged_insights(): void
    {
        [$team, $monitor] = $this->makeMonitor();

        $monitor->site->update(['amazon_tag' => 'correct-21']);

        // Pre-existing acknowledged AFFILIATE_LEAK — must survive both runs.
        $acknowledged = Insight::factory()
            ->acknowledged()
            ->ofType(InsightType::AFFILIATE_LEAK)
            ->create([
                'team_id' => $team->id,
                'monitor_id' => $monitor->id,
            ]);

        PageMetric::factory()->create([
            'site' => 'shop.example.com',
            'page' => 'https://shop.example.com/page-9',
            'clicks' => 20,
            'impressions' => 400,
            'ctr' => 5.0,
            'position' => 3.0,
            'captured_at' => now(),
        ]);

        $html = '<html><body>'
            .'<a href="https://www.amazon.fr/dp/B017FAKEAQ?tag=intrus-99">Leak</a>'
            .'</body></html>';

        Http::fake([
            'https://shop.example.com/page-9' => Http::response($html, 200),
        ]);

        $service = $this->makeService();

        // First run.
        $service->detectForMonitor($monitor);
        // Second run — must replace the unacknowledged insight, not add another.
        $service->detectForMonitor($monitor);

        $unacknowledgedCount = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::AFFILIATE_LEAK->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(
            1,
            $unacknowledgedCount,
            'Expected exactly 1 unacknowledged AFFILIATE_LEAK insight after 2 runs.',
        );

        // The acknowledged insight must have been preserved.
        $this->assertDatabaseHas('insights', ['id' => $acknowledged->id]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a Team + Site (with amazon in merchant_domains) + Monitor linked
     * to that site.  The monitor URL is 'https://shop.example.com' so that
     * siteNameFromUrl() returns 'shop.example.com', matching PageMetric rows
     * seeded with site = 'shop.example.com'.
     *
     * @return array{0: Team, 1: Monitor}
     */
    private function makeMonitor(): array
    {
        $team = Team::factory()->create();

        $site = Site::factory()->create([
            'team_id' => $team->id,
            'merchant_domains' => ['amazon'],
            'amazon_tag' => null,
        ]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://shop.example.com',
            'team_id' => $team->id,
            'is_priority' => false,
            'site_id' => $site->id,
        ]);

        return [$team, $monitor];
    }

    /**
     * Build AffiliateAuditService with a mocked KpiCollector whose
     * siteNameFromUrl() mirrors the real implementation: ltrim 'www.' from host.
     *
     * Pattern mirrors BrokenPageServiceTest::makeService().
     */
    private function makeService(): AffiliateAuditService
    {
        $collector = $this->mock(KpiCollector::class, function ($mock): void {
            $mock->shouldReceive('siteNameFromUrl')
                ->andReturnUsing(function (string $url): string {
                    $host = parse_url($url, PHP_URL_HOST) ?? $url;

                    return ltrim($host, 'www.');
                });
        });

        return new AffiliateAuditService($collector);
    }
}
