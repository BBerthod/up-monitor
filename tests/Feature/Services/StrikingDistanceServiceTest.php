<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Services\KpiCollector;
use App\Services\StrikingDistanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for StrikingDistanceService.
 *
 * KpiCollector is mocked in every test because collectGscQueries() requires
 * valid Google service-account credentials (absent in CI). We inject a
 * controlled set of GSC rows and test filtering / scoring / persistence logic.
 */
class StrikingDistanceServiceTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // findForMonitor — filtering
    // ──────────────────────────────────────────────────────────────────────

    public function test_find_for_monitor_keeps_only_positions_11_to_20(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $rows = [
            $this->gscRow('keyword-top', 5.0, 500),       // position 5  → excluded
            $this->gscRow('keyword-striking-a', 13.0, 200), // position 13 → included
            $this->gscRow('keyword-striking-b', 18.0, 150), // position 18 → included
            $this->gscRow('keyword-deep', 25.0, 300),       // position 25 → excluded
        ];

        $service = $this->makeServiceWithRows($monitor, $rows);
        $results = $service->findForMonitor($monitor);

        $positions = array_column($results, 'position');
        $this->assertCount(2, $results);
        $this->assertContains(13.0, $positions);
        $this->assertContains(18.0, $positions);
        $this->assertNotContains(5.0, $positions);
        $this->assertNotContains(25.0, $positions);
    }

    public function test_find_for_monitor_excludes_rows_below_impression_threshold(): void
    {
        // Default min_impressions is 100; row below threshold must be excluded.
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $rows = [
            $this->gscRow('low-impressions', 15.0, 50),  // 50 < 100 → excluded
            $this->gscRow('high-impressions', 14.0, 200), // 200 >= 100 → included
        ];

        $service = $this->makeServiceWithRows($monitor, $rows);
        $results = $service->findForMonitor($monitor);

        $this->assertCount(1, $results);
        $this->assertEquals('high-impressions', $results[0]['query']);
    }

    public function test_find_for_monitor_sorts_by_impact_score_descending(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        // Low CTR → high potential gain → higher impact score.
        $rows = [
            $this->gscRow('keyword-low-impact', 12.0, 110, 3.0),   // low impressions → low impact
            $this->gscRow('keyword-high-impact', 11.0, 5000, 0.5),  // many impressions, low CTR → high impact
            $this->gscRow('keyword-mid-impact', 16.0, 500, 1.0),    // medium
        ];

        $service = $this->makeServiceWithRows($monitor, $rows);
        $results = $service->findForMonitor($monitor);

        // First result must have the highest impact_score.
        $this->assertGreaterThanOrEqual($results[1]['impact_score'], $results[0]['impact_score']);
        $this->assertGreaterThanOrEqual($results[2]['impact_score'], $results[1]['impact_score']);
    }

    public function test_find_for_monitor_returns_empty_when_no_rows(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $service = $this->makeServiceWithRows($monitor, []);
        $results = $service->findForMonitor($monitor);

        $this->assertSame([], $results);
    }

    // ──────────────────────────────────────────────────────────────────────
    // detectForMonitor — persistence
    // ──────────────────────────────────────────────────────────────────────

    public function test_detect_for_monitor_creates_insights_and_returns_count(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $rows = [
            $this->gscRow('keyword-a', 13.0, 200),
            $this->gscRow('keyword-b', 17.0, 400),
        ];

        $service = $this->makeServiceWithRows($monitor, $rows);
        $count = $service->detectForMonitor($monitor);

        $this->assertEquals(2, $count);

        $insights = Insight::withoutGlobalScopes()
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->get();

        $this->assertCount(2, $insights);

        // Verify mandatory fields on the persisted insights.
        foreach ($insights as $insight) {
            $this->assertEquals($team->id, $insight->team_id);
            $this->assertEquals($monitor->id, $insight->monitor_id);
            $this->assertEquals(InsightSeverity::OPPORTUNITY, $insight->severity);
            $this->assertEquals(InsightType::STRIKING_DISTANCE, $insight->type);
        }
    }

    public function test_detect_for_monitor_does_not_duplicate_on_second_run(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $rows = [
            $this->gscRow('keyword-x', 12.0, 300),
        ];

        $service = $this->makeServiceWithRows($monitor, $rows);
        $service->detectForMonitor($monitor);
        $service->detectForMonitor($monitor);

        $this->assertEquals(
            1,
            Insight::withoutGlobalScopes()
                ->where('monitor_id', $monitor->id)
                ->where('type', InsightType::STRIKING_DISTANCE->value)
                ->count()
        );
    }

    public function test_detect_for_monitor_preserves_acknowledged_insights(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Create an existing acknowledged insight for this monitor.
        $acknowledged = Insight::factory()
            ->acknowledged()
            ->ofType(InsightType::STRIKING_DISTANCE)
            ->create([
                'team_id' => $team->id,
                'monitor_id' => $monitor->id,
            ]);

        $rows = [
            $this->gscRow('new-keyword', 14.0, 250),
        ];

        $service = $this->makeServiceWithRows($monitor, $rows);
        $service->detectForMonitor($monitor);

        // The acknowledged insight must still exist.
        $this->assertDatabaseHas('insights', ['id' => $acknowledged->id]);
    }

    public function test_detect_for_monitor_returns_zero_when_no_opportunities(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $service = $this->makeServiceWithRows($monitor, []);
        $count = $service->detectForMonitor($monitor);

        $this->assertEquals(0, $count);
    }

    // ──────────────────────────────────────────────────────────────────────
    // site_id FK
    // ──────────────────────────────────────────────────────────────────────

    public function test_detect_for_monitor_fills_site_id_when_monitor_has_linked_site(): void
    {
        $team = Team::factory()->create();

        // Create a Site and link it to the monitor via site_id.
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'example.com',
        ]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'site_id' => $site->id,
        ]);

        $rows = [
            $this->gscRow('keyword-fk', 15.0, 300),
        ];

        $service = $this->makeServiceWithRows($monitor, $rows);
        $service->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals($site->id, $insight->site_id);
    }

    public function test_detect_for_monitor_site_id_is_null_when_monitor_has_no_site(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'site_id' => null,
        ]);

        $rows = [
            $this->gscRow('keyword-no-site', 13.0, 200),
        ];

        $service = $this->makeServiceWithRows($monitor, $rows);
        $service->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertNull($insight->site_id);
    }

    // ──────────────────────────────────────────────────────────────────────
    // B3 regression — dedup keyed on site_id, not monitor_id
    // ──────────────────────────────────────────────────────────────────────

    /**
     * DispatchInsights picks a "representative" monitor per site (lowest id),
     * and that representative can change between runs. Before this fix,
     * purging on monitor_id left the previous representative's insight
     * orphaned forever — this test locks in that the purge now follows the
     * Site instead.
     */
    public function test_representative_change_purges_previous_representatives_insight(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->create(['team_id' => $team->id, 'primary_domain' => 'example.com']);

        // Same host, distinct path — a real HTTP monitor pair, not the
        // exact-URL duplicate the (team_id, normalized_url) unique index now
        // rejects. Only the shared site_id matters for this test.
        $monitorA = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'site_id' => $site->id,
        ]);
        $monitorB = Monitor::factory()->create([
            'url' => 'https://example.com/b',
            'team_id' => $team->id,
            'site_id' => $site->id,
        ]);

        $rows = [$this->gscRow('keyword-x', 12.0, 300)];

        // Run 1: monitorA is the representative.
        $this->makeServiceWithRows($monitorA, $rows)->detectForMonitor($monitorA);

        $first = Insight::withoutGlobalScopes()
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->whereNull('acknowledged_at')
            ->sole();
        $this->assertSame($monitorA->id, $first->monitor_id);

        // Run 2: the representative changed to monitorB.
        $this->makeServiceWithRows($monitorB, $rows)->detectForMonitor($monitorB);

        $open = Insight::withoutGlobalScopes()
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->whereNull('acknowledged_at')
            ->get();

        $this->assertCount(1, $open, 'The previous representative insight must be purged, not left orphaned.');
        $this->assertSame($monitorB->id, $open->first()->monitor_id);
        $this->assertSame($site->id, $open->first()->site_id);
    }

    public function test_purge_falls_back_to_hostname_when_monitor_has_no_site(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'site_id' => null,
        ]);

        $rows = [$this->gscRow('keyword-x', 12.0, 300)];
        $service = $this->makeServiceWithRows($monitor, $rows);

        $service->detectForMonitor($monitor);
        $service->detectForMonitor($monitor);

        $this->assertSame(1, Insight::withoutGlobalScopes()
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->whereNull('acknowledged_at')
            ->count());
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build a GSC row array in the format returned by KpiCollector::collectGscQueries().
     */
    private function gscRow(
        string $query,
        float $position,
        float $impressions,
        float $ctr = 1.0,
        string $page = 'https://example.com/page'
    ): array {
        return [
            'query' => $query,
            'page' => $page,
            'clicks' => round($impressions * $ctr / 100, 2),
            'impressions' => $impressions,
            'ctr' => $ctr,
            'position' => $position,
        ];
    }

    /**
     * Create a StrikingDistanceService with a mocked KpiCollector that returns
     * the given rows from collectGscQueries() and the canonical site name from
     * siteNameFromUrl().
     */
    private function makeServiceWithRows(Monitor $monitor, array $rows): StrikingDistanceService
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

        return new StrikingDistanceService($collector);
    }
}
