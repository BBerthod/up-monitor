<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\GeminiProvider;
use App\Support\CircuitBreaker;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for GeminiProvider.
 *
 * IMPORTANT: GeminiProvider reads 'services.ai.api_key' in its constructor.
 * Always call config() BEFORE instantiating the provider so the constructor
 * picks up the value we set for the test.
 *
 * CircuitBreaker uses the Cache facade — phpunit.xml sets CACHE_STORE=array
 * so each test starts with a fresh, empty in-memory cache. The circuit is
 * therefore always CLOSED at the start of each test.
 */
class GeminiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset circuit breaker state for 'gemini' before each test so previous
        // failure counts do not bleed across tests.
        CircuitBreaker::reset('gemini');
    }

    // ──────────────────────────────────────────────────────────────────────
    // isAvailable
    // ──────────────────────────────────────────────────────────────────────

    public function test_is_available_returns_false_without_api_key(): void
    {
        config(['services.ai.api_key' => null]);
        $provider = new GeminiProvider;

        $this->assertFalse($provider->isAvailable());
    }

    public function test_is_available_returns_false_with_empty_string_key(): void
    {
        config(['services.ai.api_key' => '']);
        $provider = new GeminiProvider;

        $this->assertFalse($provider->isAvailable());
    }

    public function test_is_available_returns_true_with_api_key(): void
    {
        config(['services.ai.api_key' => 'test-key-abc']);
        $provider = new GeminiProvider;

        $this->assertTrue($provider->isAvailable());
    }

    // ──────────────────────────────────────────────────────────────────────
    // narrate — without key
    // ──────────────────────────────────────────────────────────────────────

    public function test_narrate_throws_runtime_exception_without_api_key(): void
    {
        $this->expectException(RuntimeException::class);

        config(['services.ai.api_key' => null]);
        $provider = new GeminiProvider;
        $provider->narrate('system', ['data' => 'value']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // narrate — with key, successful API response
    // ──────────────────────────────────────────────────────────────────────

    public function test_narrate_returns_text_from_successful_api_response(): void
    {
        config(['services.ai.api_key' => 'test-key-abc']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Generated narrative from Gemini.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $provider = new GeminiProvider;
        $result = $provider->narrate('system prompt', ['key' => 'val']);

        $this->assertEquals('Generated narrative from Gemini.', $result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // narrate — API error response
    // ──────────────────────────────────────────────────────────────────────

    public function test_narrate_throws_on_api_500_response(): void
    {
        $this->expectException(RuntimeException::class);

        config(['services.ai.api_key' => 'test-key-abc']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('Internal Server Error', 500),
        ]);

        $provider = new GeminiProvider;
        $provider->narrate('system prompt', ['key' => 'val']);
    }

    public function test_narrate_throws_when_response_has_no_text_candidate(): void
    {
        $this->expectException(RuntimeException::class);

        config(['services.ai.api_key' => 'test-key-abc']);

        // Response is successful but has no candidates.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [],
            ], 200),
        ]);

        $provider = new GeminiProvider;
        $provider->narrate('system prompt', []);
    }

    // ──────────────────────────────────────────────────────────────────────
    // narrate — circuit breaker open
    // ──────────────────────────────────────────────────────────────────────

    public function test_narrate_throws_when_circuit_breaker_is_open(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/circuit breaker/i');

        config(['services.ai.api_key' => 'test-key-abc']);

        // Trip the circuit manually by recording 5 consecutive failures.
        for ($i = 0; $i < 5; $i++) {
            CircuitBreaker::recordFailure('gemini');
        }

        // Http::fake is not needed — the circuit check fires before any HTTP call.
        $provider = new GeminiProvider;
        $provider->narrate('system prompt', []);
    }
}
