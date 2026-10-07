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
 * Tests for KpiCollector::cleanedAveragePosition() via collectForSite() (Fix 3).
 *
 * Verifies phantom/spam queries are excluded from the weighted average position,
 * and that the other aggregate metrics stay untouched. collectGsc() makes TWO
 * POST requests to the identical GSC endpoint (aggregate then per-query), so
 * Http::sequence() is mandatory to serve each a distinct response.
 *
 * Companion file (fallback paths): KpiCollectorPositionFallbackTest.
 */
class KpiCollectorCleanedPositionTest extends TestCase
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
     * Aggregate position = 60 (dragged down by phantom queries). The per-query
     * breakdown has 1 engaged query at position 5 (1000 impr, 50 clicks) plus 50
     * phantoms at position 60 (1 impr, 0 clicks). Only the engaged query qualifies,
     * so the cleaned position must be ≈ 5 with source 'weighted_filtered'.
     */
    public function test_cleaned_position_filters_phantom_queries_to_engaged_average(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team, 'example.com');

        $phantomRows = array_map(
            fn (int $i) => ['keys' => ['phantom-'.$i], 'clicks' => 0, 'impressions' => 1, 'ctr' => 0, 'position' => 60.0],
            range(0, 49),
        );

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'www.googleapis.com/webmasters/*' => Http::sequence()
                ->push(['rows' => [['clicks' => 300, 'impressions' => 51050, 'ctr' => 0.006, 'position' => 60.0]]], 200)
                ->push(['rows' => array_merge(
                    [['keys' => ['best-query'], 'clicks' => 50, 'impressions' => 1000, 'ctr' => 0.05, 'position' => 5.0]],
                    $phantomRows,
                )], 200),
            '*' => Http::response([], 200),
        ]);

        $this->collector->collectForSite($site);

        $snapshot = KpiSnapshot::where('site', 'example.com')
            ->where('source', 'gsc')
            ->where('metric', 'position_28d')
            ->latest('captured_at')
            ->first();

        $this->assertNotNull($snapshot, 'position_28d snapshot must exist');
        $this->assertSame('weighted_filtered', ($snapshot->meta ?? [])['position_source'] ?? null);
        $this->assertEqualsWithDelta(5.0, (float) $snapshot->value, 0.5);
    }

    /**
     * The cleaning only affects position_28d. impressions_28d / clicks_28d /
     * ctr_28d must be stored verbatim from the aggregate byProperty row, while
     * position reflects the cleaned value (8), not the polluted aggregate (60).
     */
    public function test_aggregate_metrics_are_unaffected_by_position_cleaning(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team, 'metrics.example.com');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'www.googleapis.com/webmasters/*' => Http::sequence()
                ->push(['rows' => [['clicks' => 500, 'impressions' => 20000, 'ctr' => 0.025, 'position' => 60.0]]], 200)
                ->push(['rows' => [['keys' => ['real-query'], 'clicks' => 500, 'impressions' => 20000, 'ctr' => 0.025, 'position' => 8.0]]], 200),
            '*' => Http::response([], 200),
        ]);

        $this->collector->collectForSite($site);

        $snap = fn (string $m) => KpiSnapshot::where('site', 'metrics.example.com')
            ->where('source', 'gsc')->where('metric', $m)->latest('captured_at')->first();

        $this->assertEqualsWithDelta(20000, (float) $snap('impressions_28d')?->value, 0.01);
        $this->assertEqualsWithDelta(500, (float) $snap('clicks_28d')?->value, 0.01);
        $this->assertEqualsWithDelta(2.5, (float) $snap('ctr_28d')?->value, 0.01);

        $pos = $snap('position_28d');
        $this->assertNotNull($pos);
        $this->assertSame('weighted_filtered', ($pos->meta ?? [])['position_source'] ?? null);
        $this->assertEqualsWithDelta(8.0, (float) $pos->value, 0.1);
    }
}
