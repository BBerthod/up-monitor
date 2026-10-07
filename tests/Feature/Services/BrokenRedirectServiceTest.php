<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\PageMetric;
use App\Models\Site;
use App\Models\Team;
use App\Services\BrokenRedirectService;
use App\Services\KpiCollector;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for BrokenRedirectService::detectForMonitor().
 *
 * Follows the DNS-isolation and Http::fake() conventions documented at length in
 * BrokenPageServiceTest: a synthetic resolver keeps UrlSafetyValidator hermetic,
 * and fakes are registered before the service runs.
 *
 * Redirect probing specifics
 * ──────────────────────────
 * The service calls Http::withoutRedirecting(), so a faked 301 is observed as a
 * 301 rather than being resolved by the client. Fakes therefore return the
 * Location header explicitly and no fake is needed for the merchant itself —
 * which is the point: the service never contacts Amazon.
 */
class BrokenRedirectServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        UrlSafetyValidator::setResolver(function (string $host, int $type): array {
            if ($type === DNS_A) {
                return [['ip' => '8.8.8.8']];
            }

            return [];
        });

        // Most tests isolate one redirect verdict. The dedicated minimum-sample
        // test below restores the production threshold explicitly.
        config()->set('monitoring.affiliate_redirects.min_sample', 1);
    }

    protected function tearDown(): void
    {
        UrlSafetyValidator::setResolver(null);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function makeService(): BrokenRedirectService
    {
        return new BrokenRedirectService(app(KpiCollector::class));
    }

    /**
     * Create a monitor whose site declares Amazon as a merchant, plus one
     * high-impression page for the harvester to scan.
     */
    private function makeAffiliateMonitor(Team $team, ?array $merchants = ['amazon']): Monitor
    {
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'example.com',
            'merchant_domains' => $merchants,
        ]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'site_id' => $site->id,
        ]);

        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => 'https://example.com/review',
            'clicks' => 40,
            'impressions' => 3000,
            'ctr' => round(40 / 3000 * 100, 4),
            'position' => 4.0,
            'captured_at' => now(),
        ]);

        return $monitor;
    }

    private function pageHtmlWith(string ...$hrefs): string
    {
        $links = '';

        foreach ($hrefs as $href) {
            $links .= '<a href="'.$href.'">Buy</a>';
        }

        return '<html><body>'.$links.'</body></html>';
    }

    private function browserProofHtml(string $target = '\/go\/B01ABCDEFG\/?wk_c=1'): string
    {
        return '<html><head><title>Please wait</title></head><body>'
            .'<script data-cfasync="false">(function(){'
            .'var t="fedcba9876543210".split("").reverse().join("");'
            .'document.cookie="wk_go="+t+"; path=/go/; max-age=7200; SameSite=Lax; Secure";'
            .'location.replace("'.$target.'");})();</script>'
            .'</body></html>';
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. The production incident: every redirect 404s → CRITICAL
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_systemic_404_on_affiliate_redirects(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG', '/go/B02HIJKLMN'),
                200,
            ),
            'https://example.com/go/*' => Http::response('Not Found', 404),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::AFFILIATE_REDIRECT_BROKEN->value)
            ->first();

        $this->assertNotNull($insight);
        // 2 of 2 broken = ratio 1.0, at or above the systemic threshold.
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertSame(2, $insight->payload['tested']);
        $this->assertSame(2, $insight->payload['broken']);
        $this->assertEquals(1.0, $insight->payload['failure_ratio']);
        $this->assertEquals(100, (int) $insight->impact_score);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Healthy redirects to the merchant → no insight
    // ──────────────────────────────────────────────────────────────────────

    public function test_healthy_redirect_to_merchant_creates_no_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/*' => Http::response('', 302, [
                'Location' => 'https://www.amazon.fr/dp/B01ABCDEFG?tag=example-21',
            ]),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('insights', [
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2b. Bot protection answering 204 is NOT a broken link.
    //
    //     Four production sites alerted "25 of 25 broken" while a genuine click
    //     on the very same URL returned a correct 302 to Amazon. The /go/ guard
    //     answers 204 to anything that does not look like a real visitor, so a
    //     bare probe was measuring the guard, not the link.
    // ──────────────────────────────────────────────────────────────────────

    public function test_bot_protection_204_is_indeterminate_not_broken(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG', '/go/B02HIJKLMN'),
                200,
            ),
            'https://example.com/go/*' => Http::response('', 204),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('insights', [
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
        ]);
    }

    public function test_protection_and_transient_statuses_are_indeterminate(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);
        $status = 403;

        Http::fake(function ($request) use (&$status) {
            if ($request->url() === 'https://example.com/review') {
                return Http::response($this->pageHtmlWith('/go/B01ABCDEFG'), 200);
            }

            return Http::response('', $status);
        });

        foreach ([403, 429, 503] as $status) {
            $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
        }

        $this->assertDatabaseMissing('insights', [
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
        ]);
    }

    public function test_connection_failure_is_indeterminate(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake(function ($request) {
            if ($request->url() === 'https://example.com/review') {
                return Http::response($this->pageHtmlWith('/go/B01ABCDEFG'), 200);
            }

            throw new ConnectionException('Connection timed out');
        });

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
        $this->assertDatabaseMissing('insights', [
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2c. The probe must present itself as a visitor, or the guard above will
    //     refuse it in production and the whole detector goes blind.
    // ──────────────────────────────────────────────────────────────────────

    public function test_probe_sends_visitor_navigation_headers(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/*' => Http::response('', 302, [
                'Location' => 'https://www.amazon.fr/dp/B01ABCDEFG?tag=example-21',
            ]),
        ]);

        $this->makeService()->detectForMonitor($monitor);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/go/')) {
                return false;
            }

            return $request->header('Referer') === ['https://example.com/review']
                && $request->header('Sec-Fetch-Mode') === ['navigate']
                && $request->header('Sec-Fetch-Dest') === ['document']
                && $request->header('Sec-Fetch-Site') === ['same-origin'];
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2d. A browser-proof page is replayed once, without following the final
    //     merchant redirect.
    // ──────────────────────────────────────────────────────────────────────

    public function test_browser_proof_then_merchant_redirect_creates_no_insight_and_replays_cookie(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/B01ABCDEFG' => Http::response($this->browserProofHtml(), 200),
            'https://example.com/go/B01ABCDEFG/?wk_c=1' => Http::response('', 302, [
                'Location' => 'https://www.amazon.fr/dp/B01ABCDEFG?tag=example-21',
            ]),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('insights', [
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/go/B01ABCDEFG/?wk_c=1'
                && $request->header('Cookie') === ['wk_go=0123456789abcdef']
                && $request->header('Referer') === ['https://example.com/go/B01ABCDEFG']
                && $request->header('Sec-Fetch-Mode') === ['navigate']
                && $request->header('Sec-Fetch-Dest') === ['document']
                && $request->header('Sec-Fetch-Site') === ['same-origin'];
        });

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'amazon.fr'));
    }

    public function test_browser_proof_then_204_is_indeterminate(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/B01ABCDEFG' => Http::response($this->browserProofHtml(), 200),
            'https://example.com/go/B01ABCDEFG/?wk_c=1' => Http::response('', 204),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('insights', [
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
        ]);
    }

    public function test_browser_proof_targeting_another_host_is_not_followed(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/B01ABCDEFG' => Http::response(
                $this->browserProofHtml('https:\/\/other.example\/go\/B01ABCDEFG\/?wk_c=1'),
                200,
            ),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('insights', [
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
        ]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.example'));
    }

    public function test_browser_proof_then_non_proof_200_is_broken(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/B01ABCDEFG' => Http::response($this->browserProofHtml(), 200),
            'https://example.com/go/B01ABCDEFG/?wk_c=1' => Http::response('<html>Not a proof</html>', 200),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame('no_redirect_issued', $insight->payload['samples'][0]['reason']);
    }

    public function test_second_browser_proof_is_indeterminate_and_not_replayed(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/B01ABCDEFG' => Http::response($this->browserProofHtml(), 200),
            'https://example.com/go/B01ABCDEFG/?wk_c=1' => Http::response(
                $this->browserProofHtml('\/go\/B01ABCDEFG\/?wk_c=2'),
                200,
            ),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('insights', [
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
        ]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'wk_c=2'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. A redirect that lands somewhere other than the merchant is broken.
    //    This is the geotargeting-style failure: the hop happens, but it does
    //    not go to Amazon, so no commission is ever attributed.
    // ──────────────────────────────────────────────────────────────────────

    public function test_redirect_to_non_merchant_is_flagged(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/*' => Http::response('', 302, [
                'Location' => 'https://example.com/search?q=product',
            ]),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->first();

        $this->assertSame(
            'redirects_to_non_merchant',
            $insight->payload['samples'][0]['reason'],
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. A 200 means the rewrite never fired — the CMS swallowed it.
    // ──────────────────────────────────────────────────────────────────────

    public function test_redirect_returning_200_is_flagged_as_no_redirect(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/*' => Http::response('<html>404 page</html>', 200),
        ]);

        $this->makeService()->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame('no_redirect_issued', $insight->payload['samples'][0]['reason']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. A minority of dead ASINs is a WARNING, not a systemic break.
    // ──────────────────────────────────────────────────────────────────────

    public function test_partial_failure_is_warning_not_critical(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/AAAAAAAAAA', '/go/BBBBBBBBBB', '/go/CCCCCCCCCC'),
                200,
            ),
            'https://example.com/go/AAAAAAAAAA' => Http::response('Not Found', 404),
            'https://example.com/go/BBBBBBBBBB' => Http::response('', 302, [
                'Location' => 'https://www.amazon.fr/dp/BBBBBBBBBB?tag=example-21',
            ]),
            'https://example.com/go/CCCCCCCCCC' => Http::response('', 302, [
                'Location' => 'https://www.amazon.fr/dp/CCCCCCCCCC?tag=example-21',
            ]),
        ]);

        $this->makeService()->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->first();

        $this->assertNotNull($insight);
        // 1 of 3 = 0.33, below the 0.5 systemic threshold.
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
        $this->assertSame(3, $insight->payload['tested']);
        $this->assertSame(1, $insight->payload['broken']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Sites with no declared merchant are skipped entirely.
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_without_merchants_is_skipped(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team, merchants: null);

        Http::fake();

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        // No outbound request at all: the guard short-circuits before any fetch.
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. Incident lifecycle: refresh in place, preserve notification/snooze
    //    state, acknowledge confirmed repairs, and reopen as a new incident.
    // ──────────────────────────────────────────────────────────────────────

    public function test_repeated_failure_refreshes_the_same_insight_in_place(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);
        $run = 1;

        Http::fake(function ($request) use (&$run) {
            if ($request->url() === 'https://example.com/review') {
                $links = $run === 1
                    ? ['/go/AAAAAAAAAA']
                    : ['/go/AAAAAAAAAA', '/go/BBBBBBBBBB', '/go/CCCCCCCCCC'];

                return Http::response($this->pageHtmlWith(...$links), 200);
            }

            if (str_contains($request->url(), '/go/AAAAAAAAAA')) {
                return Http::response('Not Found', 404);
            }

            return Http::response('', 302, [
                'Location' => 'https://www.amazon.fr/dp/healthy',
            ]);
        });

        $this->assertSame(1, $this->makeService()->detectForMonitor($monitor));

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::AFFILIATE_REDIRECT_BROKEN->value)
            ->firstOrFail();

        $insight->update(['notified_at' => now()->subMinute()]);
        $insight->refresh();

        $id = $insight->id;
        $detectedAt = $insight->detected_at->copy();
        $notifiedAt = $insight->notified_at->copy();
        $run = 2;

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));

        $insight->refresh();

        $this->assertSame($id, $insight->id);
        $this->assertTrue($detectedAt->equalTo($insight->detected_at));
        $this->assertTrue($notifiedAt->equalTo($insight->notified_at));
        $this->assertSame(
            'Affiliate redirects failing: 1 of 3 tested links are broken',
            $insight->title,
        );
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
        $this->assertSame(3, $insight->payload['tested']);
        $this->assertSame(1, $insight->payload['broken']);
        $this->assertSame(1, Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->whereNull('acknowledged_at')
            ->count());
    }

    public function test_repeated_failure_preserves_snooze(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/*' => Http::response('Not Found', 404),
        ]);

        $this->makeService()->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->firstOrFail();
        $insight->snooze(now()->addHour());
        $insight->refresh();
        $snoozedUntil = $insight->snoozed_until->copy();

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));

        $this->assertTrue($snoozedUntil->equalTo($insight->fresh()->snoozed_until));
    }

    public function test_confirmed_repair_acknowledges_then_a_new_failure_creates_a_new_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);
        $status = 404;

        Http::fake(function ($request) use (&$status) {
            if ($request->url() === 'https://example.com/review') {
                return Http::response($this->pageHtmlWith('/go/B01ABCDEFG'), 200);
            }

            if ($status === 404) {
                return Http::response('Not Found', 404);
            }

            return Http::response('', 301, [
                'Location' => 'https://www.amazon.fr/dp/B01ABCDEFG?tag=example-21',
            ]);
        });

        $this->assertSame(1, $this->makeService()->detectForMonitor($monitor));

        $first = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->firstOrFail();
        $status = 301;

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
        $this->assertNotNull($first->fresh()->acknowledged_at);
        $status = 404;

        $this->assertSame(1, $this->makeService()->detectForMonitor($monitor));

        $second = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->whereNull('acknowledged_at')
            ->firstOrFail();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->count());
    }

    public function test_indeterminate_run_leaves_open_insight_unchanged(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);
        $status = 404;

        Http::fake(function ($request) use (&$status) {
            if ($request->url() === 'https://example.com/review') {
                return Http::response($this->pageHtmlWith('/go/B01ABCDEFG'), 200);
            }

            return Http::response($status === 404 ? 'Not Found' : '', $status);
        });

        $this->makeService()->detectForMonitor($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->firstOrFail();
        $updatedAt = $insight->updated_at->copy();
        $status = 429;

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));

        $insight->refresh();
        $this->assertNull($insight->acknowledged_at);
        $this->assertTrue($updatedAt->equalTo($insight->updated_at));
    }

    public function test_broken_sample_below_minimum_does_not_create_insight(): void
    {
        config()->set('monitoring.affiliate_redirects.min_sample', 3);

        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG', '/go/B02HIJKLMN'),
                200,
            ),
            'https://example.com/go/*' => Http::response('Not Found', 404),
        ]);

        $this->assertSame(0, $this->makeService()->detectForMonitor($monitor));
        $this->assertDatabaseMissing('insights', [
            'monitor_id' => $monitor->id,
            'type' => InsightType::AFFILIATE_REDIRECT_BROKEN->value,
        ]);
    }

    public function test_configured_monitor_token_is_sent_on_redirect_probes(): void
    {
        config()->set('monitoring.affiliate_redirects.monitor_token', 'monitor-secret');

        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG'),
                200,
            ),
            'https://example.com/go/*' => Http::response('', 302, [
                'Location' => 'https://www.amazon.fr/dp/B01ABCDEFG',
            ]),
        ]);

        $this->makeService()->detectForMonitor($monitor);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/go/')
            && $request->header('X-Wk-Monitor') === ['monitor-secret']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. The URL is probed exactly as authored, query string included.
    //    A rewrite broke in production on precisely the suffix a plugin
    //    appended, so a "cleaned" URL would test something nobody clicks.
    // ──────────────────────────────────────────────────────────────────────

    public function test_probes_the_url_verbatim_including_query_string(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('/go/B01ABCDEFG?keywords=cordless+drill'),
                200,
            ),
            'https://example.com/go/*' => Http::response('Not Found', 404),
        ]);

        $this->makeService()->detectForMonitor($monitor);

        Http::assertSent(fn ($request) => $request->url()
            === 'https://example.com/go/B01ABCDEFG?keywords=cordless+drill');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. External /go/ links belong to someone else and must be ignored.
    // ──────────────────────────────────────────────────────────────────────

    public function test_external_redirect_links_are_ignored(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeAffiliateMonitor($team);

        Http::fake([
            'https://example.com/review' => Http::response(
                $this->pageHtmlWith('https://other-site.test/go/B01ABCDEFG'),
                200,
            ),
        ]);

        $count = $this->makeService()->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other-site.test'));
    }
}
