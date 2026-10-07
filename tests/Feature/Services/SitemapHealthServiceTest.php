<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Services\SitemapHealthService;
use App\Support\UrlSafetyValidator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for SitemapHealthService::detectForSite().
 *
 * Reproduces the two sitemap failures this fleet actually suffered:
 * a file frozen at a years-old lastmod, and one whose URLs were overwhelmingly
 * redirects after a slug migration.
 *
 * Http::fake() note: the service probes URLs with withoutRedirecting(), so a
 * faked 301 is observed as a 301 rather than resolved.
 */
class SitemapHealthServiceTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        UrlSafetyValidator::setResolver(null);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function makeSite(?Team $team = null): Site
    {
        return Site::factory()->create([
            'team_id' => ($team ?? Team::factory()->create())->id,
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'sitemap_path' => '/sitemap.xml',
            'is_active' => true,
        ]);
    }

    /**
     * Build a leaf sitemap document.
     *
     * @param  array<int, array{loc: string, lastmod?: string}>  $entries
     */
    private function sitemapXml(array $entries): string
    {
        $body = '';

        foreach ($entries as $entry) {
            $body .= '<url><loc>'.$entry['loc'].'</loc>';

            if (isset($entry['lastmod'])) {
                $body .= '<lastmod>'.$entry['lastmod'].'</lastmod>';
            }

            $body .= '</url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$body
            .'</urlset>';
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. The migration failure: most URLs redirect → flagged
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_sitemap_dominated_by_redirects(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapXml([
                ['loc' => 'https://example.com/a', 'lastmod' => now()->subDays(2)->toDateString()],
                ['loc' => 'https://example.com/b', 'lastmod' => now()->subDays(3)->toDateString()],
                ['loc' => 'https://example.com/c', 'lastmod' => now()->subDays(4)->toDateString()],
            ]), 200),
            'https://example.com/a' => Http::response('', 301, ['Location' => 'https://example.com/new-a']),
            'https://example.com/b' => Http::response('', 301, ['Location' => 'https://example.com/new-b']),
            'https://example.com/c' => Http::response('ok', 200),
        ]);

        $count = (new SitemapHealthService)->detectForSite($site);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::SITEMAP_HEALTH->value)
            ->first();

        $this->assertNotNull($insight);
        // 2 of 3 redirect — well past the 20% tolerance.
        $this->assertEqualsWithDelta(0.667, $insight->payload['redirect_ratio'], 0.01);
        $this->assertSame(3, $insight->payload['sampled']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. The frozen-file failure: newest lastmod is years old
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_stale_lastmod(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapXml([
                ['loc' => 'https://example.com/a', 'lastmod' => '2023-09-22'],
                ['loc' => 'https://example.com/b', 'lastmod' => '2023-08-01'],
            ]), 200),
            'https://example.com/a' => Http::response('ok', 200),
            'https://example.com/b' => Http::response('ok', 200),
        ]);

        $count = (new SitemapHealthService)->detectForSite($site);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()->where('site_id', $site->id)->first();

        $this->assertNotNull($insight);
        $this->assertTrue($insight->payload['lastmod_present']);
        // An old lastmod alone is never actionable: the sampled URLs are healthy,
        // so the content may simply not have changed. Reported as INFO only.
        $this->assertEquals(InsightSeverity::INFO, $insight->severity);
        $this->assertGreaterThan(365, $insight->payload['lastmod_age_days']);
    }

    public function test_stale_lastmod_with_fully_healthy_sample_never_warns(): void
    {
        $site = $this->makeSite();

        $entries = [];
        $fakes = [];

        for ($i = 0; $i < 40; $i++) {
            $loc = "https://example.com/p/{$i}";
            $entries[] = ['loc' => $loc, 'lastmod' => now()->subDays(206)->toDateString()];
            $fakes[$loc] = Http::response('ok', 200);
        }

        $fakes['https://example.com/sitemap.xml'] = Http::response($this->sitemapXml($entries), 200);
        Http::fake($fakes);

        (new SitemapHealthService)->detectForSite($site);

        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::SITEMAP_HEALTH->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);
        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::SITEMAP_HEALTH->value,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);
    }

    public function test_quiet_site_below_default_age_threshold_creates_no_insight(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapXml([
                ['loc' => 'https://example.com/a', 'lastmod' => now()->subDays(206)->toDateString()],
            ]), 200),
            'https://example.com/a' => Http::response('ok', 200),
        ]);

        $this->assertSame(0, (new SitemapHealthService)->detectForSite($site));
    }

    public function test_mostly_redirecting_sample_still_warns_even_with_stale_lastmod(): void
    {
        $site = $this->makeSite();

        $entries = [];
        $fakes = [];

        // 49 of 50 URLs redirect, but only 40 are sampled: ratio stays ~98%.
        for ($i = 0; $i < 50; $i++) {
            $loc = "https://example.com/p/{$i}";
            $entries[] = ['loc' => $loc, 'lastmod' => now()->subDays(2)->toDateString()];
            $fakes[$loc] = $i === 0
                ? Http::response('ok', 200)
                : Http::response('', 301, ['Location' => $loc.'-new']);
        }

        $fakes['https://example.com/sitemap.xml'] = Http::response($this->sitemapXml($entries), 200);
        Http::fake($fakes);

        $this->assertSame(1, (new SitemapHealthService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()->where('site_id', $site->id)->first();

        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
        $this->assertGreaterThan(0.9, $insight->payload['redirect_ratio']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. A healthy sitemap produces nothing
    // ──────────────────────────────────────────────────────────────────────

    public function test_healthy_sitemap_creates_no_insight(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapXml([
                ['loc' => 'https://example.com/a', 'lastmod' => now()->subDays(1)->toDateString()],
                ['loc' => 'https://example.com/b', 'lastmod' => now()->subDays(5)->toDateString()],
            ]), 200),
            'https://example.com/a' => Http::response('ok', 200),
            'https://example.com/b' => Http::response('ok', 200),
        ]);

        $count = (new SitemapHealthService)->detectForSite($site);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::SITEMAP_HEALTH->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Broken entries are CRITICAL even in small numbers
    // ──────────────────────────────────────────────────────────────────────

    public function test_broken_urls_are_critical(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapXml([
                ['loc' => 'https://example.com/a', 'lastmod' => now()->subDay()->toDateString()],
                ['loc' => 'https://example.com/b', 'lastmod' => now()->subDay()->toDateString()],
            ]), 200),
            'https://example.com/a' => Http::response('ok', 200),
            'https://example.com/b' => Http::response('gone', 404),
        ]);

        $this->assertSame(1, (new SitemapHealthService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()->where('site_id', $site->id)->first();

        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertSame(1, $insight->payload['broken']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. An unreachable sitemap is a total discovery outage
    // ──────────────────────────────────────────────────────────────────────

    public function test_unreachable_sitemap_is_critical(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('Not Found', 404),
        ]);

        $this->assertSame(1, (new SitemapHealthService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()->where('site_id', $site->id)->first();

        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertSame('empty_or_unreachable', $insight->payload['reason']);
        $this->assertEquals(100, (int) $insight->impact_score);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Sitemap index files are followed into their children
    // ──────────────────────────────────────────────────────────────────────

    public function test_follows_sitemap_index(): void
    {
        $site = $this->makeSite();

        $index = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<sitemap><loc>https://example.com/sitemap-posts.xml</loc></sitemap>'
            .'</sitemapindex>';

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($index, 200),
            'https://example.com/sitemap-posts.xml' => Http::response($this->sitemapXml([
                ['loc' => 'https://example.com/post-1', 'lastmod' => '2023-01-01'],
            ]), 200),
            'https://example.com/post-1' => Http::response('ok', 200),
        ]);

        $this->assertSame(1, (new SitemapHealthService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()->where('site_id', $site->id)->first();

        // The child's single entry was collected and audited.
        $this->assertSame(1, $insight->payload['total_urls']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. A sitemap without any lastmod is legal, not a fault
    // ──────────────────────────────────────────────────────────────────────

    public function test_missing_lastmod_is_not_a_fault(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapXml([
                ['loc' => 'https://example.com/a'],
                ['loc' => 'https://example.com/b'],
            ]), 200),
            'https://example.com/a' => Http::response('ok', 200),
            'https://example.com/b' => Http::response('ok', 200),
        ]);

        $this->assertSame(0, (new SitemapHealthService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. Idempotence: a fixed sitemap clears its previous finding
    // ──────────────────────────────────────────────────────────────────────

    public function test_previous_insight_is_cleared_when_fixed(): void
    {
        $site = $this->makeSite();

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'type' => InsightType::SITEMAP_HEALTH->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'title' => 'stale finding',
            'payload' => [],
            'impact_score' => 90,
            'detected_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapXml([
                ['loc' => 'https://example.com/a', 'lastmod' => now()->subDay()->toDateString()],
            ]), 200),
            'https://example.com/a' => Http::response('ok', 200),
        ]);

        (new SitemapHealthService)->detectForSite($site);

        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::SITEMAP_HEALTH->value,
        ]);
    }

    public function test_detected_at_is_preserved_across_runs_while_severity_and_payload_are_recomputed(): void
    {
        $site = $this->makeSite();

        Carbon::setTestNow('2026-08-01 08:00:00');

        Http::fake([
            'https://example.com/sitemap.xml' => Http::sequence()
                ->push($this->sitemapXml([
                    ['loc' => 'https://example.com/a', 'lastmod' => '2025-06-01'],
                ]), 200)
                ->push($this->sitemapXml([
                    ['loc' => 'https://example.com/a', 'lastmod' => '2025-02-03'],
                ]), 200),
            'https://example.com/a' => Http::response('ok', 200),
        ]);

        (new SitemapHealthService)->detectForSite($site);

        $firstInsight = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::SITEMAP_HEALTH->value)
            ->whereNull('acknowledged_at')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::INFO, $firstInsight->severity);
        $firstAge = $firstInsight->payload['lastmod_age_days'];
        $this->assertGreaterThan(365, $firstAge);
        $firstDetectedAt = $firstInsight->detected_at;

        Carbon::setTestNow('2026-08-22 08:00:00');

        (new SitemapHealthService)->detectForSite($site);

        $refreshed = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::SITEMAP_HEALTH->value)
            ->whereNull('acknowledged_at')
            ->get();

        $this->assertCount(1, $refreshed);

        $refreshedInsight = $refreshed->first();

        $this->assertTrue($refreshedInsight->detected_at->equalTo($firstDetectedAt));
        $this->assertEquals(InsightSeverity::INFO, $refreshedInsight->severity);
        $this->assertGreaterThan($firstAge, $refreshedInsight->payload['lastmod_age_days']);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. Sites without a configured sitemap are skipped silently
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_without_sitemap_path_is_skipped(): void
    {
        $site = Site::factory()->create([
            'primary_domain' => 'example.com',
            'sitemap_path' => null,
        ]);

        Http::fake();

        $this->assertSame(0, (new SitemapHealthService)->detectForSite($site));
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. Memory regression: a huge sitemap tree must not accumulate every
    //     entry in memory (prod OOM on 2026-09-15: ~577k URLs, 407MB of XML).
    // ──────────────────────────────────────────────────────────────────────

    public function test_large_sitemap_tree_does_not_blow_up_memory(): void
    {
        $site = $this->makeSite();

        $childCount = 20;
        $urlsPerChild = 10_000;
        $totalUrls = $childCount * $urlsPerChild;

        $indexBody = '';
        $fakes = [];

        // A stale lastmod (well past the 365-day stale threshold) so the
        // run is guaranteed to raise an insight, letting the test also assert
        // on total_urls without depending on the broken/redirect path.
        $lastmod = now()->subDays(400)->toDateString();

        for ($child = 0; $child < $childCount; $child++) {
            $childUrl = "https://example.com/sitemap-{$child}.xml";
            $indexBody .= "<sitemap><loc>{$childUrl}</loc></sitemap>";

            $urlsBody = '';

            for ($i = 0; $i < $urlsPerChild; $i++) {
                $urlsBody .= "<url><loc>https://example.com/p/{$child}/{$i}</loc><lastmod>{$lastmod}</lastmod></url>";
            }

            $fakes[$childUrl] = Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .$urlsBody
                .'</urlset>',
                200,
            );
        }

        $fakes['https://example.com/sitemap.xml'] = Http::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$indexBody
            .'</sitemapindex>',
            200,
        );

        // Every sampled URL answers 200, so the only reason an insight fires
        // is the stale lastmod — keeping the assertions about total_urls
        // independent from the redirect/broken sampling path.
        $fakes['*'] = Http::response('ok', 200);

        Http::fake($fakes);

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $before = memory_get_usage();

        $count = (new SitemapHealthService)->detectForSite($site);

        $peak = memory_get_peak_usage();
        $increaseMb = ($peak - $before) / 1024 / 1024;

        // Old (pre-fix) code accumulating 200k {loc, lastmod} pairs via
        // array_merge() on every recursive step measured well above this
        // ceiling on this same fixture; streaming + reservoir sampling keeps
        // the increase bounded regardless of total_urls.
        $this->assertLessThanOrEqual(48, $increaseMb, sprintf('Peak memory increase was %.2fMB', $increaseMb));

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::SITEMAP_HEALTH->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame($totalUrls, $insight->payload['total_urls']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 11. Reservoir sampling: the probed sample size is bounded by
    //     sample_size, and never larger than the actual number of URLs.
    // ──────────────────────────────────────────────────────────────────────

    public function test_reservoir_sample_is_bounded_and_distinct(): void
    {
        $site = $this->makeSite();

        config(['monitoring.sitemap_health.sample_size' => 10]);

        $urlsBody = '';

        for ($i = 0; $i < 500; $i++) {
            $urlsBody .= "<url><loc>https://example.com/p/{$i}</loc></url>";
        }

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .$urlsBody
                .'</urlset>',
                200,
            ),
            '*' => Http::response('ok', 200),
        ]);

        (new SitemapHealthService)->detectForSite($site);

        // A healthy sitemap (no lastmod, all 200s) creates no insight, so read
        // the sample size back from the request log instead: every probed URL
        // is a HEAD request against one of the 500 sitemap entries.
        $probedUrls = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'HEAD')
            ->map(fn ($pair) => $pair[0]->url())
            ->unique();

        $this->assertCount(10, $probedUrls);

        foreach ($probedUrls as $url) {
            $this->assertStringStartsWith('https://example.com/p/', $url);
        }
    }

    public function test_reservoir_sample_keeps_every_url_when_fewer_than_sample_size(): void
    {
        $site = $this->makeSite();

        config(['monitoring.sitemap_health.sample_size' => 40]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapXml([
                ['loc' => 'https://example.com/a'],
                ['loc' => 'https://example.com/b'],
                ['loc' => 'https://example.com/c'],
            ]), 200),
            '*' => Http::response('ok', 200),
        ]);

        (new SitemapHealthService)->detectForSite($site);

        $probedUrls = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'HEAD')
            ->map(fn ($pair) => $pair[0]->url())
            ->unique();

        $this->assertCount(3, $probedUrls);
    }
}
