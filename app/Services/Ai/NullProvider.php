<?php

namespace App\Services\Ai;

final class NullProvider implements AiProvider
{
    /**
     * Always available — this is the deterministic fallback, no network required.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Produce a factual, template-based narrative from $facts without any AI.
     *
     * This method is intentionally defensive: it makes no assumptions about the
     * exact shape of $facts (the DigestService finalises that contract). It
     * must never throw.
     */
    public function narrate(string $systemPrompt, array $facts): string
    {
        // Attempt to extract a recognisable list of changed items.
        $bullets = $this->extractBullets($facts);

        if ($bullets === []) {
            // Minimal honest message when we cannot extract anything meaningful.
            return 'Here is your weekly portfolio summary. Detailed metrics are available in the tables below.';
        }

        $lines = ['Here is your weekly portfolio summary.', ''];

        foreach (array_slice($bullets, 0, 5) as $bullet) {
            $lines[] = '- '.$bullet;
        }

        return implode("\n", $lines);
    }

    /**
     * Walk common $facts keys looking for human-readable bullet strings.
     *
     * Tries several well-known shapes without requiring an exact structure:
     *   - $facts['what_changed']  — array of items with 'title' or 'description'
     *   - $facts['changes']       — same
     *   - $facts['top_opportunities'] — same
     * Falls back to raw scalar values at the top level.
     *
     * @return list<string>
     */
    private function extractBullets(array $facts): array
    {
        $bullets = [];

        foreach (['what_changed', 'changes', 'top_opportunities'] as $key) {
            if (! isset($facts[$key]) || ! is_array($facts[$key])) {
                continue;
            }

            foreach ($facts[$key] as $item) {
                if (! is_array($item)) {
                    if (is_string($item) && $item !== '') {
                        $bullets[] = $item;
                    }

                    continue;
                }

                // Pick the most descriptive scalar field available.
                $label = $item['title'] ?? $item['description'] ?? $item['name'] ?? $item['label'] ?? null;

                if (is_string($label) && $label !== '') {
                    $bullets[] = $label;
                }
            }
        }

        // Last resort: top-level scalar values (skip arrays, skip 'period').
        if ($bullets === []) {
            foreach ($facts as $k => $v) {
                if ($k === 'period') {
                    continue;
                }
                if (is_scalar($v) && (string) $v !== '') {
                    $bullets[] = ucfirst((string) $k).': '.(string) $v;
                }
            }
        }

        return $bullets;
    }
}
