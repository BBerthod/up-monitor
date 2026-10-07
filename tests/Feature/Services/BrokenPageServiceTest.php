<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\PageMetric;
use App\Models\Team;
use App\Services\BrokenPageService;
use App\Services\KpiCollector;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for BrokenPageService::detectForMonitor().
 *
 * DNS isolation strategy
 * ──────────────────────
 * UrlSafetyValidator::isSafe() resolves hostnames via dns_get_record() before any
 * outbound HTTP call.  In CI or restricted networks, DNS resolution for
 * "example.com" may succeed (it's a real, publicly routable domain) but we cannot
 * guarantee it.  To make every test hermetic we inject a fake resolver via
 * UrlSafetyValidator::setResolver() that returns a synthetic public IP (8.8.8.8)
 * for any host.  setUp() installs the fake resolver; tearDown() restores the real
 * one so other test suites are unaffected.
 *
 * Http::fake() pattern
 * ────────────────────
 * BrokenPageService calls Http::get($page) where $page is the full URL stored in
 * page_metrics.page.  We fake exact URLs with wildcard globs where needed.
 * Http::fake() must be called BEFORE the service runs (Laravel resolves fakes
 * lazily on first use, but registering them early avoids ordering surprises).
 *
 * Monitor URL convention: 'https://example.com' → site = 'example.com'
 * All PageMetric rows must use site = 'example.com' to match.
 */
class BrokenPageServiceTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // Test lifecycle
    // ──────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Inject a hermetic DNS resolver: any hostname → 8.8.8.8 (public IP).
        // This prevents real network calls in UrlSafetyValidator and makes every
        // test reliable regardless of CI network access.
        UrlSafetyValidator::setResolver(function (string $host, int $type): array {
            if ($type === DNS_A) {
                return [['ip' => '8.8.8.8']];
            }

            return [];
        });
    }

    protected function tearDown(): void
    {
        // Restore real DNS resolution for other test classes.
        UrlSafetyValidator::setResolver(null);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Broken page (404) with high traffic → CRITICAL insight
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_broken_page_with_traffic(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'is_priority' => false,
        ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => 'https://example.com/broken',
            'clicks' => 80,
            'impressions' => 2000,
            'ctr' => round(80 / 2000 * 100, 4),
            'position' => 3.5,
            'captured_at' => now(),
        ]);

        Http::fake([
            'https://example.com/broken' => Http::response('Not Found', 404),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::REVENUE_AT_RISK->value)
            ->first();

        $this->assertNotNull($insight);
        // 80 clicks >= 50 high_traffic_clicks threshold → CRITICAL
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertEquals($team->id, $insight->team_id);
        // impact_score = monthly clicks = 80
        $this->assertEquals(80, (int) $insight->impact_score);
        $this->assertEquals(404, $insight->payload['status_code']);
        $this->assertEquals('https://example.com/broken', $insight->payload['page']);
        $this->assertEquals(80, $insight->payload['clicks']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Healthy page (200) → no insight
    // ──────────────────────────────────────────────────────────────────────

    public function test_healthy_page_creates_no_insight(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'is_priority' => false,
        ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => 'https://example.com/ok',
            'clicks' => 80,
            'impressions' => 2000,
            'ctr' => 4.0,
            'position' => 3.5,
            'captured_at' => now(),
        ]);

        Http::fake([
            'https://example.com/ok' => Http::response('OK', 200),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Unreachable page (ConnectionException) → insight with error='unreachable'
    // ──────────────────────────────────────────────────────────────────────

    public function test_unreachable_page_is_flagged(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'is_priority' => false,
        ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => 'https://example.com/down',
            'clicks' => 80,
            'impressions' => 1500,
            'ctr' => 5.33,
            'position' => 4.0,
            'captured_at' => now(),
        ]);

        Http::fake([
            'https://example.com/down' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::REVENUE_AT_RISK->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertNull($insight->payload['status_code']);
        $this->assertEquals('unreachable', $insight->payload['error']);
        $this->assertStringContainsString('unreachable', $insight->title);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Page below min_clicks → skipped (no HTTP request, no insight)
    // ──────────────────────────────────────────────────────────────────────

    public function test_ignores_pages_below_min_clicks(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'is_priority' => false,
        ]);

        // clicks=2 is below the default min_clicks=5 threshold.
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => 'https://example.com/low-traffic',
            'clicks' => 2,
            'impressions' => 500,
            'ctr' => 0.4,
            'position' => 10.0,
            'captured_at' => now(),
        ]);

        // Fake a 404 for this URL — if the service incorrectly probes it, the
        // insight count will be 1 and the test will fail.
        Http::fake([
            'https://example.com/low-traffic' => Http::response('Not Found', 404),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Broken page, low traffic, non-priority monitor → WARNING severity
    // ──────────────────────────────────────────────────────────────────────

    public function test_severity_warning_for_low_traffic_broken_page(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'is_priority' => false,
        ]);

        // clicks=10: above min_clicks (5), below high_traffic_clicks (50).
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => 'https://example.com/medium',
            'clicks' => 10,
            'impressions' => 300,
            'ctr' => 3.33,
            'position' => 8.0,
            'captured_at' => now(),
        ]);

        Http::fake([
            'https://example.com/medium' => Http::response('Internal Server Error', 500),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::REVENUE_AT_RISK->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Priority monitor overrides traffic-based severity → always CRITICAL
    // ──────────────────────────────────────────────────────────────────────

    public function test_severity_critical_when_monitor_is_priority(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'is_priority' => true, // overrides traffic threshold
        ]);

        // clicks=10: would normally be WARNING, but is_priority overrides.
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => 'https://example.com/priority-broken',
            'clicks' => 10,
            'impressions' => 400,
            'ctr' => 2.5,
            'position' => 7.0,
            'captured_at' => now(),
        ]);

        Http::fake([
            'https://example.com/priority-broken' => Http::response('Service Unavailable', 503),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::REVENUE_AT_RISK->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. Idempotence: second run replaces unacknowledged; acknowledged preserved
    // ──────────────────────────────────────────────────────────────────────

    public function test_idempotent_replaces_unacknowledged(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'is_priority' => false,
        ]);

        // Pre-existing acknowledged REVENUE_AT_RISK insight — must survive both runs.
        $acknowledged = Insight::factory()
            ->acknowledged()
            ->ofType(InsightType::REVENUE_AT_RISK)
            ->create([
                'team_id' => $team->id,
                'monitor_id' => $monitor->id,
            ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => 'https://example.com/idempotent',
            'clicks' => 80,
            'impressions' => 2000,
            'ctr' => 4.0,
            'position' => 3.0,
            'captured_at' => now(),
        ]);

        Http::fake([
            'https://example.com/idempotent' => Http::response('Not Found', 404),
        ]);

        $service = $this->makeService();

        // First run.
        $service->detectForMonitor($monitor);
        // Second run — must de-duplicate unacknowledged insights.
        $service->detectForMonitor($monitor);

        $unacknowledgedCount = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::REVENUE_AT_RISK->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(1, $unacknowledgedCount, 'Expected exactly 1 unacknowledged REVENUE_AT_RISK insight after 2 runs.');

        // Acknowledged insight must not have been deleted.
        $this->assertDatabaseHas('insights', ['id' => $acknowledged->id]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. No page_metrics → returns 0
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_zero_when_no_page_metrics(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        Http::fake(); // prevent any accidental outbound request

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. max_pages_per_monitor cap: only the highest-traffic pages are tested
    // ──────────────────────────────────────────────────────────────────────

    public function test_respects_max_pages_cap(): void
    {
        // Cap at 2 pages per monitor.
        config(['monitoring.broken_pages.max_pages_per_monitor' => 2]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'is_priority' => false,
        ]);

        // 4 pages, all broken with 404, ordered by clicks descending.
        // The service should only test the 2 with the most clicks.
        $pages = [
            ['page' => 'https://example.com/p1', 'clicks' => 100],
            ['page' => 'https://example.com/p2', 'clicks' => 80],
            ['page' => 'https://example.com/p3', 'clicks' => 30],
            ['page' => 'https://example.com/p4', 'clicks' => 10],
        ];

        foreach ($pages as $data) {
            PageMetric::factory()->create([
                'site' => 'example.com',
                'page' => $data['page'],
                'clicks' => $data['clicks'],
                'impressions' => $data['clicks'] * 20,
                'ctr' => 5.0,
                'position' => 5.0,
                'captured_at' => now(),
            ]);
        }

        Http::fake([
            'https://example.com/*' => Http::response('Not Found', 404),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        // Only 2 pages were tested due to the cap.
        $this->assertSame(2, $count);

        // The 2 created insights must correspond to the highest-traffic pages.
        $insightPages = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::REVENUE_AT_RISK->value)
            ->pluck('payload')
            ->map(fn (array $p) => $p['page'])
            ->all();

        $this->assertContains('https://example.com/p1', $insightPages);
        $this->assertContains('https://example.com/p2', $insightPages);
        $this->assertNotContains('https://example.com/p3', $insightPages);
        $this->assertNotContains('https://example.com/p4', $insightPages);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. impact_score equals the raw click count
    // ──────────────────────────────────────────────────────────────────────

    public function test_impact_score_equals_clicks(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'is_priority' => false,
        ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => 'https://example.com/scored',
            'clicks' => 123,
            'impressions' => 3000,
            'ctr' => round(123 / 3000 * 100, 4),
            'position' => 4.0,
            'captured_at' => now(),
        ]);

        Http::fake([
            'https://example.com/scored' => Http::response('Not Found', 404),
        ]);

        $this->makeService()->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::REVENUE_AT_RISK->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(123, (int) $insight->impact_score);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build a BrokenPageService with a mocked KpiCollector whose
     * siteNameFromUrl() mirrors the real implementation (ltrim www. from host).
     *
     * ContentDecayServiceTest uses the same pattern — see that test for precedent.
     */
    private function makeService(): BrokenPageService
    {
        $collector = $this->mock(KpiCollector::class, function ($mock): void {
            $mock->shouldReceive('siteNameFromUrl')
                ->andReturnUsing(function (string $url): string {
                    $host = parse_url($url, PHP_URL_HOST) ?? $url;

                    return ltrim($host, 'www.');
                });
        });

        return new BrokenPageService($collector);
    }
}
