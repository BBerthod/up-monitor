<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\PerfRegressionDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Detects Lighthouse performance regressions for a single monitor and persists
 * them as Insight rows via PerfRegressionDetector.
 *
 * Dispatched per-monitor by DispatchInsights (independent, not inside the
 * Bus::chain) so that a missing Lighthouse history does not prevent the
 * rest of the insight pipeline from running.
 */
class DetectPerfRegression implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [30, 60];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(PerfRegressionDetector $detector): void
    {
        $count = $detector->detectForMonitor($this->monitor);

        Log::info('DetectPerfRegression: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectPerfRegression job failed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
