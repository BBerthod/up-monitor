<?php

namespace App\Jobs;

use App\Models\PageMetric;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prunes page_metrics rows older than the configured retention period.
 *
 * Retention default (180 days) is intentionally wider than the decay detection
 * window (84 days) to allow forensic look-backs without bloating the table
 * indefinitely.  Rows beyond 180 days have no analytical value and can be
 * safely discarded.
 *
 * Chunked deletes (1 000 rows at a time) avoid long-running DELETE statements
 * that would lock the table for other concurrent writers.
 */
class PrunePageMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(): void
    {
        $retentionDays = (int) config('monitoring.content_decay.window_days', 84) * 2;
        $cutoff = now()->subDays($retentionDays);
        $total = 0;

        do {
            $deleted = PageMetric::where('captured_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        if ($total > 0) {
            Log::info('PrunePageMetrics: deleted old page metric snapshots', [
                'count' => $total,
                'retention_days' => $retentionDays,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('PrunePageMetrics job failed', [
            'error' => $e->getMessage(),
        ]);
    }
}
