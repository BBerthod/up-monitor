<?php

namespace Tests\Feature\Services;

use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Services\HealthScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests the monetization dimension of the composite health score.
 *
 * The gap it closes: the other four dimensions answer "is the site working".
 * A site can pass all of them — up, fast, well ranked — while earning nothing,
 * because its consent platform vanished or its ad slots stopped filling. That
 * has happened in this fleet more than once, and scored an untroubled A each
 * time.
 */
class MonetizationHealthDimensionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): HealthScoreService
    {
        return app(HealthScoreService::class);
    }

    /**
     * A monitor whose site declares the given monetization configuration.
     */
    private function makeMonitor(
        ?array $adNetworks = ['adsense'],
        ?array $merchants = null,
    ): Monitor {
        $team = Team::factory()->create();

        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'ad_networks' => $adNetworks,
            'merchant_domains' => $merchants,
        ]);

        return Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'site_id' => $site->id,
        ]);
    }

    private function recordEarnings(float $value, int $daysAgo): void
    {
        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::ADSENSE->value,
            'metric' => 'earnings_28d',
            'value' => $value,
            'period_days' => 28,
            'captured_at' => now()->subDays($daysAgo),
        ]);
    }

    private function recordFillRate(float $value, int $daysAgo = 0): void
    {
        KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::ADSENSE->value,
            'metric' => 'ad_fill_rate_28d',
            'value' => $value,
            'period_days' => 28,
            'captured_at' => now()->subDays($daysAgo),
        ]);
    }

    private function monetization(Monitor $monitor): array
    {
        return $this->service()->scoreForMonitor($monitor)['breakdown']['monetization'];
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Non-monetised sites are exempt, not penalised
    // ──────────────────────────────────────────────────────────────────────

    public function test_dimension_does_not_apply_to_non_monetised_sites(): void
    {
        $monitor = $this->makeMonitor(adNetworks: null, merchants: null);

        $breakdown = $this->monetization($monitor);

        $this->assertFalse($breakdown['applies']);
        // Weight reported as 0: it was redistributed, not merely neutralised.
        $this->assertEquals(0.0, $breakdown['weight']);
        $this->assertSame('not_monetised', $breakdown['detail']['reason']);
    }

    public function test_non_monetised_site_scores_the_same_as_before_the_dimension_existed(): void
    {
        $monitor = $this->makeMonitor(adNetworks: null, merchants: null);

        // With redistribution, a brochure site's score must be identical to the
        // pure core composite — the monetization dimension must not move it.
        //
        // Here SEO, perf and TTFB have no data, so they are excluded and their
        // weight is renormalised onto uptime, which is 100 by convention when a
        // monitor has no checks yet. The score is therefore uptime alone.
        $result = $this->service()->scoreForMonitor($monitor);

        $this->assertSame(['uptime'], $result['coverage']['available']);
        $this->assertSame($result['breakdown']['uptime']['score'], $result['score']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. An affiliate site counts as monetised even without ad networks
    // ──────────────────────────────────────────────────────────────────────

    public function test_affiliate_only_site_is_monetised(): void
    {
        $monitor = $this->makeMonitor(adNetworks: null, merchants: ['amazon']);

        $this->assertTrue($this->monetization($monitor)['applies']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Revenue holding steady scores well; halving scores badly
    // ──────────────────────────────────────────────────────────────────────

    public function test_steady_revenue_scores_full_marks(): void
    {
        $monitor = $this->makeMonitor();

        foreach ([1, 5, 10, 20, 28] as $daysAgo) {
            $this->recordEarnings(100.0, $daysAgo);
        }

        $breakdown = $this->monetization($monitor);

        $this->assertTrue($breakdown['applies']);
        $this->assertGreaterThanOrEqual(95, $breakdown['score']);
    }

    public function test_collapsed_revenue_scores_poorly(): void
    {
        $monitor = $this->makeMonitor();

        // Earned 100 historically, 30 in the last week.
        foreach ([10, 15, 20, 25, 28] as $daysAgo) {
            $this->recordEarnings(100.0, $daysAgo);
        }

        $this->recordEarnings(30.0, 1);
        $this->recordEarnings(30.0, 2);

        $breakdown = $this->monetization($monitor);

        $this->assertLessThan(60, $breakdown['score']);
        $this->assertLessThan(1.0, $breakdown['detail']['revenue_ratio']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Fill rate catches empty inventory that revenue alone can hide
    // ──────────────────────────────────────────────────────────────────────

    public function test_poor_fill_rate_drags_the_score_down(): void
    {
        $monitor = $this->makeMonitor();

        // Inventory is requested but barely filled — the "one ad slot and it is
        // empty" case, invisible in a revenue figure on a quiet week.
        $this->recordFillRate(10.0);

        $breakdown = $this->monetization($monitor);

        $this->assertTrue($breakdown['applies']);
        $this->assertLessThanOrEqual(10, $breakdown['score']);
        $this->assertEquals(10.0, $breakdown['detail']['fill_rate']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Known revenue blockers apply a penalty
    // ──────────────────────────────────────────────────────────────────────

    public function test_unresolved_blocker_penalises_the_score(): void
    {
        $monitor = $this->makeMonitor();

        foreach ([1, 5, 10, 20, 28] as $daysAgo) {
            $this->recordEarnings(100.0, $daysAgo);
        }

        $withoutBlocker = $this->monetization($monitor)['score'];

        Insight::create([
            'team_id' => $monitor->team_id,
            'site' => 'example.com',
            'site_id' => $monitor->site_id,
            'type' => 'affiliate_leak',
            'severity' => 'critical',
            'title' => 'Affiliate links leaking commission',
            'payload' => [],
            'impact_score' => 100,
            'detected_at' => now(),
        ]);

        $withBlocker = $this->monetization($monitor)['score'];

        $this->assertSame(15, $withoutBlocker - $withBlocker);
    }

    public function test_blocker_penalty_is_capped(): void
    {
        $monitor = $this->makeMonitor();

        foreach ([1, 5, 10, 20, 28] as $daysAgo) {
            $this->recordEarnings(100.0, $daysAgo);
        }

        // Four blockers — the penalty must stop at 30 so one dimension can never
        // zero out an otherwise healthy site on its own.
        foreach (range(1, 4) as $i) {
            Insight::create([
                'team_id' => $monitor->team_id,
                'site' => 'example.com',
                'site_id' => $monitor->site_id,
                'type' => 'affiliate_leak',
                'severity' => 'critical',
                'title' => 'blocker',
                'payload' => [],
                'impact_score' => 100,
                'detected_at' => now(),
            ]);
        }

        $breakdown = $this->monetization($monitor);

        $this->assertSame(4, $breakdown['detail']['blockers']);
        $this->assertGreaterThanOrEqual(70, $breakdown['score']);
    }

    public function test_acknowledged_blockers_do_not_penalise(): void
    {
        $monitor = $this->makeMonitor();

        foreach ([1, 5, 10, 20, 28] as $daysAgo) {
            $this->recordEarnings(100.0, $daysAgo);
        }

        Insight::create([
            'team_id' => $monitor->team_id,
            'site' => 'example.com',
            'site_id' => $monitor->site_id,
            'type' => 'affiliate_leak',
            'severity' => 'critical',
            'title' => 'already handled',
            'payload' => [],
            'impact_score' => 100,
            'detected_at' => now(),
            'acknowledged_at' => now(),
        ]);

        $this->assertSame(0, $this->monetization($monitor)['detail']['blockers']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Monetised but no revenue data yet — neutral, not condemned
    // ──────────────────────────────────────────────────────────────────────

    public function test_monetised_site_without_revenue_data_stays_neutral(): void
    {
        $monitor = $this->makeMonitor();

        $breakdown = $this->monetization($monitor);

        $this->assertTrue($breakdown['applies']);
        $this->assertSame('no_revenue_data', $breakdown['detail']['reason']);
        $this->assertSame(50, $breakdown['score']);
    }

    public function test_blocker_still_counts_without_revenue_data(): void
    {
        $monitor = $this->makeMonitor();

        Insight::create([
            'team_id' => $monitor->team_id,
            'site' => 'example.com',
            'site_id' => $monitor->site_id,
            'type' => 'affiliate_leak',
            'severity' => 'critical',
            'title' => 'Affiliate links leaking commission',
            'payload' => [],
            'impact_score' => 100,
            'detected_at' => now(),
        ]);

        // A missing consent platform is an observed fact, not an inference from
        // a revenue series — it must count even before any earnings arrive.
        $this->assertSame(35, $this->monetization($monitor)['score']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. The composite stays bounded
    // ──────────────────────────────────────────────────────────────────────

    public function test_composite_score_remains_within_bounds(): void
    {
        $monitor = $this->makeMonitor();

        $this->recordFillRate(0.0);

        $score = $this->service()->scoreForMonitor($monitor)['score'];

        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }
}
