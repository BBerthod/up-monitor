<?php

namespace App\Jobs;

use App\Enums\SmokeTestStatus;
use App\Models\DeployEvent;
use App\Models\SmokeTestConfig;
use App\Models\User;
use App\Notifications\DeployPassedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunPostDeploySmokeTests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30, 60];

    public int $timeout = 120;

    public function __construct(public DeployEvent $deployEvent)
    {
        $this->onQueue('monitors');
    }

    public function handle(): void
    {
        $config = SmokeTestConfig::where('application_id', $this->deployEvent->application_id)
            ->active()
            ->first();

        if ($config === null) {
            Log::info('RunPostDeploySmokeTests: no active config found, marking as passed', [
                'application_id' => $this->deployEvent->application_id,
            ]);

            $this->deployEvent->update(['smoke_test_status' => SmokeTestStatus::PASSED]);

            return;
        }

        $results = [];
        $allPassed = true;

        foreach ($config->tests as $test) {
            $result = $this->runTest($test);
            $results[] = $result;

            if (! $result['passed']) {
                $allPassed = false;
            }
        }

        $status = $allPassed ? SmokeTestStatus::PASSED : SmokeTestStatus::FAILED;

        $this->deployEvent->update([
            'smoke_test_status' => $status,
            'smoke_test_results' => $results,
        ]);

        Log::info('RunPostDeploySmokeTests: smoke tests completed', [
            'deploy_event_id' => $this->deployEvent->id,
            'application_id' => $this->deployEvent->application_id,
            'status' => $status->value,
            'total' => count($results),
            'failed' => count(array_filter($results, fn ($r) => ! $r['passed'])),
        ]);

        if ($allPassed) {
            $this->notifyAdmins(new DeployPassedNotification($this->deployEvent));
        } else {
            TriggerDokployRollback::dispatch($this->deployEvent);
        }
    }

    private function runTest(array $test): array
    {
        $url = $test['url'];
        $expectedStatus = $test['expected_status'] ?? 200;
        $expectedKeyword = isset($test['expected_keyword']) && $test['expected_keyword'] !== '' ? $test['expected_keyword'] : null;
        $expectedJsonPath = $test['expected_json_path'] ?? null;
        $expectedValue = $test['expected_value'] ?? null;
        $timeoutMs = $test['timeout_ms'] ?? 5000;

        $result = [
            'url' => $url,
            'passed' => false,
            'status_code' => null,
            'response_time_ms' => null,
            'error' => null,
        ];

        try {
            $startedAt = microtime(true);

            $response = Http::timeout((int) ($timeoutMs / 1000))
                ->withoutVerifying()
                ->get($url);

            $result['response_time_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            $result['status_code'] = $response->status();

            // Check status code
            if ($response->status() !== (int) $expectedStatus) {
                $result['error'] = "Expected status {$expectedStatus}, got {$response->status()}";

                return $result;
            }

            // Check keyword in body
            if ($expectedKeyword !== null && ! str_contains($response->body(), $expectedKeyword)) {
                $result['error'] = "Keyword '{$expectedKeyword}' not found in response body";

                return $result;
            }

            // Check JSON path value
            if ($expectedJsonPath !== null && $expectedValue !== null) {
                $jsonValue = $this->resolveJsonPath($response->json(), $expectedJsonPath);

                if ($jsonValue !== $expectedValue) {
                    $result['error'] = "JSON path '{$expectedJsonPath}' expected '{$expectedValue}', got '{$jsonValue}'";

                    return $result;
                }
            }

            $result['passed'] = true;
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Resolves a simple JSONPath expression like "$.status" against decoded JSON.
     * Only supports single-level dot-notation ($.key).
     */
    private function resolveJsonPath(mixed $json, string $path): mixed
    {
        if (! is_array($json)) {
            return null;
        }

        // Strip leading "$." and resolve the key
        $key = ltrim($path, '$.');

        return $json[$key] ?? null;
    }

    private function notifyAdmins(object $notification): void
    {
        User::admin()->get()->each(function (User $user) use ($notification): void {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                Log::error('RunPostDeploySmokeTests: failed to notify admin', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function failed(Throwable $e): void
    {
        Log::error('RunPostDeploySmokeTests job failed', [
            'deploy_event_id' => $this->deployEvent->id,
            'application_id' => $this->deployEvent->application_id,
            'error' => $e->getMessage(),
        ]);

        // Mark as failed when the job itself fails (not just a smoke test)
        $this->deployEvent->update(['smoke_test_status' => SmokeTestStatus::FAILED]);
    }
}
