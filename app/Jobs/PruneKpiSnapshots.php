<?php

namespace App\Jobs;

use App\Models\KpiSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prunes kpi_snapshots rows beyond the retention window.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Every other metrics table in the schema is bounded — monitor_checks,
 * page_metrics, lighthouse scores, server metrics, warm runs, ingest events,
 * notification logs all have a pruning job. kpi_snapshots did not, and grew
 * without limit: roughly (sites × sources × metrics) rows every day, for as
 * long as the install has existed.
 *
 * At this fleet's size that is a slow leak rather than an emergency, which is
 * exactly why it went unnoticed — the table is queried by narrow indexed
 * lookups that stay fast long after it has stopped being reasonable.
 *
 * WHY THE WINDOW IS SO WIDE
 * ─────────────────────────
 * Wider than any other retention here, on purpose. This table holds two things
 * that are worth keeping far longer than raw checks:
 *
 *  - the daily health_score, which is what the trend arrows and the digest
 *    narrate over time;
 *  - the 28-day KPI series that year-on-year comparison reads, which is the
 *    one look-back seasonal sites genuinely need — several of these sites have
 *    a summer or a Christmas shape, and a 180-day window would make last
 *    year's peak invisible exactly when it matters.
 *
 * 400 days keeps a full year plus a month of margin, so a comparison made in
 * early January can still see the previous January.
 *
 * Deletes in bounded chunks, like its sibling jobs, so the first run against a
 * long backlog never holds a single long transaction.
 */
class PruneKpiSnapshots implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(): void
    {
        $retentionDays = (int) config('monitoring.kpi_snapshots.retention_days', 400);
        $cutoff = now()->subDays($retentionDays);
        $total = 0;

        do {
            $deleted = KpiSnapshot::where('captured_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        if ($total > 0) {
            Log::info('PruneKpiSnapshots: deleted old KPI snapshots', [
                'count' => $total,
                'retention_days' => $retentionDays,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('PruneKpiSnapshots job failed', [
            'error' => $e->getMessage(),
        ]);
    }
}
