<?php

namespace App\Services\Checkers\Functional;

use App\DTOs\FunctionalResult;
use App\Models\FunctionalCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ContentChecker
{
    public function check(FunctionalCheck $check): FunctionalResult
    {
        $startTime = microtime(true);
        $url = $check->resolveUrl();

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withHeaders([
                    'User-Agent' => 'Up-Monitor/1.0 (+https://github.com/BBerthod/up)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
                ->get($url);

            $body = $response->body();
            $details = [];
            $passed = true;

            foreach ($check->rules as $rule) {
                $detail = $this->applyRule($rule, $response, $body);
                $details[] = $detail;
                if (! $detail['passed']) {
                    $passed = false;
                }
            }

            return new FunctionalResult(
                passed: $passed,
                durationMs: (int) ((microtime(true) - $startTime) * 1000),
                details: $details,
            );
        } catch (ConnectionException $e) {
            return new FunctionalResult(
                passed: false,
                durationMs: (int) ((microtime(true) - $startTime) * 1000),
                details: [],
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function applyRule(array $rule, mixed $response, string $body): array
    {
        return match ($rule['type']) {
            'text_present' => [
                'rule' => 'text_present',
                'value' => $rule['value'],
                'passed' => str_contains($body, $rule['value']),
                'message' => str_contains($body, $rule['value'])
                    ? 'Text found'
                    : "Text not found: \"{$rule['value']}\"",
            ],
            'text_absent' => [
                'rule' => 'text_absent',
                'value' => $rule['value'],
                'passed' => ! str_contains($body, $rule['value']),
                'message' => ! str_contains($body, $rule['value'])
                    ? 'Text absent (OK)'
                    : "Unwanted text found: \"{$rule['value']}\"",
            ],
            'min_content_length' => [
                'rule' => 'min_content_length',
                'value' => $rule['value'],
                'passed' => strlen($body) >= (int) $rule['value'],
                'message' => strlen($body) >= (int) $rule['value']
                    ? 'Content length OK ('.strlen($body).' bytes)'
                    : 'Content too short ('.strlen($body).' bytes, min: '.$rule['value'].')',
            ],
            'status_code' => [
                'rule' => 'status_code',
                'value' => $rule['value'],
                'passed' => $response->status() === (int) $rule['value'],
                'message' => "Status {$response->status()} (expected {$rule['value']})",
            ],
            default => [
                'rule' => $rule['type'],
                'passed' => false,
                'message' => "Unknown rule type: {$rule['type']}",
            ],
        };
    }
}
