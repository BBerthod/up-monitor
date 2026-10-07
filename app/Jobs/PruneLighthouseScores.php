<?php

namespace App\Jobs;

use App\Models\MonitorLighthouseScore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneLighthouseScores implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(): void
    {
        $retentionDays = (int) config('monitoring.lighthouse_scores_retention_days', 180);
        $cutoff = now()->subDays($retentionDays);
        $total = 0;

        do {
            $deleted = MonitorLighthouseScore::where('scored_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        if ($total > 0) {
            Log::info('PruneLighthouseScores: deleted old lighthouse scores', [
                'count' => $total,
                'retention_days' => $retentionDays,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('PruneLighthouseScores job failed', [
            'error' => $e->getMessage(),
        ]);
    }
}
