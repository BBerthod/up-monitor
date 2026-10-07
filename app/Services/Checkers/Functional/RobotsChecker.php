<?php

namespace App\Services\Checkers\Functional;

use App\DTOs\FunctionalResult;
use App\Models\FunctionalCheck;
use Illuminate\Support\Facades\Http;

class RobotsChecker
{
    public function check(FunctionalCheck $check): FunctionalResult
    {
        $startTime = microtime(true);
        $url = $check->resolveUrl();

        try {
            $response = Http::timeout(15)->connectTimeout(10)
                ->withHeaders(['User-Agent' => 'Up-Monitor/1.0 (+https://github.com/BBerthod/up)'])
                ->get($url);

            $body = $response->body();
            $details = [];
            $passed = true;

            foreach ($check->rules as $rule) {
                $detail = $this->applyRule($rule, $body, $check);
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
        } catch (\Throwable $e) {
            return new FunctionalResult(
                passed: false,
                durationMs: (int) ((microtime(true) - $startTime) * 1000),
                details: [],
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function applyRule(array $rule, string $body, FunctionalCheck $check): array
    {
        return match ($rule['type']) {
            'no_disallow_all' => [
                'rule' => 'no_disallow_all',
                'passed' => ! $this->hasDisallowAll($body),
                'message' => $this->hasDisallowAll($body)
                    ? 'WARNING: Disallow: / found for User-agent: *'
                    : 'No global Disallow: / detected',
            ],
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
            'track_changes' => $this->trackChanges($body, $check),
            default => [
                'rule' => $rule['type'],
                'passed' => false,
                'message' => "Unknown rule type: {$rule['type']}",
            ],
        };
    }

    private function hasDisallowAll(string $body): bool
    {
        $lines = explode("\n", $body);
        $currentAgentIsAll = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with(strtolower($line), 'user-agent:')) {
                $currentAgentIsAll = trim(substr($line, 11)) === '*';
            }

            if ($currentAgentIsAll && str_starts_with(strtolower($line), 'disallow:')) {
                if (trim(substr($line, 9)) === '/') {
                    return true;
                }
            }
        }

        return false;
    }

    private function trackChanges(string $currentBody, FunctionalCheck $check): array
    {
        $lastResult = $check->results()->latest('checked_at')->first();

        if (! $lastResult) {
            return [
                'rule' => 'track_changes',
                'passed' => true,
                'message' => 'Baseline established',
                'content' => $currentBody,
            ];
        }

        $previousContent = collect($lastResult->details)
            ->firstWhere('rule', 'track_changes')['content'] ?? null;

        $changed = $previousContent !== null && $previousContent !== $currentBody;

        return [
            'rule' => 'track_changes',
            'passed' => ! $changed,
            'message' => $changed ? 'robots.txt content has changed' : 'No changes detected',
            'content' => $currentBody,
        ];
    }
}
