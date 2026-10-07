<?php

namespace App\Jobs;

use App\Models\FunctionalCheckResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneFunctionalCheckResults implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(): void
    {
        $retentionDays = (int) config('monitoring.functional_check_results_retention_days', 90);
        $cutoff = now()->subDays($retentionDays);
        $total = 0;

        do {
            $deleted = FunctionalCheckResult::where('checked_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        if ($total > 0) {
            Log::info('PruneFunctionalCheckResults: deleted old functional check results', [
                'count' => $total,
                'retention_days' => $retentionDays,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('PruneFunctionalCheckResults job failed', [
            'error' => $e->getMessage(),
        ]);
    }
}
