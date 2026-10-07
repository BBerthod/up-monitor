<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\PageMetric;
use App\Models\Site;
use App\Models\Team;
use App\Services\ContentDecayService;
use App\Services\KpiCollector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for ContentDecayService::detectForMonitor().
 *
 * KpiCollector is mocked so that siteNameFromUrl() returns a canonical hostname
 * without needing real GSC credentials.  PageMetric rows are seeded directly via
 * the factory.
 *
 * Monitor URL convention: 'https://example.com' → site = 'example.com'.
 * All PageMetric rows must use site = 'example.com' to match.
 */
class ContentDecayServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Decay detection — happy path
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_decayed_page(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Baseline: 60 days ago — strong traffic.
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/a',
            'clicks' => 200,
            'impressions' => 5000,
            'ctr' => round(200 / 5000 * 100, 4),
            'position' => 5.0,
            'captured_at' => now()->subDays(60),
        ]);

        // Current: today — significant decline (60 % click drop, impressions also down).
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/a',
            'clicks' => 80,
            'impressions' => 3000,
            'ctr' => round(80 / 3000 * 100, 4),
            'position' => 8.0,
            'captured_at' => now(),
        ]);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::CONTENT_DECAY->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
        $this->assertEquals($team->id, $insight->team_id);
        // impact_score = absolute clicks lost = 200 - 80 = 120
        $this->assertEquals('120.00', $insight->impact_score);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Guard: insufficient history between baseline and current
    // ──────────────────────────────────────────────────────────────────────

    public function test_ignores_page_with_insufficient_history(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Only 5 days apart — below min_history_days default of 14.
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/b',
            'clicks' => 200,
            'impressions' => 5000,
            'ctr' => 4.0,
            'position' => 5.0,
            'captured_at' => now()->subDays(5),
        ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/b',
            'clicks' => 1,
            'impressions' => 100,
            'ctr' => 1.0,
            'position' => 20.0,
            'captured_at' => now(),
        ]);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Guard: click drop but impressions stable or rising → CTR shift, not decay
    // ──────────────────────────────────────────────────────────────────────

    public function test_ignores_ctr_only_drop(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Baseline.
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/c',
            'clicks' => 200,
            'impressions' => 5000,
            'ctr' => 4.0,
            'position' => 5.0,
            'captured_at' => now()->subDays(60),
        ]);

        // Clicks dropped 50 % BUT impressions are the same — pure CTR drop.
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/c',
            'clicks' => 100,
            'impressions' => 5000,  // unchanged
            'ctr' => 2.0,
            'position' => 7.0,
            'captured_at' => now(),
        ]);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Guard: click decline below threshold (15 % < 30 %)
    // ──────────────────────────────────────────────────────────────────────

    public function test_ignores_decline_below_threshold(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/d',
            'clicks' => 200,
            'impressions' => 5000,
            'ctr' => 4.0,
            'position' => 5.0,
            'captured_at' => now()->subDays(60),
        ]);

        // 15 % decline — below 30 % threshold.
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/d',
            'clicks' => 170,
            'impressions' => 4500,
            'ctr' => 3.78,
            'position' => 6.0,
            'captured_at' => now(),
        ]);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Guard: baseline clicks = 0 → page was growing from nothing, not decaying
    // ──────────────────────────────────────────────────────────────────────

    public function test_ignores_zero_baseline(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Baseline with 0 clicks — must be skipped regardless of current value.
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/e',
            'clicks' => 0,
            'impressions' => 500,
            'ctr' => 0.0,
            'position' => 15.0,
            'captured_at' => now()->subDays(60),
        ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/e',
            'clicks' => 0,
            'impressions' => 100,
            'ctr' => 0.0,
            'position' => 20.0,
            'captured_at' => now(),
        ]);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // No page_metrics data at all
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_zero_when_no_page_metrics(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://example.com']);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(0, $count);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Idempotence: second run must not create duplicate insights
    // ──────────────────────────────────────────────────────────────────────

    public function test_idempotent_replaces_unacknowledged(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Pre-existing acknowledged insight — must survive both runs.
        $acknowledged = Insight::factory()
            ->acknowledged()
            ->ofType(InsightType::CONTENT_DECAY)
            ->create([
                'team_id' => $team->id,
                'monitor_id' => $monitor->id,
            ]);

        // Seed decayed page data.
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/f',
            'clicks' => 200,
            'impressions' => 5000,
            'ctr' => 4.0,
            'position' => 5.0,
            'captured_at' => now()->subDays(60),
        ]);
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/f',
            'clicks' => 80,
            'impressions' => 3000,
            'ctr' => 2.67,
            'position' => 8.0,
            'captured_at' => now(),
        ]);

        $service = $this->makeService($monitor);

        // First run.
        $service->detectForMonitor($monitor);
        // Second run — must de-duplicate.
        $service->detectForMonitor($monitor);

        $unacknowledgedCount = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::CONTENT_DECAY->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(1, $unacknowledgedCount, 'Expected exactly 1 unacknowledged CONTENT_DECAY insight after 2 runs.');

        // Acknowledged insight must not have been touched.
        $this->assertDatabaseHas('insights', ['id' => $acknowledged->id]);
    }

    public function test_detected_at_is_preserved_per_page_while_payload_and_impact_are_recomputed(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        Carbon::setTestNow('2026-08-01 08:00:00');

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/aging',
            'clicks' => 100,
            'impressions' => 1000,
            'ctr' => 10.0,
            'position' => 5.0,
            'captured_at' => now()->subDays(60),
        ]);
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/aging',
            'clicks' => 60,
            'impressions' => 600,
            'ctr' => 10.0,
            'position' => 8.0,
            'captured_at' => now(),
        ]);

        $service = $this->makeService($monitor);
        $service->detectForMonitor($monitor);

        $firstInsight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::CONTENT_DECAY->value)
            ->where('payload->page', '/aging')
            ->whereNull('acknowledged_at')
            ->firstOrFail();

        $this->assertSame(40, $firstInsight->payload['decline_pct']);
        $this->assertEquals('40.00', $firstInsight->impact_score);
        $firstDetectedAt = $firstInsight->detected_at;

        Carbon::setTestNow('2026-08-22 08:00:00');

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/aging',
            'clicks' => 20,
            'impressions' => 200,
            'ctr' => 10.0,
            'position' => 12.0,
            'captured_at' => now(),
        ]);

        $service->detectForMonitor($monitor);

        $refreshed = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::CONTENT_DECAY->value)
            ->where('payload->page', '/aging')
            ->whereNull('acknowledged_at')
            ->get();

        $this->assertCount(1, $refreshed);

        $refreshedInsight = $refreshed->first();

        $this->assertTrue($refreshedInsight->detected_at->equalTo($firstDetectedAt));
        $this->assertSame(60, $refreshedInsight->payload['decline_pct']);
        $this->assertEquals('60.00', $refreshedInsight->impact_score);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────
    // impact_score = absolute clicks lost
    // ──────────────────────────────────────────────────────────────────────

    public function test_impact_score_is_absolute_clicks_lost(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $clicksBefore = 350;
        $clicksNow = 100;

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/g',
            'clicks' => $clicksBefore,
            'impressions' => 8000,
            'ctr' => round($clicksBefore / 8000 * 100, 4),
            'position' => 4.0,
            'captured_at' => now()->subDays(60),
        ]);
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/g',
            'clicks' => $clicksNow,
            'impressions' => 5000,
            'ctr' => round($clicksNow / 5000 * 100, 4),
            'position' => 9.0,
            'captured_at' => now(),
        ]);

        $service = $this->makeService($monitor);
        $service->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::CONTENT_DECAY->value)
            ->first();

        $this->assertNotNull($insight);
        $expectedImpact = $clicksBefore - $clicksNow; // 250
        $this->assertEquals(number_format($expectedImpact, 2), $insight->impact_score);
    }

    // ──────────────────────────────────────────────────────────────────────
    // B3 regression — dedup keyed on site_id, not monitor_id
    // ──────────────────────────────────────────────────────────────────────

    /**
     * DispatchInsights picks a "representative" monitor per site (lowest id),
     * and that representative can change between runs (e.g. the original
     * monitor was disabled or deleted). Before this fix, purging on
     * monitor_id left the previous representative's insight orphaned forever
     * — this test locks in that the purge now follows the Site instead.
     */
    public function test_representative_change_purges_previous_representatives_insight(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        // Same host, distinct path — a real HTTP monitor pair, not the
        // exact-URL duplicate the (team_id, normalized_url) unique index now
        // rejects. Only the shared hostname matters for this test.
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

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/a',
            'clicks' => 200,
            'impressions' => 5000,
            'ctr' => 4.0,
            'position' => 5.0,
            'captured_at' => now()->subDays(60),
        ]);
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/a',
            'clicks' => 80,
            'impressions' => 3000,
            'ctr' => 2.67,
            'position' => 8.0,
            'captured_at' => now(),
        ]);

        // Run 1: monitorA is the representative.
        $this->makeService($monitorA)->detectForMonitor($monitorA);

        $firstInsight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::CONTENT_DECAY->value)
            ->whereNull('acknowledged_at')
            ->sole();
        $this->assertSame($monitorA->id, $firstInsight->monitor_id);

        // Run 2: the representative changed to monitorB (e.g. monitorA was
        // deactivated). Same underlying Site, same decayed page.
        $this->makeService($monitorB)->detectForMonitor($monitorB);

        $openInsights = Insight::withoutGlobalScopes()
            ->where('type', InsightType::CONTENT_DECAY->value)
            ->whereNull('acknowledged_at')
            ->get();

        $this->assertCount(1, $openInsights, 'The previous representative insight must be purged, not left orphaned.');
        $this->assertSame($monitorB->id, $openInsights->first()->monitor_id);
        $this->assertSame($site->id, $openInsights->first()->site_id);
    }

    // ──────────────────────────────────────────────────────────────────────
    // B3 regression — monitor without a linked Site falls back to the
    // hostname string, so dedup still works for orphan monitors.
    // ──────────────────────────────────────────────────────────────────────

    public function test_purge_falls_back_to_hostname_when_monitor_has_no_site(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'site_id' => null,
        ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/a',
            'clicks' => 200,
            'impressions' => 5000,
            'ctr' => 4.0,
            'position' => 5.0,
            'captured_at' => now()->subDays(60),
        ]);
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => '/a',
            'clicks' => 80,
            'impressions' => 3000,
            'ctr' => 2.67,
            'position' => 8.0,
            'captured_at' => now(),
        ]);

        $service = $this->makeService($monitor);
        $service->detectForMonitor($monitor);
        $service->detectForMonitor($monitor);

        $this->assertSame(1, Insight::withoutGlobalScopes()
            ->where('type', InsightType::CONTENT_DECAY->value)
            ->whereNull('acknowledged_at')
            ->count());
    }

    // ──────────────────────────────────────────────────────────────────────
    // Rolling-average fix (2026-09-07 audit) — regression tests locking in
    // the move from two single points to two averaged comparison windows.
    // See ContentDecayService docblock ("WHY NOT TWO SINGLE POINTS").
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Mirrors alert #43 (campsite): a single anomalous "0 clicks" snapshot
     * on the very last collection day (a GSC data-lag glitch) used to be
     * picked as "the current point" and compared against the oldest snapshot,
     * producing a "-100 %" alert while the page's real week-to-week average
     * was flat. Averaging over the current window absorbs the one-off dip.
     */
    public function test_single_day_dip_does_not_trigger_when_average_is_stable(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Baseline window (older than the 28-day comparison period): stable ~10 clicks/day.
        $this->createDailySnapshots('/h', [10, 11, 9, 10, 10], startDaysAgo: 70);

        // Current window: stable ~10 clicks/day except the very last (most
        // recent) snapshot, which reads 0 — the exact point the OLD
        // two-point algorithm would have picked as "current".
        $this->createDailySnapshots('/h', [11, 10, 9, 10, 0], startDaysAgo: 4);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        // Average current (8) vs average baseline (10) = 20 % decline, below
        // the 30 % threshold. The old algorithm (oldest=10 vs latest=0) would
        // have read this as a -100 % alert.
        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    /**
     * Mirrors alert #39 (jokes-site): comparing the two extreme points
     * exaggerated the decline (~-60 %) versus GSC's own 4-week/4-week
     * comparison (~-24 %). Averaging both windows brings the computed
     * decline back under the alerting threshold.
     */
    public function test_gradual_decline_measured_by_average_stays_under_threshold(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Baseline window: declining daily but averaging 37.5 clicks/day.
        $this->createDailySnapshots('/i', [45, 40, 35, 30], startDaysAgo: 60);

        // Current window: also declining daily, averaging 29 clicks/day.
        // The oldest baseline point (45) vs the newest current point (18)
        // alone would read as a ~60 % drop; the averaged comparison is ~23 %.
        $this->createDailySnapshots('/i', [35, 33, 30, 18], startDaysAgo: 3);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    /**
     * Mirrors alert #44 (garden-site-a): 1 click → 0 clicks is a "-100 %"
     * decline in relative terms but is not a meaningful content-decay
     * signal. The min_click_volume floor must suppress it even though the
     * averaged-window comparison correctly measures a genuine 100 % drop.
     */
    public function test_alert_suppressed_below_min_click_volume_floor(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Baseline averages 3 clicks/day — below the default floor of 5.
        $this->createDailySnapshots('/j', [4, 3, 2], startDaysAgo: 60);

        // Current window has gone fully silent.
        $this->createDailySnapshots('/j', [0, 0, 0], startDaysAgo: 2);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    /**
     * Boundary check: a baseline average AT the min_weekly_clicks floor (10/week,
     * i.e. 40 per 28-day snapshot)
     * with a genuine sustained decline must still alert — the floor guards
     * against noise, it must not silently swallow small-site real decay.
     */
    public function test_alert_still_fires_at_min_click_volume_boundary(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Baseline averages exactly 40 clicks per snapshot (10 per week).
        $this->createDailySnapshots('/k', [42, 40, 38], startDaysAgo: 60);

        // Current window collapses to near zero.
        $this->createDailySnapshots('/k', [1, 1, 0], startDaysAgo: 2);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(1, $count);
        $this->assertDatabaseCount('insights', 1);
    }

    /**
     * Mirrors the blague-coquine false negative: a real, sustained drop
     * (55 clicks per snapshot down to ~0) spread across many days must still be
     * detected under the averaged-window comparison — the fix must not
     * trade false positives for missed real decay.
     */
    public function test_sustained_multi_day_decline_still_triggers(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        // Baseline window: healthy ~55 clicks per snapshot, matching the real
        // blague-coquine baseline before the drop.
        $this->createDailySnapshots('/l', [60, 55, 50, 55], startDaysAgo: 70, impressionsPerClick: 25.0);

        // Current window: collapsed to near-zero across every day, not just
        // the extremes — a genuine sustained decline.
        $this->createDailySnapshots('/l', [1, 0, 0, 0], startDaysAgo: 15, impressionsPerClick: 25.0);

        $service = $this->makeService($monitor);
        $count = $service->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::CONTENT_DECAY->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Weekly volume floor
    // ──────────────────────────────────────────────────────────────────────

    public function test_baseline_below_ten_clicks_per_week_is_not_decay(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['url' => 'https://example.com', 'team_id' => $team->id]);

        // 30 clicks per 28-day snapshot = 7.5 per week, below the floor of 10.
        $this->createDailySnapshots('/m', [30, 30, 30], startDaysAgo: 60);
        $this->createDailySnapshots('/m', [2, 1, 1], startDaysAgo: 2);

        $this->assertSame(0, $this->makeService($monitor)->detectForMonitor($monitor));
    }

    public function test_baseline_above_ten_clicks_per_week_is_decay(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['url' => 'https://example.com', 'team_id' => $team->id]);

        // 48 clicks per snapshot = 12 per week.
        $this->createDailySnapshots('/m', [48, 48, 48], startDaysAgo: 60);
        $this->createDailySnapshots('/m', [2, 1, 1], startDaysAgo: 2);

        $this->assertSame(1, $this->makeService($monitor)->detectForMonitor($monitor));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Site-relative decline
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Seed the site-wide GSC clicks used as the seasonality reference.
     */
    private function seedSiteClicks(float $baseline, float $current): void
    {
        foreach ([[$baseline, 60], [$baseline, 55], [$current, 3], [$current, 2]] as [$value, $daysAgo]) {
            KpiSnapshot::create([
                'site' => 'example.com',
                'source' => KpiSource::GSC->value,
                'metric' => 'clicks_28d',
                'value' => $value,
                'period_days' => 28,
                'captured_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    public function test_page_decline_in_line_with_the_whole_site_is_not_decay(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['url' => 'https://example.com', 'team_id' => $team->id]);

        // Page -74 % while the site lost 80 %: seasonality.
        $this->createDailySnapshots('/n', [100, 100, 100], startDaysAgo: 60);
        $this->createDailySnapshots('/n', [26, 26, 26], startDaysAgo: 2);
        $this->seedSiteClicks(1000, 200);

        $this->assertSame(0, $this->makeService($monitor)->detectForMonitor($monitor));
    }

    public function test_page_decline_well_beyond_the_site_decline_is_decay(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['url' => 'https://example.com', 'team_id' => $team->id]);

        // Page -74 % while the site lost only 10 %: page-specific.
        $this->createDailySnapshots('/n', [100, 100, 100], startDaysAgo: 60);
        $this->createDailySnapshots('/n', [26, 26, 26], startDaysAgo: 2);
        $this->seedSiteClicks(1000, 900);

        $this->assertSame(1, $this->makeService($monitor)->detectForMonitor($monitor));
    }

    public function test_without_site_history_the_page_is_judged_alone(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['url' => 'https://example.com', 'team_id' => $team->id]);

        $this->createDailySnapshots('/n', [100, 100, 100], startDaysAgo: 60);
        $this->createDailySnapshots('/n', [26, 26, 26], startDaysAgo: 2);

        $this->assertSame(1, $this->makeService($monitor)->detectForMonitor($monitor));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Memory regression — bulk snapshot volume must not hydrate every row
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Reproduces the production incident of 2026-09-15: a worker was killed
     * with "Allowed memory size of 268435456 bytes exhausted" while running
     * DetectContentDecay. A site can accumulate ~1000 pages × 84 days of
     * daily snapshots; hydrating every row as a PageMetric model (with
     * decimal/datetime casts) blew past the 256M memory_limit. The fix moves
     * the per-page averaging into a single SQL GROUP BY query, so memory use
     * must stay bounded regardless of how many snapshot rows exist.
     */
    public function test_large_snapshot_volume_does_not_balloon_memory_usage(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $pageCount = 500;
        $daysPerPage = 84;
        $now = now();
        $chunkSize = 5000;

        $rows = [];
        for ($p = 0; $p < $pageCount; $p++) {
            $page = "/page-{$p}";
            for ($d = 0; $d < $daysPerPage; $d++) {
                $rows[] = [
                    'site' => 'example.com',
                    'page' => $page,
                    'clicks' => 10,
                    'impressions' => 200,
                    'ctr' => 5.0,
                    'position' => 10.0,
                    'captured_at' => $now->copy()->subDays($daysPerPage - $d),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) >= $chunkSize) {
                    DB::table('page_metrics')->insert($rows);
                    $rows = [];
                }
            }
        }
        if (! empty($rows)) {
            DB::table('page_metrics')->insert($rows);
        }

        $service = $this->makeService($monitor);

        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $service->detectForMonitor($monitor);

        $peakIncrease = memory_get_peak_usage(true) - $baseline;

        $this->assertLessThan(
            32 * 1024 * 1024,
            $peakIncrease,
            sprintf(
                'detectForMonitor() peak memory increase was %.2f MB (limit 32 MB) for %d pages × %d days — likely hydrating raw snapshot rows again instead of aggregating in SQL.',
                $peakIncrease / 1024 / 1024,
                $pageCount,
                $daysPerPage,
            )
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create one PageMetric per day for a page, oldest first.
     *
     * @param  list<float>  $clicks  Daily click values, oldest to newest.
     * @param  int  $startDaysAgo  How many days ago the FIRST (oldest) entry was captured;
     *                             subsequent entries move one day at a time towards "now".
     */
    private function createDailySnapshots(
        string $page,
        array $clicks,
        int $startDaysAgo,
        float $impressionsPerClick = 20.0,
        float $position = 10.0,
    ): void {
        foreach ($clicks as $i => $click) {
            $impressions = max($click * $impressionsPerClick, 50.0);

            PageMetric::factory()->create([
                'site' => 'example.com',
                'page' => $page,
                'clicks' => $click,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? round($click / $impressions * 100, 4) : 0.0,
                'position' => $position,
                'captured_at' => now()->subDays($startDaysAgo - $i),
            ]);
        }
    }

    /**
     * Build a ContentDecayService with a mocked KpiCollector whose
     * siteNameFromUrl() mirrors the real implementation (ltrim www. from host).
     */
    private function makeService(Monitor $monitor): ContentDecayService
    {
        $collector = $this->mock(KpiCollector::class, function ($mock) {
            $mock->shouldReceive('siteNameFromUrl')
                ->andReturnUsing(function (string $url): string {
                    $host = parse_url($url, PHP_URL_HOST) ?? $url;

                    return ltrim($host, 'www.');
                });
        });

        return new ContentDecayService($collector);
    }
}
