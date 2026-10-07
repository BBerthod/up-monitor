<?php

namespace Tests\Unit\Support;

use App\Support\SitemapChildSelector;
use PHPUnit\Framework\TestCase;

class SitemapChildSelectorTest extends TestCase
{
    public function test_it_returns_every_page_sitemap_when_the_index_fits_the_budget(): void
    {
        $children = [
            'https://example.com/post-1.xml',
            'https://example.com/sitemap-images.xml',
            'https://example.com/post-2.xml',
        ];

        $this->assertSame([
            'https://example.com/post-1.xml',
            'https://example.com/post-2.xml',
        ], SitemapChildSelector::select($children, 200));
    }

    public function test_it_samples_the_whole_index_instead_of_its_prefix(): void
    {
        $children = array_map(
            fn (int $part): string => "https://example.com/post-{$part}.xml",
            range(1, 101),
        );

        $selected = SitemapChildSelector::select($children, 5);

        $this->assertSame([
            'https://example.com/post-1.xml',
            'https://example.com/post-26.xml',
            'https://example.com/post-51.xml',
            'https://example.com/post-76.xml',
            'https://example.com/post-101.xml',
        ], $selected);
    }

    public function test_it_deduplicates_and_honours_a_zero_budget(): void
    {
        $children = [
            'https://example.com/post.xml',
            'https://example.com/post.xml',
        ];

        $this->assertSame([], SitemapChildSelector::select($children, 0));
        $this->assertSame(['https://example.com/post.xml'], SitemapChildSelector::select($children, 10));
    }
}
