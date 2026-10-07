<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\BrokenPageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Probes high-traffic pages for a given monitor and creates REVENUE_AT_RISK
 * insights when a page that earns organic clicks returns an HTTP error or is
 * unreachable.
 *
 * CHAIN POSITION
 * ──────────────
 * This job is chained AFTER DetectContentDecay (which itself runs after
 * CollectPageMetrics) so it always operates on the freshest page_metrics data
 * from the current collection cycle:
 *
 *   CollectPageMetrics → DetectContentDecay → DetectBrokenPages
 *
 * Running after CollectPageMetrics is important: BrokenPageService reads clicks
 * from page_metrics to decide which pages are worth probing.  Without fresh
 * data, a newly high-traffic page would be missed until the next cycle.
 *
 * TIMEOUT
 * ───────
 * 120 s covers up to 30 pages × ~4 s per slow HTTP response with margin.
 * Uses the default 'monitors' queue (not redis_long) — 120 s is within the
 * standard timeout budget and keeps the job alongside its chain siblings.
 */
class DetectBrokenPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30, 60];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(BrokenPageService $service): void
    {
        $count = $service->detectForMonitor($this->monitor);

        Log::info('DetectBrokenPages: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectBrokenPages job failed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
