<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\ContentDecayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyses page_metrics for the monitor and raises CONTENT_DECAY insights.
 *
 * Chained AFTER CollectPageMetrics (via Bus::chain in DispatchInsights) to
 * guarantee it sees the freshest snapshot from the current run before
 * performing the baseline vs current comparison.
 *
 * On first run (no historical data): silently exits with 0 insights.
 * Detection becomes progressively more accurate as the time-series grows.
 */
class DetectContentDecay implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public array $backoff = [30, 60];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(ContentDecayService $service): void
    {
        $count = $service->detectForMonitor($this->monitor);

        Log::info('DetectContentDecay: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectContentDecay job failed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
