<?php

namespace App\Services\Ai;

use App\Support\CircuitBreaker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class GeminiProvider implements AiProvider
{
    private readonly string $apiKey;

    private readonly string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.ai.api_key', '');
        $this->model = (string) config('services.ai.model', 'gemini-2.5-flash');
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Generate a narrative from pre-computed facts using the Gemini API.
     *
     * The system prompt instructs the model not to invent any numbers — it may
     * only narrate the facts supplied in the $facts array.
     *
     * @throws RuntimeException if the provider is not configured, the circuit is
     *                          open, or the API returns an error.
     */
    public function narrate(string $systemPrompt, array $facts): string
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('GeminiProvider: API key is not configured.');
        }

        if (CircuitBreaker::isOpen('gemini')) {
            throw new RuntimeException('GeminiProvider: circuit breaker is open.');
        }

        // Pass the API key as a header rather than a query param so it never
        // appears in the request URL — which could otherwise leak into an
        // exception message or log line on failure.
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $this->model,
        );

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'parts' => [
                        ['text' => "Facts (JSON):\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 800,
            ],
        ];

        try {
            $response = Http::timeout(30)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->retry(2, 500)
                ->post($url, $payload);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "GeminiProvider: API returned HTTP {$response->status()}."
                );
            }

            $text = $response->json('candidates.0.content.parts.0.text');

            if (empty($text)) {
                throw new RuntimeException(
                    'GeminiProvider: API response contained no text candidate.'
                );
            }

            CircuitBreaker::recordSuccess('gemini');

            Log::info('GeminiProvider: narration generated.', [
                'model' => $this->model,
                'response_length' => mb_strlen($text),
            ]);

            return trim($text);

        } catch (Throwable $e) {
            CircuitBreaker::recordFailure('gemini');

            Log::warning('GeminiProvider: API call failed.', [
                'model' => $this->model,
                'error' => $e->getMessage(),
                // Deliberately omit full response body to avoid leaking API key
                // reflections or internal error details into logs.
            ]);

            throw new RuntimeException(
                'GeminiProvider: narration failed — '.$e->getMessage(),
                previous: $e,
            );
        }
    }
}
