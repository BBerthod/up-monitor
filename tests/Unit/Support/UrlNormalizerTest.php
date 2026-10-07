<?php

namespace Tests\Unit\Support;

use App\Support\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlNormalizerTest extends TestCase
{
    #[DataProvider('equivalentUrlProvider')]
    public function test_equivalent_urls_normalize_to_the_same_value(string $a, string $b): void
    {
        $this->assertSame(UrlNormalizer::normalize($a), UrlNormalizer::normalize($b));
    }

    public static function equivalentUrlProvider(): array
    {
        return [
            'trailing slash' => ['https://example.com', 'https://example.com/'],
            'host casing' => ['https://Example.com', 'https://example.com'],
            'scheme casing' => ['HTTPS://example.com', 'https://example.com'],
            'combined' => ['HTTPS://Example.com/', 'https://example.com'],
        ];
    }

    /**
     * Scheme (http vs https) and a leading "www." are deliberately NOT
     * normalised: this project runs separate, independently-checked
     * monitors for each variant on purpose (see HealthDropDeduplicationTest
     * and DispatchInsightsTest) — the SEO/insight layer collapses them per
     * site downstream, uptime-checking does not.
     */
    #[DataProvider('distinctUrlProvider')]
    public function test_scheme_and_www_variants_remain_distinct(string $a, string $b): void
    {
        $this->assertNotSame(UrlNormalizer::normalize($a), UrlNormalizer::normalize($b));
    }

    public static function distinctUrlProvider(): array
    {
        return [
            'http vs https' => ['http://example.com', 'https://example.com'],
            'www vs apex' => ['https://www.example.com', 'https://example.com'],
        ];
    }

    public function test_distinct_paths_do_not_collide(): void
    {
        $this->assertNotSame(
            UrlNormalizer::normalize('https://example.com/pricing'),
            UrlNormalizer::normalize('https://example.com/about')
        );
    }

    public function test_query_string_is_preserved(): void
    {
        $this->assertNotSame(
            UrlNormalizer::normalize('https://example.com/search?q=a'),
            UrlNormalizer::normalize('https://example.com/search?q=b')
        );
    }

    public function test_bare_host_used_by_ping_and_port_monitors_is_lowercased(): void
    {
        $this->assertSame('example.com', UrlNormalizer::normalize('Example.com'));
    }

    public function test_bare_value_without_scheme_is_lowercased(): void
    {
        $this->assertSame('not a url', UrlNormalizer::normalize('Not A URL'));
    }

    public function test_url_with_no_host_falls_back_to_lowercase_trim(): void
    {
        $this->assertSame('https:///no-host', UrlNormalizer::normalize('HTTPS:///no-host'));
    }

    public function test_empty_string_normalizes_to_empty_string(): void
    {
        $this->assertSame('', UrlNormalizer::normalize('   '));
    }
}
