<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * Returns 200 when the application is healthy, 503 when unhealthy.
     *
     * Checks performed:
     *  - Redis reachability
     *  - Latest MonitorCheck age (stall detection)
     *  - Redis queue pending-jobs count
     */
    public function __invoke(): JsonResponse
    {
        $checks = $this->runChecks();
        $unhealthy = array_filter($checks, fn (array $c) => $c['status'] !== 'ok');

        if ($unhealthy) {
            $reasons = array_values(array_map(fn (array $c) => $c['reason'] ?? $c['name'], $unhealthy));

            return response()->json([
                'status' => 'unhealthy',
                'reason' => implode('; ', $reasons),
                'checks' => $checks,
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }

        return response()->json([
            'status' => 'healthy',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, array{name: string, status: string, reason?: string, value?: mixed}>
     */
    private function runChecks(): array
    {
        return [
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueueBacklog(),
            'scheduler' => $this->checkSchedulerStall(),
        ];
    }

    /**
     * Verify Redis is reachable.
     */
    private function checkRedis(): array
    {
        try {
            Redis::ping();

            return ['name' => 'redis', 'status' => 'ok'];
        } catch (\Throwable) {
            return [
                'name' => 'redis',
                'status' => 'fail',
                'reason' => 'Redis is unreachable',
            ];
        }
    }

    /**
     * Alert when the pending-jobs count in the monitors queue exceeds 1 000.
     * Uses the database-backed failed_jobs table as a proxy for queue depth when
     * the Redis queue driver is in use (redis LLEN on the queue key).
     */
    private function checkQueueBacklog(): array
    {
        try {
            // The monitors queue is stored as lists in Redis.
            $pending = (int) Redis::llen('queues:monitors');

            $limit = 1_000;

            if ($pending > $limit) {
                return [
                    'name' => 'queue',
                    'status' => 'fail',
                    'reason' => "monitors queue backlog {$pending} exceeds threshold {$limit}",
                    'value' => $pending,
                ];
            }

            return ['name' => 'queue', 'status' => 'ok', 'value' => $pending];
        } catch (\Throwable) {
            // Redis is already flagged by checkRedis(); don't double-report.
            return ['name' => 'queue', 'status' => 'ok', 'value' => null];
        }
    }

    /**
     * Alert when no MonitorCheck has been written within 2 × the maximum active
     * monitor interval (minimum stall threshold: 10 minutes).
     */
    private function checkSchedulerStall(): array
    {
        $latestCheckedAt = MonitorCheck::max('checked_at');

        if ($latestCheckedAt === null) {
            // No checks yet — fresh install, not a stall.
            return ['name' => 'scheduler', 'status' => 'ok', 'value' => null];
        }

        $ageSeconds = (int) Carbon::parse($latestCheckedAt)->diffInSeconds(now());

        $maxInterval = (int) Monitor::withoutGlobalScopes()
            ->active()
            ->max('interval');

        // Stall threshold: 2 × max monitor interval, but at least 10 minutes.
        $thresholdSeconds = max(10 * 60, $maxInterval * 2 * 60);

        if ($ageSeconds > $thresholdSeconds) {
            return [
                'name' => 'scheduler',
                'status' => 'fail',
                'reason' => "Last MonitorCheck is {$ageSeconds}s old (threshold: {$thresholdSeconds}s). Scheduler or worker may be stalled.",
                'value' => [
                    'latest_check_age_seconds' => $ageSeconds,
                    'threshold_seconds' => $thresholdSeconds,
                ],
            ];
        }

        return [
            'name' => 'scheduler',
            'status' => 'ok',
            'value' => [
                'latest_check_age_seconds' => $ageSeconds,
                'threshold_seconds' => $thresholdSeconds,
            ],
        ];
    }
}
