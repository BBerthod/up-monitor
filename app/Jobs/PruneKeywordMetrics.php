<?php

namespace App\Jobs;

use App\Models\KeywordMetric;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prunes keyword_metrics rows beyond the retention window.
 *
 * This is the fastest-growing table in the schema — up to 1000 rows per site
 * per run, against ~50 for page_metrics — so it is also the one that most needs
 * a bound.
 *
 * Retention (400 days by default) is deliberately wider than the 28-day
 * comparison window: keeping just over a year makes year-on-year comparison
 * possible, which is the one look-back seasonal sites actually need.
 *
 * Deletes in chunks, like the sibling pruning jobs, so a first run on a large
 * backlog never holds a long transaction.
 */
class PruneKeywordMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(): void
    {
        $retentionDays = (int) config('monitoring.keyword_tracking.retention_days', 400);
        $cutoff = now()->subDays($retentionDays);
        $total = 0;

        do {
            $deleted = KeywordMetric::where('captured_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        if ($total > 0) {
            Log::info('PruneKeywordMetrics: deleted old keyword snapshots', [
                'count' => $total,
                'retention_days' => $retentionDays,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('PruneKeywordMetrics job failed', [
            'error' => $e->getMessage(),
        ]);
    }
}
