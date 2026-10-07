<?php

namespace Tests\Feature\Services;

use App\Models\Monitor;
use App\Models\PageMetric;
use App\Services\KpiCollector;
use App\Services\PageMetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for PageMetricsCollector::collectForMonitor().
 *
 * KpiCollector is mocked in every test because collectGscQueries() requires
 * valid Google service-account credentials (absent in CI/test environment).
 * We inject controlled GSC rows and verify aggregation, filtering, and
 * persistence logic.
 */
class PageMetricsCollectorTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // Aggregation by page
    // ──────────────────────────────────────────────────────────────────────

    public function test_aggregates_rows_by_page(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        // Two rows for '/a' (different queries) + one row for '/b'.
        $rows = [
            $this->gscRow('query-1', '/a', 60, 1000, 6.0, 5.0),
            $this->gscRow('query-2', '/a', 40, 800, 5.0, 7.0),
            $this->gscRow('query-3', '/b', 30, 500, 6.0, 4.0),
        ];

        $service = $this->makeService($monitor, $rows);
        $count = $service->collectForMonitor($monitor);

        $this->assertSame(2, $count);

        // '/a' — clicks = 60 + 40 = 100, impressions = 1000 + 800 = 1800.
        $metricA = PageMetric::where('site', 'example.com')
            ->where('page', '/a')
            ->first();

        $this->assertNotNull($metricA);
        $this->assertEquals('100.00', $metricA->clicks);
        $this->assertEquals('1800.00', $metricA->impressions);

        // '/b' — single row, directly persisted.
        $metricB = PageMetric::where('site', 'example.com')
            ->where('page', '/b')
            ->first();

        $this->assertNotNull($metricB);
        $this->assertEquals('30.00', $metricB->clicks);
        $this->assertEquals('500.00', $metricB->impressions);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Noise filter: impressions below threshold
    // ──────────────────────────────────────────────────────────────────────

    public function test_filters_low_impression_pages(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        // '/low' has only 30 impressions — below default min_impressions of 50.
        // '/ok' has 200 impressions — above threshold.
        $rows = [
            $this->gscRow('q-low', '/low', 3, 30, 10.0, 15.0),
            $this->gscRow('q-ok', '/ok', 20, 200, 10.0, 8.0),
        ];

        $service = $this->makeService($monitor, $rows);
        $count = $service->collectForMonitor($monitor);

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('page_metrics', ['page' => '/low']);
        $this->assertDatabaseHas('page_metrics', ['page' => '/ok']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Empty GSC response
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_zero_when_no_rows(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $service = $this->makeService($monitor, []);
        $count = $service->collectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('page_metrics', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Site name is derived from monitor URL (www. stripped)
    // ──────────────────────────────────────────────────────────────────────

    public function test_persists_site_from_monitor_url(): void
    {
        // 'https://www.example.com' → site should be 'example.com' (www stripped).
        $monitor = Monitor::factory()->create(['url' => 'https://www.example.com']);

        $rows = [
            $this->gscRow('q', '/page', 10, 100, 10.0, 5.0),
        ];

        $service = $this->makeService($monitor, $rows);
        $service->collectForMonitor($monitor);

        $metric = PageMetric::first();
        $this->assertNotNull($metric);
        $this->assertEquals('example.com', $metric->site);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Defensive truncation: pathological URLs must not abort the whole run
    // (regression test for SQLSTATE[22001] "value too long" — B2)
    // ──────────────────────────────────────────────────────────────────────

    public function test_overlong_page_url_is_truncated_instead_of_throwing(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $overlongPage = '/'.str_repeat('a', 2000);

        $rows = [
            $this->gscRow('q', $overlongPage, 60, 1000, 6.0, 5.0),
        ];

        $service = $this->makeService($monitor, $rows);
        $count = $service->collectForMonitor($monitor);

        $this->assertSame(1, $count);

        $metric = PageMetric::first();
        $this->assertNotNull($metric);
        $this->assertSame(1024, mb_strlen($metric->page));
        $this->assertSame(mb_substr($overlongPage, 0, 1024), $metric->page);
    }

    public function test_overlong_page_url_in_keyword_metrics_is_truncated(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $overlongPage = '/'.str_repeat('b', 2000);

        $rows = [
            $this->gscRow('q', $overlongPage, 60, 1000, 6.0, 5.0),
        ];

        $service = $this->makeService($monitor, $rows);
        $service->collectForMonitor($monitor);

        $keywordMetric = \App\Models\KeywordMetric::first();
        $this->assertNotNull($keywordMetric);
        $this->assertSame(1024, mb_strlen($keywordMetric->page));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build a GSC row in the format returned by KpiCollector::collectGscQueries().
     */
    private function gscRow(
        string $query,
        string $page,
        float $clicks,
        float $impressions,
        float $ctr,
        float $position
    ): array {
        return compact('query', 'page', 'clicks', 'impressions', 'ctr', 'position');
    }

    /**
     * Build a PageMetricsCollector backed by a mocked KpiCollector that returns
     * the given rows from collectGscQueries() and applies real siteNameFromUrl logic.
     */
    private function makeService(Monitor $monitor, array $rows): PageMetricsCollector
    {
        $collector = $this->mock(KpiCollector::class, function ($mock) use ($monitor, $rows) {
            $mock->shouldReceive('collectGscQueries')
                ->with($monitor)
                ->andReturn($rows);

            $mock->shouldReceive('siteNameFromUrl')
                ->andReturnUsing(function (string $url): string {
                    $host = parse_url($url, PHP_URL_HOST) ?? $url;

                    return ltrim($host, 'www.');
                });
        });

        return new PageMetricsCollector($collector);
    }
}
