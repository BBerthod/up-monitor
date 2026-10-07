<?php

namespace App\Jobs;

use App\Services\OrphanMonitorDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the orphan-monitor detector across all teams and persists / maintains
 * the corresponding HEALTH_DROP Insights.
 *
 * Dispatched as a standalone job from DispatchInsights (not per-monitor, because
 * the detector evaluates all teams at once and requires no site-specific data).
 *
 * Safe to run repeatedly — the detector is fully idempotent.
 */
class DetectOrphanMonitors implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(OrphanMonitorDetector $detector): void
    {
        try {
            $count = $detector->evaluateAll();

            Log::info('OrphanMonitorDetector completed', ['orphan_total' => $count]);
        } catch (Throwable $e) {
            Log::error('OrphanMonitorDetector failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectOrphanMonitors job failed permanently', [
            'error' => $e->getMessage(),
        ]);
    }
}
