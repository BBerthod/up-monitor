<?php

namespace Tests\Feature\Services;

use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Services\KpiCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for KpiCollector::collectForSite().
 *
 * RSA key strategy
 * ────────────────
 * KpiCollector::getGoogleAccessToken() calls openssl_sign() with the private key
 * from config. A real key pair must be supplied, otherwise openssl_sign() returns
 * false and the token call is skipped (returning null → no snapshots created).
 *
 * We generate a 2048-bit RSA key pair in setUp() and inject the private key PEM
 * into config so the JWT signing succeeds. The token endpoint is then faked with
 * Http::fake() to return a dummy access_token.
 */
class KpiCollectorSiteTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKeyPem = '';

    private KpiCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate a fresh RSA 2048-bit key pair for this test run.
        // This is the minimum required by openssl_sign() with SHA256.
        // Some minimal CI/container environments lack a usable OpenSSL RNG/config;
        // skip rather than crash so the suite stays green where crypto isn't available.
        $resource = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false || ! @openssl_pkey_export($resource, $this->privateKeyPem) || $this->privateKeyPem === '') {
            $this->markTestSkipped('OpenSSL RSA key generation unavailable in this environment.');
        }

        // Inject fake Google service-account credentials into config so the
        // JWT generation path executes (otherwise token returns null → skipped).
        config([
            'services.google.client_email' => 'test-sa@test-project.iam.gserviceaccount.com',
            'services.google.private_key' => $this->privateKeyPem,
        ]);

        $this->collector = app(KpiCollector::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // collectForSite — GSC property routing
    // ──────────────────────────────────────────────────────────────────────────

    public function test_collect_for_site_uses_gsc_property(): void
    {
        // Fake the Google oauth token endpoint and the GSC API.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/webmasters/*' => Http::response([
                'rows' => [
                    [
                        'clicks' => 120.0,
                        'impressions' => 5000.0,
                        'ctr' => 0.024,
                        'position' => 8.5,
                    ],
                ],
            ]),
            // TTFB probe — respond quickly so samples complete.
            '*' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'examplestore.com',
            'domains' => ['examplestore.com'],
            'gsc_property' => 'sc-domain:examplestore.com',
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $snapshots = $this->collector->collectForSite($site);

        // At least the GSC metrics should have been collected.
        $this->assertNotEmpty($snapshots);

        // The GSC API call must have included the sc-domain property URL-encoded.
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'webmasters')) {
                return false;
            }

            // URL should contain "sc-domain%3Aexamplestore.com" (URL-encoded colon).
            return str_contains($request->url(), urlencode('sc-domain:examplestore.com'));
        });

        // Snapshot site key must match siteNameFromUrl("https://examplestore.com").
        $this->assertDatabaseHas('kpi_snapshots', [
            'site' => 'examplestore.com',
            'source' => 'gsc',
            'metric' => 'clicks_28d',
        ]);
    }

    public function test_collect_for_site_skips_gsc_when_property_null(): void
    {
        Http::fake([
            // Allow TTFB probes to succeed.
            '*' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'no-gsc.example.com',
            'domains' => ['no-gsc.example.com'],
            'gsc_property' => null,
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $this->collector->collectForSite($site);

        // No GSC snapshot must have been persisted.
        $this->assertDatabaseMissing('kpi_snapshots', ['source' => 'gsc']);

        // The GSC webmasters API must never have been called.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webmasters'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // collectForSite — GA4 property routing
    // ──────────────────────────────────────────────────────────────────────────

    public function test_collect_for_site_uses_ga4_property(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            'analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [
                    [
                        'metricValues' => [
                            ['value' => '350'],  // activeUsers
                            ['value' => '420'],  // sessions
                            ['value' => '1100'], // screenPageViews
                        ],
                    ],
                ],
            ]),
            // TTFB probes.
            '*' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'ga4site.example.com',
            'domains' => ['ga4site.example.com'],
            'gsc_property' => null,
            'ga4_property' => 'p528945610',
            'is_active' => true,
        ]);

        $snapshots = $this->collector->collectForSite($site);

        $this->assertNotEmpty($snapshots);

        // The GA4 request must target the site's own property with the "p" prefix
        // already stripped — "properties/528945610", NOT "properties/p528945610".
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'analyticsdata.googleapis.com')) {
                return false;
            }

            return str_contains($request->url(), 'properties/528945610')
                && ! str_contains($request->url(), 'properties/p528945610');
        });

        $this->assertDatabaseHas('kpi_snapshots', [
            'site' => 'ga4site.example.com',
            'source' => 'ga4',
            'metric' => 'users_28d',
        ]);
    }

    public function test_collect_for_site_skips_ga4_when_property_null(): void
    {
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'no-ga4.example.com',
            'domains' => ['no-ga4.example.com'],
            'gsc_property' => null,
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $this->collector->collectForSite($site);

        $this->assertDatabaseMissing('kpi_snapshots', ['source' => 'ga4']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'analyticsdata.googleapis.com'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Site key coherence
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Regression test for the ltrim() bug: ltrim($host, 'www.') strips every
     * leading character in the set {w, .}, so "webcompare.fr" wrongly became
     * "ebcompare.fr". siteNameFromUrl must only remove a literal "www." prefix.
     *
     * @dataProvider siteNameCases
     */
    public function test_site_name_only_strips_www_prefix(string $url, string $expected): void
    {
        $this->assertSame($expected, $this->collector->siteNameFromUrl($url));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function siteNameCases(): array
    {
        return [
            'leading w kept' => ['https://webcompare.fr/', 'webcompare.fr'],
            'www stripped' => ['https://www.webcompare.fr/', 'webcompare.fr'],
            'double w domain' => ['https://wweb.example.com/', 'wweb.example.com'],
            'no www' => ['https://examplestore.com', 'examplestore.com'],
            'subdomain kept' => ['https://de.examplestore.com/', 'de.examplestore.com'],
            'www only once' => ['https://www.www-site.com/', 'www-site.com'],
        ];
    }

    public function test_site_key_strips_www_prefix(): void
    {
        // Even when the primary domain has a www. prefix, the stored site key
        // must be the bare hostname (as siteNameFromUrl strips www.).
        Http::fake(['*' => Http::response('', 200)]);

        $team = Team::factory()->create();
        // SiteFactory sets primary_domain; we override with www. variant.
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'www.strip-www.example.com',
            'domains' => ['www.strip-www.example.com'],
            'gsc_property' => null,
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $this->collector->collectForSite($site);

        // TTFB snapshots stored — key must be bare hostname without www.
        $this->assertDatabaseHas('kpi_snapshots', [
            'site' => 'strip-www.example.com',
        ]);
    }

    public function test_site_key_for_multi_locale_domain_template(): void
    {
        // Multi-locale site: "{locale}.examplestore.com" with primary_locale "fr".
        // resolvedPrimaryDomain() → "fr.examplestore.com"
        // siteNameFromUrl("https://fr.examplestore.com") → "fr.examplestore.com"
        Http::fake(['*' => Http::response('', 200)]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'fr.examplestore.com',
            'domains' => ['{locale}.examplestore.com'],
            'primary_locale' => 'fr',
            'locales' => ['fr', 'us'],
            'gsc_property' => null,
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $this->collector->collectForSite($site);

        $this->assertDatabaseHas('kpi_snapshots', [
            'site' => 'fr.examplestore.com',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // collectForSite — Bing collection
    // ──────────────────────────────────────────────────────────────────────────

    public function test_collect_for_site_collects_bing_when_url_set(): void
    {
        // Inject a fake Bing API key so the empty-key guard does not fire.
        config(['services.bing.api_key' => 'fake-bing-key']);

        // Build three in-window OData rows with known totals.
        // 2 clicks / 50 impr  +  1 click / 30 impr  +  0 clicks / 20 impr
        //  → 3 clicks total, 100 impressions total, CTR = 3/100*100 = 3.0
        $rows = [
            ['Clicks' => 2, 'Impressions' => 50, 'Date' => '/Date('.now()->subDays(5)->valueOf().'-0000)/'],
            ['Clicks' => 1, 'Impressions' => 30, 'Date' => '/Date('.now()->subDays(10)->valueOf().'-0000)/'],
            ['Clicks' => 0, 'Impressions' => 20, 'Date' => '/Date('.now()->subDays(15)->valueOf().'-0000)/'],
        ];

        Http::fake([
            // Bing Webmaster REST API — returns OData rows.
            'ssl.bing.com/*' => Http::response(['d' => $rows]),
            // Google OAuth token endpoint — needed by setUp() but Bing does not call it;
            // kept here so TTFB probes do not trigger unexpected-call failures.
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            // Catch-all for TTFB probes.
            '*' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'bingtest.example.com',
            'domains' => ['bingtest.example.com'],
            'bing_url' => 'https://bingtest.example.com/',
            'gsc_property' => null,
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $this->collector->collectForSite($site);

        // Three Bing snapshots must have been persisted with the correct aggregated values.
        $this->assertDatabaseHas('kpi_snapshots', [
            'source' => 'bing',
            'metric' => 'bing_clicks_28d',
            'value' => 3,
        ]);
        $this->assertDatabaseHas('kpi_snapshots', [
            'source' => 'bing',
            'metric' => 'bing_impressions_28d',
            'value' => 100,
        ]);
        $this->assertDatabaseHas('kpi_snapshots', [
            'source' => 'bing',
            'metric' => 'bing_ctr_28d',
            'value' => 3.0,
        ]);

        // The Bing API call must have included the property URL in the query string.
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'GetRankAndTrafficStats')) {
                return false;
            }

            // The request must carry both the api key and the siteUrl parameter.
            return str_contains($request->url(), 'apikey=fake-bing-key')
                && str_contains($request->url(), 'siteUrl=');
        });
    }

    public function test_collect_for_site_skips_bing_when_url_null(): void
    {
        // bing_url is explicitly null — no Bing collection should occur even if the key
        // is present, because there is no registered Bing Webmaster property for the site.
        config(['services.bing.api_key' => 'fake-bing-key']);

        Http::fake(['*' => Http::response('', 200)]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'no-bing.example.com',
            'domains' => ['no-bing.example.com'],
            'bing_url' => null,
            'gsc_property' => null,
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $this->collector->collectForSite($site);

        $this->assertDatabaseMissing('kpi_snapshots', ['source' => 'bing']);

        // The Bing REST API must never have been contacted.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ssl.bing.com'));
    }

    public function test_collect_for_site_skips_bing_when_key_missing(): void
    {
        // When the API key env var is absent the early-exit guard fires before any HTTP
        // call — bing_url being set is not enough on its own.
        config(['services.bing.api_key' => null]);

        Http::fake(['*' => Http::response('', 200)]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'no-key.example.com',
            'domains' => ['no-key.example.com'],
            'bing_url' => 'https://no-key.example.com/',
            'gsc_property' => null,
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $this->collector->collectForSite($site);

        $this->assertDatabaseMissing('kpi_snapshots', ['source' => 'bing']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ssl.bing.com'));
    }

    public function test_bing_ignores_rows_outside_window(): void
    {
        // Mix rows inside and outside the [today-29d, yesterday] aggregation window.
        // Only the two recent rows should be counted; the old row (200 days ago) must
        // be excluded — this guards the date-filter regex against off-by-one drift.
        config(['services.bing.api_key' => 'fake-bing-key']);

        $rows = [
            // Inside window: 5 clicks / 80 impr
            ['Clicks' => 3, 'Impressions' => 50, 'Date' => '/Date('.now()->subDays(7)->valueOf().'-0000)/'],
            ['Clicks' => 2, 'Impressions' => 30, 'Date' => '/Date('.now()->subDays(20)->valueOf().'-0000)/'],
            // Outside window (200 days ago): must be ignored entirely.
            ['Clicks' => 999, 'Impressions' => 99999, 'Date' => '/Date('.now()->subDays(200)->valueOf().'-0000)/'],
        ];

        Http::fake([
            'ssl.bing.com/*' => Http::response(['d' => $rows]),
            '*' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'window-filter.example.com',
            'domains' => ['window-filter.example.com'],
            'bing_url' => 'https://window-filter.example.com/',
            'gsc_property' => null,
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $this->collector->collectForSite($site);

        // Only the two in-window rows contribute: 5 clicks, 80 impressions.
        $this->assertDatabaseHas('kpi_snapshots', [
            'source' => 'bing',
            'metric' => 'bing_clicks_28d',
            'value' => 5,
        ]);
        $this->assertDatabaseHas('kpi_snapshots', [
            'source' => 'bing',
            'metric' => 'bing_impressions_28d',
            'value' => 80,
        ]);

        // The out-of-window row carried 999 clicks; if the filter leaked those the
        // clicks snapshot would be 1004, not 5 — assert the old value never appears.
        $this->assertDatabaseMissing('kpi_snapshots', [
            'source' => 'bing',
            'metric' => 'bing_clicks_28d',
            'value' => 1004,
        ]);
    }

    public function test_collect_for_site_normalizes_dashboard_bing_url(): void
    {
        // Reproduce the prod bug: bing_url is a Bing Webmaster dashboard link, not
        // the bare property URL. The collector must extract "https://dash.example.com/"
        // from the `siteUrl=` query parameter and pass THAT to GetRankAndTrafficStats —
        // NOT the full dashboard URL which Bing would not recognise.
        config(['services.bing.api_key' => 'fake-bing-key']);

        $rows = [
            ['Clicks' => 4, 'Impressions' => 80, 'Date' => '/Date('.now()->subDays(3)->valueOf().'-0000)/'],
        ];

        Http::fake([
            'ssl.bing.com/*' => Http::response(['d' => $rows]),
            '*' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'dash.example.com',
            'domains' => ['dash.example.com'],
            // Dashboard-form URL — this is what the import stored in prod.
            'bing_url' => 'https://www.bing.com/webmasters/sitemaps?siteUrl=https://dash.example.com',
            'gsc_property' => null,
            'ga4_property' => null,
            'is_active' => true,
        ]);

        $snapshots = $this->collector->collectForSite($site);

        // Snapshots must have been created — the dashboard URL was normalised.
        $this->assertNotEmpty($snapshots);

        // The API call must have gone to ssl.bing.com with the bare property URL,
        // NOT the bing.com dashboard host in the siteUrl parameter.
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'GetRankAndTrafficStats')) {
                return false;
            }

            // siteUrl= must reference the real property host, not bing.com.
            return str_contains($request->url(), 'dash.example.com')
                && ! str_contains($request->url(), 'bing.com/webmasters');
        });

        $this->assertDatabaseHas('kpi_snapshots', [
            'source' => 'bing',
            'metric' => 'bing_clicks_28d',
            'value' => 4,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GA4 property-id normalisation and date-range correctness
    // ──────────────────────────────────────────────────────────────────────────

    public function test_collect_for_site_strips_p_prefix_from_ga4_property(): void
    {
        // Sites imported from YAML carry the "p" prefix (e.g. "p528945610").
        // The GA4 Data API path must receive the bare numeric id only.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            'analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [
                    [
                        'metricValues' => [
                            ['value' => '350'],  // activeUsers → users_28d
                            ['value' => '420'],  // sessions → sessions_28d
                            ['value' => '1100'], // screenPageViews → pageviews_28d
                        ],
                    ],
                ],
            ]),
            // TTFB probes.
            '*' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'prefix-strip.example.com',
            'domains' => ['prefix-strip.example.com'],
            'gsc_property' => null,
            'bing_url' => null,
            'ga4_property' => 'p528945610',
            'is_active' => true,
        ]);

        $snapshots = $this->collector->collectForSite($site);

        $this->assertNotEmpty($snapshots);

        // The HTTP call must target "properties/528945610" (no "p").
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'analyticsdata.googleapis.com')) {
                return false;
            }

            return str_contains($request->url(), 'properties/528945610')
                && ! str_contains($request->url(), 'properties/p528945610');
        });

        // The numeric-only path must NOT appear anywhere with the prefix.
        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'properties/p528945610');
        });

        $this->assertDatabaseHas('kpi_snapshots', [
            'source' => 'ga4',
            'metric' => 'users_28d',
            'value' => 350,
        ]);
    }

    public function test_collect_for_site_ga4_date_range_is_chronological(): void
    {
        // Guard against the inverted-range bug where startDate was "today" and
        // endDate was "28daysAgo" (end < start → GA4 API rejects the request).
        // The correct range is startDate="28daysAgo", endDate="today".
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            'analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [
                    [
                        'metricValues' => [
                            ['value' => '200'],
                            ['value' => '300'],
                            ['value' => '800'],
                        ],
                    ],
                ],
            ]),
            '*' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'date-range.example.com',
            'domains' => ['date-range.example.com'],
            'gsc_property' => null,
            'bing_url' => null,
            'ga4_property' => 'p123456789',
            'is_active' => true,
        ]);

        $this->collector->collectForSite($site);

        // Inspect the POST body sent to the GA4 runReport endpoint.
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'analyticsdata.googleapis.com')) {
                return false;
            }

            $body = $request->data();
            $dateRanges = $body['dateRanges'] ?? [];

            if (empty($dateRanges)) {
                return false;
            }

            $range = $dateRanges[0];

            // startDate must be the past boundary, endDate must be "today".
            return ($range['startDate'] ?? '') === '28daysAgo'
                && ($range['endDate'] ?? '') === 'today';
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // collectGscQueries — sc-domain vs URL-prefix routing
    // ──────────────────────────────────────────────────────────────────────────

    public function test_collect_gsc_queries_uses_site_sc_domain_property(): void
    {
        // A monitor linked to a Site whose gsc_property is an sc-domain property
        // must send that property URL-encoded to the GSC searchAnalytics endpoint,
        // NOT the URL-prefix heuristic derived from the monitor URL.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/webmasters/*' => Http::response([
                'rows' => [
                    [
                        'keys' => ['chaussures running', 'https://webcompare.fr/chaussures'],
                        'clicks' => 42.0,
                        'impressions' => 1200.0,
                        'ctr' => 0.035,
                        'position' => 14.2,
                    ],
                ],
            ]),
        ]);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'webcompare.fr',
            'domains' => ['webcompare.fr'],
            'gsc_property' => 'sc-domain:webcompare.fr',
            'ga4_property' => null,
            'is_active' => true,
        ]);
        $monitor = Monitor::factory()->for($team)->create([
            'type' => 'http',
            'url' => 'https://webcompare.fr/',
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        $rows = $this->collector->collectGscQueries($monitor);

        // The request URL must contain the sc-domain property URL-encoded.
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'webmasters')) {
                return false;
            }

            return str_contains($request->url(), urlencode('sc-domain:webcompare.fr'))
                && ! str_contains($request->url(), urlencode('https://webcompare.fr/'));
        });

        // The rows are returned properly normalised.
        $this->assertNotEmpty($rows);
        $this->assertEquals('chaussures running', $rows[0]['query']);
    }

    public function test_collect_gsc_queries_falls_back_to_url_prefix_without_site(): void
    {
        // A monitor with no linked Site (site_id null) must fall back to the
        // URL-prefix form derived from the monitor URL.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/webmasters/*' => Http::response([
                'rows' => [
                    [
                        'keys' => ['best monitor', 'https://example.com/monitors'],
                        'clicks' => 10.0,
                        'impressions' => 500.0,
                        'ctr' => 0.02,
                        'position' => 12.0,
                    ],
                ],
            ]),
        ]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'type' => 'http',
            'url' => 'https://example.com/',
            'site_id' => null,
            'is_active' => true,
        ]);

        $this->collector->collectGscQueries($monitor);

        // The request URL must contain the URL-prefix form, not any sc-domain form.
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'webmasters')) {
                return false;
            }

            return str_contains($request->url(), urlencode('https://example.com/'))
                && ! str_contains($request->url(), 'sc-domain');
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Backwards compat: collect() and collectForMonitor() still work
    // ──────────────────────────────────────────────────────────────────────────

    public function test_collect_for_monitor_still_collects_ttfb(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'type' => 'http',
            'url' => 'https://legacy.example.com',
            'is_active' => true,
        ]);

        $snapshots = $this->collector->collectForMonitor($monitor);

        $this->assertNotEmpty($snapshots);
        $this->assertDatabaseHas('kpi_snapshots', [
            'site' => 'legacy.example.com',
            'source' => 'ttfb',
        ]);
    }
}
