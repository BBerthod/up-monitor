<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\NullProvider;
use Tests\TestCase;

/**
 * Unit tests for NullProvider.
 *
 * NullProvider is the deterministic AI fallback — it must never throw and must
 * always return a non-empty string regardless of the $facts shape.
 */
class NullProviderTest extends TestCase
{
    private NullProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullProvider;
    }

    public function test_is_available_returns_true(): void
    {
        $this->assertTrue($this->provider->isAvailable());
    }

    public function test_narrate_returns_non_empty_string_with_empty_facts(): void
    {
        $result = $this->provider->narrate('system prompt', []);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_narrate_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();

        try {
            $this->provider->narrate('', []);
            $this->provider->narrate('prompt', ['garbage' => null]);
            $this->provider->narrate('prompt', [[]]);
        } catch (\Throwable $e) {
            $this->fail('NullProvider::narrate() must never throw, but got: '.$e->getMessage());
        }
    }

    public function test_narrate_extracts_what_changed_titles_into_output(): void
    {
        $facts = [
            'what_changed' => [
                ['title' => 'Keyword Alpha'],
                ['title' => 'Keyword Beta'],
            ],
        ];

        $result = $this->provider->narrate('prompt', $facts);

        $this->assertStringContainsString('Keyword Alpha', $result);
        $this->assertStringContainsString('Keyword Beta', $result);
    }

    public function test_narrate_extracts_top_opportunities_into_output(): void
    {
        $facts = [
            'top_opportunities' => [
                ['title' => 'Opportunity X'],
                ['title' => 'Opportunity Y'],
            ],
        ];

        $result = $this->provider->narrate('prompt', $facts);

        $this->assertStringContainsString('Opportunity X', $result);
        $this->assertStringContainsString('Opportunity Y', $result);
    }

    public function test_narrate_caps_output_at_five_bullets(): void
    {
        $facts = [
            'what_changed' => array_map(
                fn ($i) => ['title' => "Item {$i}"],
                range(1, 10)
            ),
        ];

        $result = $this->provider->narrate('prompt', $facts);

        // The output must contain the first items but is capped at 5 bullets.
        // Count lines starting with "- ".
        $bullets = array_filter(
            explode("\n", $result),
            fn ($line) => str_starts_with(trim($line), '- ')
        );

        $this->assertLessThanOrEqual(5, count($bullets));
    }
}
