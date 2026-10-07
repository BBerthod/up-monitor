<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MonitorCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Watches the watchman: detects a stalled scheduler or queue worker.
 *
 * WHY THE ORIGINAL CHECK WAS NOT ENOUGH
 * ─────────────────────────────────────
 * It judged the health of every queue from a single signal —
 * MonitorCheck::max('checked_at') — which only moves when the `monitors` queue
 * is running. A blocked `warming`, `lighthouse` or `notifications` queue left
 * that timestamp perfectly fresh, so cache warming could stop, Lighthouse
 * audits could stop, and alerts could stop being delivered, all while this
 * command reported green. Silence looked exactly like success.
 *
 * WHAT IT CHECKS NOW
 * ──────────────────
 *  1. Scheduler liveness, as before: is any MonitorCheck being written?
 *  2. Per-queue backlog: is any queue accumulating jobs nobody consumes?
 *  3. failed_jobs growth: jobs failing permanently and silently.
 *
 * WHY BACKLOG ALONE IS NOT A STALL
 * ────────────────────────────────
 * A queue holding hundreds of jobs may be perfectly healthy — DispatchInsights
 * fans out exactly that shape once a day. What distinguishes a stall is that
 * the backlog does not shrink. Each run records the depth it saw and compares
 * against the previous one, so an alert needs evidence over time rather than a
 * snapshot.
 *
 * AUTO-RECOVERY
 * ─────────────
 * queue:restart is still issued on a genuine stall, but at most once per
 * cooldown window: restarting workers every five minutes because a queue is
 * legitimately busy would turn a false positive into an outage.
 */
class CheckSchedulerHealthCommand extends Command
{
    protected $signature = 'scheduler:health-check
                            {--stall-minutes=10 : Minutes without a MonitorCheck before declaring a stall}
                            {--backlog-threshold=200 : Queue depth above which a non-draining queue is a stall}';

    protected $description = 'Alert and auto-recover when the scheduler or any queue worker appears stalled.';

    /**
     * Queues that must have a consumer, mapped to their connection
     * (null = default connection).
     */
    private const WATCHED_QUEUES = [
        'monitors' => null,
        'notifications' => null,
        'lighthouse' => null,
        'warming' => 'redis_long',
    ];

    /** Cache key holding the previous run's queue depths. */
    private const STATE_KEY = 'scheduler:health:last-observation';

    /** Cache key marking the last auto-recovery. */
    private const RESTART_KEY = 'scheduler:health:last-restart';

    /** Minimum minutes between two auto-recovery restarts. */
    private const RESTART_COOLDOWN_MINUTES = 30;

    public function handle(): int
    {
        $problems = [
            ...$this->checkSchedulerLiveness(),
            ...$this->checkQueueBacklogs(),
            ...$this->checkFailedJobs(),
        ];

        if ($problems === []) {
            $this->info('Scheduler and queues are healthy.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem['message']);
        }

        Log::error('Scheduler health check failed', ['problems' => $problems]);

        // Only a stall is recoverable by restarting workers. A growing
        // failed_jobs table is a code problem: restarting would fail the same
        // jobs again, only faster.
        $recoverable = array_filter($problems, static fn (array $p): bool => $p['recoverable']);

        if ($recoverable !== []) {
            $this->attemptRecovery();
        }

        return self::FAILURE;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Checks
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Is the scheduler producing work at all?
     *
     * @return list<array{message: string, recoverable: bool}>
     */
    private function checkSchedulerLiveness(): array
    {
        $latestCheckedAt = MonitorCheck::max('checked_at');

        if ($latestCheckedAt === null) {
            $this->info('No MonitorChecks found — assuming fresh install.');

            return [];
        }

        // diffInMinutes() is signed; abs() guards against clock skew making a
        // recent timestamp read as a large negative age and silently passing.
        $ageMinutes = (int) abs(now()->diffInMinutes($latestCheckedAt));

        // Never below 2 minutes: right after a restart the queue is warming up
        // and a tighter threshold would fire on every deploy.
        $threshold = max(2, (int) $this->option('stall-minutes'));

        if ($ageMinutes < $threshold) {
            return [];
        }

        return [[
            'message' => "STALL: last MonitorCheck is {$ageMinutes}m old (threshold: {$threshold}m).",
            'recoverable' => true,
        ]];
    }

    /**
     * Is any queue accumulating work nobody consumes?
     *
     * @return list<array{message: string, recoverable: bool}>
     */
    private function checkQueueBacklogs(): array
    {
        $threshold = max(1, (int) $this->option('backlog-threshold'));
        $previous = cache()->get(self::STATE_KEY, []);
        $current = [];
        $problems = [];

        foreach (self::WATCHED_QUEUES as $queue => $connection) {
            try {
                $depth = Queue::connection($connection)->size($queue);
            } catch (\Throwable $e) {
                // An unreachable queue backend is worth reporting, but it is not
                // a stall a worker restart would fix.
                $problems[] = [
                    'message' => "Queue '{$queue}' could not be inspected: {$e->getMessage()}",
                    'recoverable' => false,
                ];

                continue;
            }

            $current[$queue] = $depth;

            if ($depth < $threshold) {
                continue;
            }

            $previousDepth = $previous[$queue] ?? null;

            // A deep queue is only a stall if it has not drained since the last
            // run. Comparing against the previous observation is what separates
            // "busy" from "abandoned" — a snapshot cannot tell them apart.
            if ($previousDepth !== null && $depth >= $previousDepth) {
                $problems[] = [
                    'message' => "STALL: queue '{$queue}' holds {$depth} jobs and has not drained "
                        ."since the last check ({$previousDepth}).",
                    'recoverable' => true,
                ];
            } else {
                $this->warn("Queue '{$queue}' is deep ({$depth}) but draining — not alerting.");
            }
        }

        // Record what we saw so the next run has a baseline to compare against.
        cache()->put(self::STATE_KEY, $current, now()->addHours(2));

        return $problems;
    }

    /**
     * Are jobs failing permanently and silently?
     *
     * @return list<array{message: string, recoverable: bool}>
     */
    private function checkFailedJobs(): array
    {
        try {
            $recent = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->count();
        } catch (\Throwable) {
            // No failed_jobs table configured — nothing to report.
            return [];
        }

        if ($recent === 0) {
            return [];
        }

        return [[
            'message' => "{$recent} job(s) failed permanently in the last hour.",
            // Restarting would fail the same jobs again — this needs a human.
            'recoverable' => false,
        ]];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Recovery
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Signal workers to restart, at most once per cooldown window.
     */
    private function attemptRecovery(): void
    {
        if (cache()->has(self::RESTART_KEY)) {
            $this->warn('Auto-recovery skipped — a restart was already issued recently.');

            return;
        }

        $this->warn('Triggering queue:restart to recover stalled workers...');
        $this->call('queue:restart');

        cache()->put(
            self::RESTART_KEY,
            now()->toIso8601String(),
            now()->addMinutes(self::RESTART_COOLDOWN_MINUTES),
        );

        Log::warning('CheckSchedulerHealthCommand: issued queue:restart for auto-recovery.');
    }
}
