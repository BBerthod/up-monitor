<?php

namespace App\Services\Checkers\Functional;

use App\DTOs\FunctionalResult;
use App\Models\FunctionalCheck;
use Illuminate\Support\Facades\Http;

class RedirectChecker
{
    public function check(FunctionalCheck $check): FunctionalResult
    {
        $startTime = microtime(true);
        $url = $check->resolveUrl();

        try {
            $chain = $this->followRedirects($url);
            $details = [];
            $passed = true;

            foreach ($check->rules as $rule) {
                $detail = $this->applyRule($rule, $url, $chain);
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

    private function followRedirects(string $url, int $maxHops = 10): array
    {
        $chain = [];
        $current = $url;

        for ($i = 0; $i < $maxHops; $i++) {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'Up-Monitor/1.0 (+https://github.com/BBerthod/up)'])
                ->get($current);

            $chain[] = ['url' => $current, 'status' => $response->status()];

            if (! in_array($response->status(), [301, 302, 303, 307, 308])) {
                break;
            }

            $location = $response->header('Location');
            if (! $location) {
                break;
            }

            if (! str_starts_with($location, 'http')) {
                $parsed = parse_url($current);
                $location = $parsed['scheme'].'://'.$parsed['host'].$location;
            }

            foreach ($chain as $visited) {
                if ($visited['url'] === $location) {
                    $chain[] = ['url' => $location, 'status' => 'loop'];

                    return $chain;
                }
            }

            if ($this->isPrivateUrl($location)) {
                $chain[] = ['url' => $location, 'status' => 'ssrf_blocked'];

                return $chain;
            }

            $current = $location;
        }

        return $chain;
    }

    private function applyRule(array $rule, string $originalUrl, array $chain): array
    {
        $finalUrl = $chain[count($chain) - 1]['url'] ?? '';

        return match ($rule['type']) {
            'redirects_to' => [
                'rule' => 'redirects_to',
                'value' => $rule['value'],
                'passed' => $finalUrl === $rule['value'],
                'message' => "Final URL: {$finalUrl}",
            ],
            'https_enforced' => [
                'rule' => 'https_enforced',
                'passed' => str_starts_with($finalUrl, 'https://'),
                'message' => str_starts_with($finalUrl, 'https://') ? 'HTTPS enforced' : "Final URL not HTTPS: {$finalUrl}",
            ],
            'no_redirect' => [
                'rule' => 'no_redirect',
                'passed' => count($chain) === 1,
                'message' => count($chain) === 1 ? 'No redirect' : (count($chain) - 1).' redirect(s) found',
            ],
            'max_hops' => [
                'rule' => 'max_hops',
                'value' => $rule['value'],
                'passed' => (count($chain) - 1) <= (int) $rule['value'],
                'message' => (count($chain) - 1).' hop(s) (max: '.$rule['value'].')',
            ],
            'www_canonical' => [
                'rule' => 'www_canonical',
                'value' => $rule['value'] ?? 'non-www',
                'passed' => $this->checkWwwCanonical($finalUrl, $rule['value'] ?? 'non-www'),
                'message' => 'Final host: '.(parse_url($finalUrl, PHP_URL_HOST) ?? 'unknown'),
            ],
            default => [
                'rule' => $rule['type'],
                'passed' => false,
                'message' => "Unknown rule type: {$rule['type']}",
            ],
        };
    }

    private function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function checkWwwCanonical(string $finalUrl, string $canonical): bool
    {
        $host = parse_url($finalUrl, PHP_URL_HOST) ?? '';

        return match ($canonical) {
            'non-www' => ! str_starts_with($host, 'www.'),
            'www' => str_starts_with($host, 'www.'),
            default => true,
        };
    }
}
