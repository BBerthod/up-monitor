<?php

namespace App\Jobs;

use App\Models\MonitorCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneMonitorChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(): void
    {
        $retentionDays = (int) config('monitoring.checks_retention_days', 90);
        $cutoff = now()->subDays($retentionDays);
        $total = 0;

        do {
            $deleted = MonitorCheck::where('checked_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        if ($total > 0) {
            Log::info('PruneMonitorChecks: deleted old monitor checks', [
                'count' => $total,
                'retention_days' => $retentionDays,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('PruneMonitorChecks job failed', [
            'error' => $e->getMessage(),
        ]);
    }
}
