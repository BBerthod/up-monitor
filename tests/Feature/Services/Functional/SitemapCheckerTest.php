<?php

namespace Tests\Feature\Services\Functional;

use App\Enums\FunctionalCheckType;
use App\Models\FunctionalCheck;
use App\Services\Checkers\Functional\SitemapChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for SitemapChecker::check().
 *
 * The gap it closes: a <sitemapindex> holds no <url> elements at all — only
 * <sitemap><loc> entries pointing at the real per-section sitemaps. Reading
 * it with a <url>/<loc> xpath (the only thing this checker used to try)
 * always found zero URLs, so `min_urls` failed permanently on any site using
 * an index. fr.examplestore.com and us.examplestore.com sat on two incidents open
 * since 2026-03-22 for exactly this, on a perfectly healthy, daily-refreshed
 * sitemap index.
 */
class SitemapCheckerTest extends TestCase
{
    use RefreshDatabase;

    private SitemapChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new SitemapChecker;
    }

    private function makeCheck(array $rules): FunctionalCheck
    {
        return FunctionalCheck::factory()->sitemap()->create([
            'url' => 'https://example.com/sitemap.xml',
            'type' => FunctionalCheckType::SITEMAP,
            'rules' => $rules,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Plain <urlset> — unchanged behaviour
    // ──────────────────────────────────────────────────────────────────────

    public function test_urlset_counts_its_own_urls(): void
    {
        $check = $this->makeCheck([
            ['type' => 'is_valid_xml'],
            ['type' => 'min_urls', 'value' => 2],
        ]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/a</loc></url>'
                .'<url><loc>https://example.com/b</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $result = $this->checker->check($check);

        $this->assertTrue($result->passed);

        $minUrls = collect($result->details)->firstWhere('rule', 'min_urls');
        $this->assertTrue($minUrls['passed']);
        $this->assertSame('2 URLs found (min: 2)', $minUrls['message']);

        // No child sitemap requests: the index path was never taken.
        Http::assertSentCount(1);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. <sitemapindex> — the bug: this used to always report 0 URLs
    // ──────────────────────────────────────────────────────────────────────

    public function test_sitemapindex_sums_urls_from_its_children(): void
    {
        $check = $this->makeCheck([
            ['type' => 'is_valid_xml'],
            ['type' => 'min_urls', 'value' => 3],
        ]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<sitemap><loc>https://example.com/sitemap-pages.xml</loc></sitemap>'
                .'<sitemap><loc>https://example.com/sitemap-posts.xml</loc></sitemap>'
                .'</sitemapindex>',
                200,
            ),
            'https://example.com/sitemap-pages.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/page-1</loc></url>'
                .'</urlset>',
                200,
            ),
            'https://example.com/sitemap-posts.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/post-1</loc></url>'
                .'<url><loc>https://example.com/post-2</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $result = $this->checker->check($check);

        $this->assertTrue($result->passed);

        $isValidXml = collect($result->details)->firstWhere('rule', 'is_valid_xml');
        $this->assertTrue($isValidXml['passed']);

        $minUrls = collect($result->details)->firstWhere('rule', 'min_urls');
        $this->assertTrue($minUrls['passed']);
        $this->assertSame('3 URLs found (min: 3)', $minUrls['message']);

        Http::assertSentCount(3);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. <sitemapindex> whose children are unreachable — degrade, not zero
    // ──────────────────────────────────────────────────────────────────────

    public function test_sitemapindex_falls_back_to_child_count_when_children_are_unreachable(): void
    {
        $check = $this->makeCheck([
            ['type' => 'min_urls', 'value' => 1],
        ]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<sitemap><loc>https://example.com/sitemap-pages.xml</loc></sitemap>'
                .'<sitemap><loc>https://example.com/sitemap-posts.xml</loc></sitemap>'
                .'</sitemapindex>',
                200,
            ),
            'https://example.com/sitemap-pages.xml' => Http::response('', 503),
            'https://example.com/sitemap-posts.xml' => Http::response('', 503),
        ]);

        $result = $this->checker->check($check);

        // Two unreachable-but-real child sitemaps beat the min_urls=1 floor,
        // instead of the old, permanently-zero result.
        $this->assertTrue($result->passed);

        $minUrls = collect($result->details)->firstWhere('rule', 'min_urls');
        $this->assertTrue($minUrls['passed']);
        $this->assertSame('2 URLs found (min: 1)', $minUrls['message']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Image sitemaps are not counted as pages
    // ──────────────────────────────────────────────────────────────────────

    public function test_image_sitemaps_are_excluded_from_the_index(): void
    {
        $check = $this->makeCheck([
            ['type' => 'min_urls', 'value' => 1],
        ]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<sitemap><loc>https://example.com/sitemap-pages.xml</loc></sitemap>'
                .'<sitemap><loc>https://example.com/sitemap-images.xml</loc></sitemap>'
                .'</sitemapindex>',
                200,
            ),
            'https://example.com/sitemap-pages.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/page-1</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $result = $this->checker->check($check);

        $minUrls = collect($result->details)->firstWhere('rule', 'min_urls');
        $this->assertSame('1 URLs found (min: 1)', $minUrls['message']);

        // The image sitemap was never requested at all.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sitemap-images'));
        Http::assertSentCount(2);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. An index pointing at another index
    // ──────────────────────────────────────────────────────────────────────

    public function test_nested_indexes_are_followed_to_the_urls(): void
    {
        // The shape every examplestore locale actually serves: the root lists a
        // per-locale index, which lists the numbered parts, and only those hold
        // any <url>. Stopping one level down reads zero and fails a sitemap
        // that is refreshed daily — the same false negative, one level deeper.
        $check = $this->makeCheck([
            ['type' => 'min_urls', 'value' => 3],
        ]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<sitemap><loc>https://example.com/sitemap-fr.xml</loc></sitemap>'
                .'</sitemapindex>',
                200,
            ),
            'https://example.com/sitemap-fr.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<sitemap><loc>https://example.com/sitemap-fr-1.xml</loc></sitemap>'
                .'<sitemap><loc>https://example.com/sitemap-fr-2.xml</loc></sitemap>'
                .'</sitemapindex>',
                200,
            ),
            'https://example.com/sitemap-fr-1.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/a</loc></url>'
                .'<url><loc>https://example.com/b</loc></url>'
                .'</urlset>',
                200,
            ),
            'https://example.com/sitemap-fr-2.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/c</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $result = $this->checker->check($check);

        $minUrls = collect($result->details)->firstWhere('rule', 'min_urls');
        $this->assertTrue($minUrls['passed']);
        $this->assertSame('3 URLs found (min: 3)', $minUrls['message']);
    }

    public function test_the_fetch_budget_is_shared_across_nesting_levels(): void
    {
        // Levels multiply, so a per-level cap is how a check turns into a
        // crawl. One budget for the whole descent keeps that bounded.
        config(['monitoring.sitemap_health.max_child_sitemaps' => 3]);

        $children = '';
        for ($i = 1; $i <= 5; $i++) {
            $children .= '<sitemap><loc>https://example.com/part-'.$i.'.xml</loc></sitemap>';
            Http::fake([
                'https://example.com/part-'.$i.'.xml' => Http::response(
                    '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                    .'<url><loc>https://example.com/p'.$i.'</loc></url>'
                    .'</urlset>',
                    200,
                ),
            ]);
        }

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .$children
                .'</sitemapindex>',
                200,
            ),
            '*' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/x</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $this->checker->check($this->makeCheck([['type' => 'min_urls', 'value' => 1]]));

        // The index itself plus three children, never all five.
        Http::assertSentCount(4);
    }
}
