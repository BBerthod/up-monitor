<?php

namespace App\Support;

/**
 * Select child sitemap URLs without biasing every audit toward the first files.
 *
 * Most generators order sitemap parts chronologically. Taking the first N therefore
 * inspects only the oldest (or newest) slice forever. When an index exceeds the
 * safety budget, an evenly-spaced selection covers the whole index, including both
 * edges, while keeping outbound requests bounded.
 */
final class SitemapChildSelector
{
    /**
     * @param  iterable<mixed>  $children
     * @return list<string>
     */
    public static function select(iterable $children, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $urls = [];

        foreach ($children as $child) {
            $url = trim((string) $child);
            $lower = strtolower($url);

            if ($url === '' || str_contains($lower, 'sitemap-image') || str_contains($lower, '-images-')) {
                continue;
            }

            $urls[$url] = $url;
        }

        $urls = array_values($urls);
        $count = count($urls);

        if ($count <= $limit) {
            return $urls;
        }

        if ($limit === 1) {
            return [$urls[intdiv($count - 1, 2)]];
        }

        $selected = [];

        for ($position = 0; $position < $limit; $position++) {
            $index = (int) round($position * ($count - 1) / ($limit - 1));
            $selected[$index] = $urls[$index];
        }

        return array_values($selected);
    }
}
