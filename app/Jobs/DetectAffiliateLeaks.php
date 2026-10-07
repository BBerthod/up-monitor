<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\AffiliateAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crawls high-traffic pages for a given monitor and creates AFFILIATE_LEAK
 * insights when Amazon associate links carry a foreign tag or no tag at all.
 *
 * CHAIN POSITION
 * ──────────────
 * This job is chained AFTER CollectPageMetrics (alongside DetectContentDecay
 * and DetectBrokenPages) so it always operates on the freshest page_metrics data
 * from the current collection cycle:
 *
 *   CollectPageMetrics → DetectContentDecay → DetectBrokenPages → DetectAffiliateLeaks
 *
 * Running after CollectPageMetrics is critical: AffiliateAuditService reads clicks
 * from page_metrics to decide which pages are worth crawling.  Without fresh data,
 * a newly high-traffic page would be missed until the next cycle.
 *
 * Affiliate link composition changes slowly (plugin updates, theme deploys) so
 * running once per insight cycle (daily) is sufficient.
 *
 * TIMEOUT
 * ───────
 * 120 s covers up to 30 pages × ~4 s per HTTP response with margin.
 * Uses the default 'monitors' queue — 120 s is within the standard timeout budget
 * and keeps the job alongside its chain siblings.
 */
class DetectAffiliateLeaks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30, 60];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(AffiliateAuditService $service): void
    {
        $count = $service->detectForMonitor($this->monitor);

        Log::info('DetectAffiliateLeaks: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectAffiliateLeaks job failed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
