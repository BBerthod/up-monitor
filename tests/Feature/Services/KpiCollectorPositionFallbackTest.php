<?php

namespace Tests\Feature\Services;

use App\Models\KpiSnapshot;
use App\Models\Site;
use App\Models\Team;
use App\Services\KpiCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for KpiCollector::cleanedAveragePosition() fallback paths (Fix 3).
 *
 * collectGsc() issues TWO POST requests to the identical GSC endpoint
 * (aggregate first, per-query breakdown second). Http::sequence() serves a
 * distinct response to each. These cases cover the two fallback branches:
 *   - 2nd request fails      → 'aggregate_fallback' (raw byProperty position)
 *   - no engaged queries     → 'weighted_all' (impressions-weighted over all)
 *
 * Companion file (nominal filtering): KpiCollectorCleanedPositionTest.
 */
class KpiCollectorPositionFallbackTest extends TestCase
{
    use RefreshDatabase;

    private KpiCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (! $key) {
            $this->markTestSkipped('openssl_pkey_new unavailable in this environment.');
        }
        openssl_pkey_export($key, $pem);

        config([
            'services.google.client_email' => 'test@example.iam.gserviceaccount.com',
            'services.google.private_key' => $pem,
        ]);

        $this->collector = app(KpiCollector::class);
    }

    private function makeSite(Team $team, string $domain): Site
    {
        return Site::factory()->for($team)->create([
            'primary_domain' => $domain,
            'domains' => [$domain],
            'gsc_property' => 'sc-domain:'.$domain,
            'ga4_property' => null,
            'bing_url' => null,
            'is_active' => true,
        ]);
    }

    /**
     * When the second GSC request (query breakdown) returns a 500, the collector
     * must fall back to the aggregate position from the first request (12.0) and
     * tag the source as aggregate_fallback.
     */
    public function test_cleaned_position_falls_back_to_aggregate_when_second_request_fails(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team, 'fallback.example.com');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'www.googleapis.com/webmasters/*' => Http::sequence()
                ->push(['rows' => [['clicks' => 100, 'impressions' => 8000, 'ctr' => 0.0125, 'position' => 12.0]]], 200)
                ->push('Internal Server Error', 500),
            '*' => Http::response([], 200),
        ]);

        $this->collector->collectForSite($site);

        $snapshot = KpiSnapshot::where('site', 'fallback.example.com')
            ->where('source', 'gsc')
            ->where('metric', 'position_28d')
            ->latest('captured_at')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('aggregate_fallback', ($snapshot->meta ?? [])['position_source'] ?? null);
        $this->assertEqualsWithDelta(12.0, (float) $snapshot->value, 0.1);
    }

    /**
     * When every query is below the engagement floor (0 clicks, < 10 impressions),
     * no query qualifies as engaged. cleanedAveragePosition() falls back to a
     * weighted mean over ALL queries → 'weighted_all'.
     *
     * Rows: positions 20..24, 5 impressions each → weighted avg = 22.0.
     */
    public function test_cleaned_position_uses_weighted_all_when_no_engaged_queries(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team, 'low-traffic.example.com');

        $rows = array_map(
            fn (int $i) => ['keys' => ['query-'.$i], 'clicks' => 0, 'impressions' => 5, 'ctr' => 0.0, 'position' => (float) (20 + $i)],
            range(0, 4),
        );

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'www.googleapis.com/webmasters/*' => Http::sequence()
                ->push(['rows' => [['clicks' => 0, 'impressions' => 25, 'ctr' => 0.0, 'position' => 22.0]]], 200)
                ->push(['rows' => $rows], 200),
            '*' => Http::response([], 200),
        ]);

        $this->collector->collectForSite($site);

        $snapshot = KpiSnapshot::where('site', 'low-traffic.example.com')
            ->where('source', 'gsc')
            ->where('metric', 'position_28d')
            ->latest('captured_at')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('weighted_all', ($snapshot->meta ?? [])['position_source'] ?? null);
        $this->assertEqualsWithDelta(22.0, (float) $snapshot->value, 0.5);
    }
}
