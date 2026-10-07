<?php

namespace Tests\Feature\Services;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Site;
use App\Models\Team;
use App\Services\CruxCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for CruxCollector::collectForSite().
 *
 * The gap it closes: Up's only performance signal was Lighthouse — synthetic,
 * one machine, one connection, one moment. Google ranks on field data instead,
 * and the two diverge in the direction that matters: a page can score 90 in the
 * lab while its p75 LCP in the field is several seconds, because real visitors
 * are on mid-range Android over mobile data.
 */
class CruxCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.pagespeed_api_key' => 'test-key',
            'services.google.pagespeed_api_keys' => null,
        ]);
    }

    private function makeSite(): Site
    {
        return Site::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'is_active' => true,
        ]);
    }

    /**
     * A realistic queryRecord response.
     */
    private function record(
        int $lcp = 3200,
        int $inp = 180,
        string $cls = '0.08',
        float $lcpGood = 0.62,
    ): array {
        return [
            'record' => [
                'key' => ['origin' => 'https://example.com'],
                'metrics' => [
                    'largest_contentful_paint' => [
                        'histogram' => [
                            ['start' => 0, 'end' => 2500, 'density' => $lcpGood],
                            ['start' => 2500, 'end' => 4000, 'density' => 0.25],
                            ['start' => 4000, 'density' => round(1 - $lcpGood - 0.25, 2)],
                        ],
                        'percentiles' => ['p75' => $lcp],
                    ],
                    'interaction_to_next_paint' => [
                        'histogram' => [
                            ['start' => 0, 'end' => 200, 'density' => 0.8],
                            ['start' => 200, 'end' => 500, 'density' => 0.15],
                            ['start' => 500, 'density' => 0.05],
                        ],
                        'percentiles' => ['p75' => $inp],
                    ],
                    'cumulative_layout_shift' => [
                        'histogram' => [
                            ['start' => '0.00', 'end' => '0.10', 'density' => 0.9],
                            ['start' => '0.10', 'end' => '0.25', 'density' => 0.07],
                            ['start' => '0.25', 'density' => 0.03],
                        ],
                        'percentiles' => ['p75' => $cls],
                    ],
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Field vitals land in kpi_snapshots
    // ──────────────────────────────────────────────────────────────────────

    public function test_persists_p75_and_good_share_for_each_vital(): void
    {
        $site = $this->makeSite();

        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response($this->record(), 200)]);

        // 3 vitals × (p75 + good share).
        $this->assertSame(6, (new CruxCollector)->collectForSite($site));

        $snapshots = KpiSnapshot::where('site', 'example.com')
            ->where('source', KpiSource::CRUX->value)
            ->pluck('value', 'metric');

        $this->assertEquals(3200, (float) $snapshots['lcp_p75']);
        $this->assertEquals(180, (float) $snapshots['inp_p75']);
        $this->assertEquals(0.08, (float) $snapshots['cls_p75']);

        // The good share is what separates a long tail from a broadly slow
        // site — the same p75 can mean either, and the fix differs.
        $this->assertEquals(62.0, (float) $snapshots['lcp_good_pct']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Insufficient field data is a fact, not an error
    // ──────────────────────────────────────────────────────────────────────

    public function test_origin_without_enough_traffic_is_not_an_error(): void
    {
        $site = $this->makeSite();

        // CrUX answers 404 for origins below its reporting threshold — routine
        // for the smaller sites in a portfolio.
        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response([
            'error' => ['code' => 404, 'message' => 'chrome ux report data not found'],
        ], 404)]);

        $this->assertSame(0, (new CruxCollector)->collectForSite($site));
        $this->assertDatabaseMissing('kpi_snapshots', [
            'site' => 'example.com',
            'source' => KpiSource::CRUX->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. No API key → silent no-op
    // ──────────────────────────────────────────────────────────────────────

    public function test_missing_api_key_is_a_no_op(): void
    {
        config([
            'services.google.pagespeed_api_key' => null,
            'services.google.pagespeed_api_keys' => null,
        ]);

        Http::fake();

        $this->assertSame(0, (new CruxCollector)->collectForSite($this->makeSite()));
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. The rotation list is accepted as a key source
    // ──────────────────────────────────────────────────────────────────────

    public function test_first_key_of_the_rotation_list_is_used(): void
    {
        config([
            'services.google.pagespeed_api_key' => null,
            'services.google.pagespeed_api_keys' => 'first-key,second-key',
        ]);

        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response($this->record(), 200)]);

        (new CruxCollector)->collectForSite($this->makeSite());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'key=first-key'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. The query is origin-level and mobile
    // ──────────────────────────────────────────────────────────────────────

    public function test_queries_the_origin_on_the_phone_form_factor(): void
    {
        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response($this->record(), 200)]);

        (new CruxCollector)->collectForSite($this->makeSite());

        Http::assertSent(function ($request) {
            $body = $request->data();

            // Origin-level so smaller sites clear the reporting threshold;
            // PHONE because that is the harder and the ranked case.
            return $body['origin'] === 'https://example.com'
                && $body['formFactor'] === 'PHONE';
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. API failure is absorbed
    // ──────────────────────────────────────────────────────────────────────

    public function test_api_error_writes_nothing(): void
    {
        $site = $this->makeSite();

        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->assertSame(0, (new CruxCollector)->collectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. A partial record yields what it has
    // ──────────────────────────────────────────────────────────────────────

    public function test_partial_record_persists_available_metrics_only(): void
    {
        $site = $this->makeSite();

        // Some origins report LCP but lack the interaction data.
        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response([
            'record' => [
                'metrics' => [
                    'largest_contentful_paint' => [
                        'histogram' => [['start' => 0, 'end' => 2500, 'density' => 0.7]],
                        'percentiles' => ['p75' => 2100],
                    ],
                ],
            ],
        ], 200)]);

        $this->assertSame(2, (new CruxCollector)->collectForSite($site));

        $this->assertDatabaseHas('kpi_snapshots', [
            'site' => 'example.com',
            'metric' => 'lcp_p75',
        ]);
        $this->assertDatabaseMissing('kpi_snapshots', [
            'site' => 'example.com',
            'metric' => 'inp_p75',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. CLS precision must survive the round trip
    // ──────────────────────────────────────────────────────────────────────

    public function test_cls_keeps_its_decimal_precision(): void
    {
        $site = $this->makeSite();

        // CLS arrives as a decimal string and is small by nature; rounding it
        // like a millisecond timing would flatten it to zero.
        Http::fake(['chromeuxreport.googleapis.com/*' => Http::response(
            $this->record(cls: '0.0412'),
            200,
        )]);

        (new CruxCollector)->collectForSite($site);

        $cls = KpiSnapshot::where('site', 'example.com')->where('metric', 'cls_p75')->first();

        $this->assertEquals(0.0412, (float) $cls->value);
    }
}
