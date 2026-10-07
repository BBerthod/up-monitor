<?php

namespace Tests\Feature\Services;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Site;
use App\Models\Team;
use App\Services\AdSenseCollector;
use App\Services\KpiCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for AdSenseCollector::collectForSite().
 *
 * This is the first service in Up to record an actual monetary figure. Until
 * now every "revenue" signal was a proxy derived from GSC clicks, which cannot
 * tell a page that earns from one that does not — nor see a revenue collapse
 * that leaves traffic untouched.
 *
 * The service-account credentials are faked through config so no real token
 * exchange is attempted; the token endpoint is stubbed alongside the API.
 */
class AdSenseCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal viable service-account config. The private key must be a real
        // RSA key because the JWT is genuinely signed before the (faked) token
        // exchange — a dummy string would fail at openssl_sign().
        config([
            'services.google.client_email' => 'up@example.iam.gserviceaccount.com',
            'services.google.private_key' => $this->generatePrivateKey(),
            'services.adsense.account_id' => 'pub-0000000000000000',
        ]);
    }

    private function generatePrivateKey(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $key);

        return $key;
    }

    private function makeService(): AdSenseCollector
    {
        return new AdSenseCollector(app(KpiCollector::class));
    }

    private function makeSite(?array $adNetworks = ['adsense']): Site
    {
        return Site::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'ad_networks' => $adNetworks,
            'is_active' => true,
        ]);
    }

    /**
     * A realistic reports:generate response.
     */
    private function reportResponse(
        string $earnings = '42.5',
        string $impressions = '15000',
        string $requests = '20000',
        string $rpm = '2.83',
    ): array {
        return [
            'headers' => [
                ['name' => 'DOMAIN_NAME'],
                ['name' => 'ESTIMATED_EARNINGS'],
                ['name' => 'IMPRESSIONS'],
                ['name' => 'AD_REQUESTS'],
                ['name' => 'IMPRESSIONS_RPM'],
            ],
            'rows' => [
                ['cells' => [
                    ['value' => 'example.com'],
                    ['value' => $earnings],
                    ['value' => $impressions],
                    ['value' => $requests],
                    ['value' => $rpm],
                ]],
            ],
        ];
    }

    private function fakeGoogle(array $reportBody, int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-token'], 200),
            'adsense.googleapis.com/*' => Http::response($reportBody, $status),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Real earnings land in kpi_snapshots
    // ──────────────────────────────────────────────────────────────────────

    public function test_persists_earnings_and_derived_fill_rate(): void
    {
        $site = $this->makeSite();
        $this->fakeGoogle($this->reportResponse());

        $written = $this->makeService()->collectForSite($site);

        // 4 API metrics + the derived fill rate.
        $this->assertSame(5, $written);

        $snapshots = KpiSnapshot::where('site', 'example.com')
            ->where('source', KpiSource::ADSENSE->value)
            ->pluck('value', 'metric');

        $this->assertEquals(42.5, (float) $snapshots['earnings_28d']);
        $this->assertEquals(15000, (float) $snapshots['ad_impressions_28d']);
        $this->assertEquals(2.83, (float) $snapshots['rpm_28d']);

        // 15000 / 20000 = 75% fill — the ratio the API does not return, and the
        // one that exposes unfilled inventory.
        $this->assertEquals(75.0, (float) $snapshots['ad_fill_rate_28d']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. "Earned nothing" is recorded, not silently skipped
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_with_no_rows_records_explicit_zeroes(): void
    {
        $site = $this->makeSite();
        $this->fakeGoogle(['headers' => [], 'rows' => []]);

        $written = $this->makeService()->collectForSite($site);

        $this->assertSame(4, $written);

        $earnings = KpiSnapshot::where('site', 'example.com')
            ->where('metric', 'earnings_28d')
            ->first();

        // A site serving nothing is a real state; it must be distinguishable
        // downstream from "we never asked".
        $this->assertNotNull($earnings);
        $this->assertEquals(0.0, (float) $earnings->value);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Sites not on AdSense are skipped before any network call
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_without_adsense_is_skipped(): void
    {
        $site = $this->makeSite(adNetworks: ['taboola']);
        Http::fake();

        $this->assertSame(0, $this->makeService()->collectForSite($site));
        Http::assertNothingSent();
    }

    public function test_site_with_no_ad_networks_is_skipped(): void
    {
        $site = $this->makeSite(adNetworks: null);
        Http::fake();

        $this->assertSame(0, $this->makeService()->collectForSite($site));
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. The www. prefix must be stripped from the domain filter
    // ──────────────────────────────────────────────────────────────────────

    public function test_www_prefix_is_stripped_from_the_domain_filter(): void
    {
        $site = $this->makeSite();
        $site->update(['adsense_domain' => 'www.example.com']);

        $this->fakeGoogle($this->reportResponse());

        $this->makeService()->collectForSite($site);

        // DOMAIN_NAME reports the bare host: filtering on "www." matches nothing
        // and is indistinguishable from a site that earned zero.
        Http::assertSent(fn ($request) => str_contains(
            urldecode($request->url()),
            'DOMAIN_NAME==example.com',
        ));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Per-site account overrides the fleet default
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_level_account_id_overrides_the_default(): void
    {
        $site = $this->makeSite();
        $site->update(['adsense_account_id' => 'pub-1111111111111111']);

        $this->fakeGoogle($this->reportResponse());

        $this->makeService()->collectForSite($site);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'pub-1111111111111111'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Failures are absorbed, never thrown into the queue chain
    // ──────────────────────────────────────────────────────────────────────

    public function test_api_error_is_handled_without_writing_snapshots(): void
    {
        $site = $this->makeSite();
        $this->fakeGoogle(['error' => ['message' => 'forbidden']], status: 403);

        $this->assertSame(0, $this->makeService()->collectForSite($site));
        $this->assertDatabaseMissing('kpi_snapshots', [
            'site' => 'example.com',
            'source' => KpiSource::ADSENSE->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. No credentials configured → silent no-op
    // ──────────────────────────────────────────────────────────────────────

    public function test_missing_account_configuration_is_a_no_op(): void
    {
        config(['services.adsense.account_id' => null]);

        $site = $this->makeSite();
        Http::fake();

        $this->assertSame(0, $this->makeService()->collectForSite($site));
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. Metrics are matched by header position, not assumed order
    // ──────────────────────────────────────────────────────────────────────

    public function test_metrics_are_matched_by_header_not_position(): void
    {
        $site = $this->makeSite();

        // Same data, columns reordered — a positional reader would mis-assign
        // every value here.
        $this->fakeGoogle([
            'headers' => [
                ['name' => 'DOMAIN_NAME'],
                ['name' => 'IMPRESSIONS_RPM'],
                ['name' => 'ESTIMATED_EARNINGS'],
                ['name' => 'AD_REQUESTS'],
                ['name' => 'IMPRESSIONS'],
            ],
            'rows' => [
                ['cells' => [
                    ['value' => 'example.com'],
                    ['value' => '2.83'],
                    ['value' => '42.5'],
                    ['value' => '20000'],
                    ['value' => '15000'],
                ]],
            ],
        ]);

        $this->makeService()->collectForSite($site);

        $snapshots = KpiSnapshot::where('site', 'example.com')->pluck('value', 'metric');

        $this->assertEquals(42.5, (float) $snapshots['earnings_28d']);
        $this->assertEquals(2.83, (float) $snapshots['rpm_28d']);
        $this->assertEquals(15000, (float) $snapshots['ad_impressions_28d']);
    }
}
