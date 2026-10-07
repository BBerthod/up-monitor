<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\KeywordMetric;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Services\KeywordTrendService;
use App\Services\KpiCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for KeywordTrendService.
 *
 * The gap this closes: Up could say a site's average position had moved, never
 * WHICH keyword moved — and an aggregate hides the interesting case entirely,
 * since a site can hold a flat average while its best commercial keyword falls
 * off page one.
 */
class KeywordTrendServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): KeywordTrendService
    {
        return new KeywordTrendService(app(KpiCollector::class));
    }

    private function makeMonitor(): Monitor
    {
        return Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => Team::factory()->create()->id,
        ]);
    }

    /**
     * Record one keyword observation.
     */
    private function record(
        string $query,
        float $position,
        \DateTimeInterface $at,
        float $impressions = 500,
        float $clicks = 20,
        string $page = 'https://example.com/a',
    ): void {
        KeywordMetric::create([
            'site' => 'example.com',
            'query' => $query,
            'page' => $page,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 4) : 0,
            'position' => $position,
            'captured_at' => $at,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Falling off page one with corroborating click loss is CRITICAL
    // ──────────────────────────────────────────────────────────────────────

    public function test_keyword_falling_off_page_one_with_click_loss_is_critical(): void
    {
        $monitor = $this->makeMonitor();

        foreach ([35, 34, 33] as $daysAgo) {
            $this->record('best drill', position: 4.0, at: now()->subDays($daysAgo), clicks: 20);
        }
        foreach ([3, 2, 1] as $daysAgo) {
            $this->record('best drill', position: 14.0, at: now()->subDays($daysAgo), clicks: 5);
        }

        $this->assertSame(1, $this->makeService()->detectForMonitor($monitor));

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::KEYWORD_DROP->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertSame('best drill', $insight->payload['drops'][0]['query']);
        $this->assertEquals(4.0, $insight->payload['drops'][0]['from']);
        $this->assertEquals(14.0, $insight->payload['drops'][0]['to']);
        $this->assertEquals(75.0, $insight->payload['drops'][0]['click_decline_pct']);
    }

    public function test_page_one_exit_with_stable_or_rising_clicks_is_not_critical(): void
    {
        $monitor = $this->makeMonitor();

        $this->record('healthy traffic', position: 4.0, at: now()->subDays(30), clicks: 20);
        $this->record('healthy traffic', position: 14.0, at: now(), clicks: 22);

        $this->assertSame(1, $this->makeService()->detectForMonitor($monitor));

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::KEYWORD_DROP->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. A slide deep in the results is not worth an alert
    // ──────────────────────────────────────────────────────────────────────

    public function test_drop_starting_beyond_the_position_ceiling_is_ignored(): void
    {
        $monitor = $this->makeMonitor();

        // 232 -> 237 and 34 -> 42: nobody was looking there, whatever the volume.
        $this->record('obscure query', position: 232.0, at: now()->subDays(30), impressions: 2000);
        $this->record('obscure query', position: 237.0, at: now(), impressions: 2000);
        $this->record('long tail', position: 34.0, at: now()->subDays(30), impressions: 2000);
        $this->record('long tail', position: 42.0, at: now(), impressions: 2000);

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    public function test_drop_from_the_first_two_pages_is_a_warning_when_loss_is_significant(): void
    {
        $monitor = $this->makeMonitor();

        $this->record('mid query', position: 18.0, at: now()->subDays(30), impressions: 600, clicks: 2);
        $this->record('mid query', position: 26.0, at: now(), impressions: 600, clicks: 2);

        $this->assertSame(1, $this->makeService()->detectForMonitor($monitor));

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::KEYWORD_DROP->value)
            ->first();

        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    public function test_drop_with_few_clicks_lost_and_low_impressions_is_ignored(): void
    {
        $monitor = $this->makeMonitor();

        // Position 8 -> 15 but only 2 clicks lost and 200 impressions.
        $this->record('quiet query', position: 8.0, at: now()->subDays(30), impressions: 200, clicks: 3);
        $this->record('quiet query', position: 15.0, at: now(), impressions: 200, clicks: 1);

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    public function test_drop_with_enough_clicks_lost_is_reported_despite_low_impressions(): void
    {
        $monitor = $this->makeMonitor();

        $this->record('clicky query', position: 8.0, at: now()->subDays(30), impressions: 200, clicks: 10);
        $this->record('clicky query', position: 15.0, at: now(), impressions: 200, clicks: 2);

        $this->assertSame(1, $this->makeService()->detectForMonitor($monitor));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Small moves are GSC averaging noise
    // ──────────────────────────────────────────────────────────────────────

    public function test_small_position_moves_are_ignored(): void
    {
        $monitor = $this->makeMonitor();

        $this->record('stable query', position: 5.0, at: now()->subDays(30));
        $this->record('stable query', position: 7.0, at: now());

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. The volume gate reads the BASELINE, not the current value
    // ──────────────────────────────────────────────────────────────────────

    public function test_low_baseline_volume_keywords_are_ignored(): void
    {
        $monitor = $this->makeMonitor();

        // Only 5 impressions historically — the drop carries no signal.
        $this->record('rare query', position: 3.0, at: now()->subDays(30), impressions: 5);
        $this->record('rare query', position: 30.0, at: now(), impressions: 5);

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    public function test_belfort_like_low_volume_position_jump_is_ignored(): void
    {
        $monitor = $this->makeMonitor();

        // Roughly two impressions/day over GSC's trailing 28-day capture: the
        // 8.9 → 14.1 movement is too thin to support a ranking alert.
        foreach ([35 => 8.8, 34 => 9.0, 33 => 8.9] as $daysAgo => $position) {
            $this->record('agence seo belfort', $position, now()->subDays($daysAgo), impressions: 56, clicks: 1);
        }
        foreach ([3 => 13.9, 2 => 14.1, 1 => 14.3] as $daysAgo => $position) {
            $this->record('agence seo belfort', $position, now()->subDays($daysAgo), impressions: 56, clicks: 1);
        }

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_single_position_spike_does_not_trigger_when_window_average_is_stable(): void
    {
        $monitor = $this->makeMonitor();

        foreach ([35 => 8.0, 34 => 8.0, 33 => 8.0] as $daysAgo => $position) {
            $this->record('windowed query', $position, now()->subDays($daysAgo), impressions: 500, clicks: 20);
        }
        foreach ([3 => 8.0, 2 => 8.0, 1 => 23.0] as $daysAgo => $position) {
            $this->record('windowed query', $position, now()->subDays($daysAgo), impressions: 500, clicks: 20);
        }

        // Latest-point comparison saw 8 → 23; the current weighted mean is 13,
        // exactly five positions worse, so raise the threshold to isolate the
        // rolling-average behaviour from the inclusive delta boundary.
        config()->set('monitoring.keyword_tracking.drop_min_positions', 6);

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    public function test_keyword_that_collapsed_to_nothing_is_still_reported(): void
    {
        $monitor = $this->makeMonitor();

        // Used to matter, now barely registers. Gating on CURRENT impressions
        // would silence exactly this case — the one worth knowing about.
        $this->record('was important', position: 3.0, at: now()->subDays(30), impressions: 2000);
        $this->record('was important', position: 40.0, at: now(), impressions: 4);

        $this->assertSame(1, $this->makeService()->detectForMonitor($monitor));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. A keyword with no baseline is new, not fallen
    // ──────────────────────────────────────────────────────────────────────

    public function test_new_keyword_without_baseline_is_not_a_drop(): void
    {
        $monitor = $this->makeMonitor();

        // Establish history for an unrelated query so a baseline capture exists.
        $this->record('other', position: 5.0, at: now()->subDays(30));
        $this->record('other', position: 5.0, at: now());
        $this->record('brand new', position: 45.0, at: now());

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. No history yet — normal during bootstrap, must stay silent
    // ──────────────────────────────────────────────────────────────────────

    public function test_no_baseline_history_produces_nothing(): void
    {
        $monitor = $this->makeMonitor();

        $this->record('only today', position: 20.0, at: now());

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. Cannibalisation: one query, several pages
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_cannibalisation(): void
    {
        $monitor = $this->makeMonitor();
        $now = now();

        $this->record('drill review', position: 8.0, at: $now, impressions: 600, page: 'https://example.com/a');
        $this->record('drill review', position: 12.0, at: $now, impressions: 400, page: 'https://example.com/b');

        $this->makeService()->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::KEYWORD_CANNIBALISATION->value)
            ->first();

        $this->assertNotNull($insight);
        // Structural content issue — never pages anyone.
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
        $this->assertSame(2, $insight->payload['findings'][0]['pages']);
        $this->assertSame('drill review', $insight->payload['findings'][0]['query']);
    }

    public function test_single_page_query_is_not_cannibalisation(): void
    {
        $monitor = $this->makeMonitor();

        $this->record('clean query', position: 8.0, at: now(), impressions: 900);

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    public function test_low_volume_cannibalisation_is_ignored(): void
    {
        $monitor = $this->makeMonitor();
        $now = now();

        // Two pages splitting a query nobody searches for is not a problem.
        $this->record('tiny query', position: 8.0, at: $now, impressions: 20, page: 'https://example.com/a');
        $this->record('tiny query', position: 9.0, at: $now, impressions: 20, page: 'https://example.com/b');

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    public function test_cannibalisation_ignores_queries_already_in_the_top_three(): void
    {
        $monitor = $this->makeMonitor();
        $now = now();

        // A brand query: the site already owns the top of the ranking.
        $this->record('example brand', position: 1.0, at: $now, impressions: 600, page: 'https://example.com/a');
        $this->record('example brand', position: 9.0, at: $now, impressions: 400, page: 'https://example.com/b');

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_cannibalisation_ignores_site_operator_queries(): void
    {
        $monitor = $this->makeMonitor();
        $now = now();

        $this->record('site:example.com widgets', position: 8.0, at: $now, impressions: 600, page: 'https://example.com/a');
        $this->record('site:example.com widgets', position: 12.0, at: $now, impressions: 400, page: 'https://example.com/b');

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
        $this->assertDatabaseCount('insights', 0);
    }

    public function test_cannibalisation_keeps_real_findings_next_to_ignored_ones(): void
    {
        $monitor = $this->makeMonitor();
        $now = now();

        $this->record('example brand', position: 1.0, at: $now, impressions: 900, page: 'https://example.com/a');
        $this->record('example brand', position: 9.0, at: $now, impressions: 900, page: 'https://example.com/b');
        $this->record('drill review', position: 8.0, at: $now, impressions: 300, page: 'https://example.com/a');
        $this->record('drill review', position: 12.0, at: $now, impressions: 300, page: 'https://example.com/b');

        $this->makeService()->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::KEYWORD_CANNIBALISATION->value)
            ->sole();

        $this->assertCount(1, $insight->payload['findings']);
        $this->assertSame('drill review', $insight->payload['findings'][0]['query']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. Idempotence
    // ──────────────────────────────────────────────────────────────────────

    public function test_reruns_replace_previous_unacknowledged_insights(): void
    {
        $monitor = $this->makeMonitor();

        $this->record('best drill', position: 4.0, at: now()->subDays(30));
        $this->record('best drill', position: 14.0, at: now());

        $this->makeService()->detectForMonitor($monitor);
        $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, Insight::withoutGlobalScopes()
            ->where('type', InsightType::KEYWORD_DROP->value)
            ->count());
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. A site's position for a query is weighted by page impressions
    // ──────────────────────────────────────────────────────────────────────

    public function test_query_position_is_weighted_by_impressions(): void
    {
        $monitor = $this->makeMonitor();
        $old = now()->subDays(30);

        // Baseline weighted position = (3×900 + 55×100) / 1000 = 8.2.
        // A best-page MIN would instead read 3 and falsely see an 11-position fall.
        $this->record('multi page', position: 3.0, at: $old, impressions: 900, page: 'https://example.com/a');
        $this->record('multi page', position: 55.0, at: $old, impressions: 100, page: 'https://example.com/b');
        $this->record('multi page', position: 14.0, at: now(), impressions: 1000, page: 'https://example.com/a');

        config()->set('monitoring.keyword_tracking.drop_min_positions', 6);

        // Weighted movement = 5.8, below the configured six-position floor.
        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. B3 regression — dedup keyed on site_id, not monitor_id
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

        $this->record('best drill', position: 4.0, at: now()->subDays(30));
        $this->record('best drill', position: 14.0, at: now());

        // Run 1: monitorA is the representative.
        $this->makeService()->detectForMonitor($monitorA);

        $first = Insight::withoutGlobalScopes()
            ->where('type', InsightType::KEYWORD_DROP->value)
            ->whereNull('acknowledged_at')
            ->sole();
        $this->assertSame($monitorA->id, $first->monitor_id);

        // Run 2: the representative changed to monitorB.
        $this->makeService()->detectForMonitor($monitorB);

        $open = Insight::withoutGlobalScopes()
            ->where('type', InsightType::KEYWORD_DROP->value)
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

        $this->record('best drill', position: 4.0, at: now()->subDays(30));
        $this->record('best drill', position: 14.0, at: now());

        $service = $this->makeService();
        $service->detectForMonitor($monitor);
        $service->detectForMonitor($monitor);

        $this->assertSame(1, Insight::withoutGlobalScopes()
            ->where('type', InsightType::KEYWORD_DROP->value)
            ->whereNull('acknowledged_at')
            ->count());
    }
}
