<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\PageMetric;
use App\Models\Site;
use App\Models\Team;
use App\Services\ZombiePageService;
use App\Support\UrlSafetyValidator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for ZombiePageService::detectForSite().
 *
 * The gap it closes: ContentDecayService skips any page whose baseline is zero,
 * so a page that NEVER had traffic is invisible to it by construction. The
 * pages that get attention are the ones that used to work; the ones that never
 * worked at all are the ones nobody hears about.
 */
class ZombiePageServiceTest extends TestCase
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

    private function makeSite(): Site
    {
        return Site::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'sitemap_path' => '/sitemap.xml',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, string>  $urls
     */
    private function sitemap(array $urls): string
    {
        $body = '';

        foreach ($urls as $url) {
            $body .= '<url><loc>'.$url.'</loc></url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$body.'</urlset>';
    }

    /**
     * Record that GSC has seen this page, and seed enough history to judge.
     */
    private function seen(string $page, int $daysAgo = 5): void
    {
        PageMetric::factory()->create([
            'site' => 'example.com',
            'page' => $page,
            'clicks' => 10,
            'impressions' => 500,
            'ctr' => 2.0,
            'position' => 8.0,
            'captured_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * Seed a snapshot old enough to clear the min_history_days gate.
     */
    private function seedHistory(): void
    {
        $this->seen('https://example.com/anchor', daysAgo: 45);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Published pages with no visibility are found
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_pages_absent_from_search_data(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();

        // Two of eight pages have visibility; six never appeared.
        $this->seen('https://example.com/a');
        $this->seen('https://example.com/b');

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap([
                'https://example.com/a',
                'https://example.com/b',
                'https://example.com/z1',
                'https://example.com/z2',
                'https://example.com/z3',
                'https://example.com/z4',
                'https://example.com/z5',
                'https://example.com/z6',
            ]), 200),
        ]);

        $this->assertSame(1, (new ZombiePageService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::ZOMBIE_PAGE->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame(6, $insight->payload['zombie_count']);
        $this->assertSame(8, $insight->payload['published_count']);
        // Wasted effort, not lost revenue — never CRITICAL.
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. A fully visible site is silent
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_with_full_visibility_creates_no_insight(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();

        $this->seen('https://example.com/a');
        $this->seen('https://example.com/b');

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap([
                'https://example.com/a',
                'https://example.com/b',
            ]), 200),
        ]);

        $this->assertSame(0, (new ZombiePageService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Not enough history — a new site is not a broken one
    // ──────────────────────────────────────────────────────────────────────

    public function test_recent_tracking_history_produces_no_verdict(): void
    {
        $site = $this->makeSite();

        // Only a few days of data: a page that has not ranked yet is
        // indistinguishable from one that never will.
        $this->seen('https://example.com/a', daysAgo: 3);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap([
                'https://example.com/a',
                'https://example.com/z1',
                'https://example.com/z2',
                'https://example.com/z3',
                'https://example.com/z4',
                'https://example.com/z5',
            ]), 200),
        ]);

        $this->assertSame(0, (new ZombiePageService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Both floors must be cleared
    // ──────────────────────────────────────────────────────────────────────

    public function test_small_absolute_count_is_ignored(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();

        // 2 of 4 is a high ratio, but two pages is not a finding.
        $this->seen('https://example.com/a');
        $this->seen('https://example.com/b');

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap([
                'https://example.com/a',
                'https://example.com/b',
                'https://example.com/z1',
                'https://example.com/z2',
            ]), 200),
        ]);

        $this->assertSame(0, (new ZombiePageService)->detectForSite($site));
    }

    public function test_small_ratio_on_a_large_site_is_ignored(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();

        $urls = [];

        for ($i = 1; $i <= 100; $i++) {
            $url = "https://example.com/page-{$i}";
            $urls[] = $url;

            // 95 of 100 visible: five stragglers are a rounding error, not a
            // problem with the site.
            if ($i <= 95) {
                $this->seen($url);
            }
        }

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap($urls), 200),
        ]);

        $this->assertSame(0, (new ZombiePageService)->detectForSite($site));
    }

    public function test_half_visible_site_below_default_ratio_is_ignored(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();

        // 6 of 20 (30 %) zombies: above the old 10 % floor, below the 50 % one.
        $urls = [];

        for ($i = 1; $i <= 20; $i++) {
            $urls[] = "https://example.com/page-{$i}";

            if ($i <= 14) {
                $this->seen("https://example.com/page-{$i}");
            }
        }

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap($urls), 200),
        ]);

        $this->assertSame(0, (new ZombiePageService)->detectForSite($site));
    }

    public function test_large_programmatic_site_is_ignored(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();

        // 1,200 published pages, none visible: de-indexation is a known state there.
        $urls = [];

        for ($i = 1; $i <= 1200; $i++) {
            $urls[] = "https://example.com/p-{$i}";
        }

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap($urls), 200),
        ]);

        $this->assertSame(0, (new ZombiePageService)->detectForSite($site));
    }

    public function test_site_at_the_published_cap_is_still_reported(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();

        config(['monitoring.zombie_pages.max_published' => 10]);

        $urls = [];

        for ($i = 1; $i <= 10; $i++) {
            $urls[] = "https://example.com/p-{$i}";
        }

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap($urls), 200),
        ]);

        $this->assertSame(1, (new ZombiePageService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Cosmetic URL differences must not create false zombies
    // ──────────────────────────────────────────────────────────────────────

    public function test_trailing_slash_and_www_differences_are_tolerated(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();

        // GSC and sitemaps routinely disagree on these without meaning a
        // different page; treating them as distinct would report every page on
        // the site as a zombie.
        $this->seen('https://www.example.com/a/');
        $this->seen('https://example.com/b');

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap([
                'https://example.com/a',
                'https://www.example.com/b/',
            ]), 200),
        ]);

        $this->assertSame(0, (new ZombiePageService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. A page seen earlier in the window is decay's business, not ours
    // ──────────────────────────────────────────────────────────────────────

    public function test_page_seen_earlier_in_the_window_is_not_a_zombie(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();

        // Ranked three weeks ago and dropped out since: that is content decay,
        // a different signal with a different fix.
        $this->seen('https://example.com/a', daysAgo: 20);

        $urls = ['https://example.com/a'];

        for ($i = 1; $i <= 5; $i++) {
            $urls[] = "https://example.com/z{$i}";
        }

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap($urls), 200),
        ]);

        (new ZombiePageService)->detectForSite($site);

        $insight = Insight::withoutGlobalScopes()->first();

        // Five zombies, not six — /a is excluded.
        $this->assertSame(5, $insight->payload['zombie_count']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. Sites without a sitemap are out of scope
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_without_sitemap_is_skipped(): void
    {
        $site = Site::factory()->create([
            'primary_domain' => 'example.com',
            'sitemap_path' => null,
        ]);

        Http::fake();

        $this->assertSame(0, (new ZombiePageService)->detectForSite($site));
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. Image sitemaps list assets, not pages
    // ──────────────────────────────────────────────────────────────────────

    public function test_image_sitemaps_are_not_counted_as_pages(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();
        $this->seen('https://example.com/a');

        $index = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<sitemap><loc>https://example.com/sitemap-posts.xml</loc></sitemap>'
            .'<sitemap><loc>https://example.com/sitemap-images-1.xml</loc></sitemap>'
            .'</sitemapindex>';

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($index, 200),
            'https://example.com/sitemap-posts.xml' => Http::response(
                $this->sitemap(['https://example.com/a']),
                200,
            ),
            // Counting these would report every image as a page nobody searches for.
            'https://example.com/sitemap-images-1.xml' => Http::response(
                $this->sitemap(array_map(fn (int $i): string => "https://example.com/img-{$i}.jpg", range(1, 50))),
                200,
            ),
        ]);

        $this->assertSame(0, (new ZombiePageService)->detectForSite($site));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sitemap-images-1'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. Idempotence
    // ──────────────────────────────────────────────────────────────────────

    public function test_previous_insight_is_cleared_when_resolved(): void
    {
        $site = $this->makeSite();
        $this->seedHistory();
        $this->seen('https://example.com/a');

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'type' => InsightType::ZOMBIE_PAGE->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'stale finding',
            'payload' => [],
            'impact_score' => 50,
            'detected_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemap([
                'https://example.com/a',
            ]), 200),
        ]);

        (new ZombiePageService)->detectForSite($site);

        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::ZOMBIE_PAGE->value,
        ]);
    }

    public function test_detected_at_is_preserved_across_runs_while_payload_and_impact_are_recomputed(): void
    {
        $site = $this->makeSite();

        Carbon::setTestNow('2026-08-01 08:00:00');

        $this->seedHistory();
        $this->seen('https://example.com/a');
        $this->seen('https://example.com/b');

        Http::fake([
            'https://example.com/sitemap.xml' => Http::sequence()
                ->push($this->sitemap([
                    'https://example.com/a',
                    'https://example.com/b',
                    'https://example.com/z1',
                    'https://example.com/z2',
                    'https://example.com/z3',
                    'https://example.com/z4',
                    'https://example.com/z5',
                    'https://example.com/z6',
                ]), 200)
                ->push($this->sitemap([
                    'https://example.com/a',
                    'https://example.com/b',
                    'https://example.com/z1',
                    'https://example.com/z2',
                    'https://example.com/z3',
                    'https://example.com/z4',
                    'https://example.com/z5',
                    'https://example.com/z6',
                    'https://example.com/z7',
                    'https://example.com/z8',
                    'https://example.com/z9',
                    'https://example.com/z10',
                ]), 200),
        ]);

        (new ZombiePageService)->detectForSite($site);

        $firstInsight = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::ZOMBIE_PAGE->value)
            ->whereNull('acknowledged_at')
            ->firstOrFail();

        $this->assertSame(6, $firstInsight->payload['zombie_count']);
        $this->assertEquals('75.00', $firstInsight->impact_score);
        $firstDetectedAt = $firstInsight->detected_at;

        Carbon::setTestNow('2026-08-22 08:00:00');

        (new ZombiePageService)->detectForSite($site);

        $refreshed = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::ZOMBIE_PAGE->value)
            ->whereNull('acknowledged_at')
            ->get();

        $this->assertCount(1, $refreshed);

        $refreshedInsight = $refreshed->first();

        $this->assertTrue($refreshedInsight->detected_at->equalTo($firstDetectedAt));
        $this->assertSame(10, $refreshedInsight->payload['zombie_count']);
        $this->assertSame(12, $refreshedInsight->payload['published_count']);
        $this->assertEquals('83.33', $refreshedInsight->impact_score);

        Carbon::setTestNow();
    }
}
