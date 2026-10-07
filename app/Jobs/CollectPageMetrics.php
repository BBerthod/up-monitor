<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\PageMetricsCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches GSC data for the monitor and persists per-page snapshots in page_metrics.
 *
 * Chained BEFORE DetectContentDecay (via Bus::chain in DispatchInsights) so that
 * the detector always works on freshly persisted data from the same run.
 *
 * Keeping collection and detection in separate jobs lets us:
 * - Retry each independently (a collection failure does not skip detection of prior data).
 * - Keep each job fast (timeout 90 s covers the GSC HTTP call comfortably).
 */
class CollectPageMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public array $backoff = [30, 60];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(PageMetricsCollector $collector): void
    {
        $count = $collector->collectForMonitor($this->monitor);

        Log::info('CollectPageMetrics: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'pages_persisted' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('CollectPageMetrics job failed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
