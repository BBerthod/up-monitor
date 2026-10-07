<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\BrokenRedirectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walks a monitor's outbound affiliate redirects and raises an insight when
 * they stop reaching the merchant.
 *
 * WHY THIS RUNS ON ITS OWN CADENCE
 * ────────────────────────────────
 * Unlike its sibling detectors, this one is NOT chained behind
 * CollectPageMetrics and does not wait for the daily 04:00 insight cycle.
 * A broken redirect is a total revenue outage for every affiliate link on the
 * site, and it is caused by exactly the kind of event that happens at any hour
 * — a plugin update, a theme deploy, a rewrite-rule change. Waiting up to 24
 * hours to notice was the difference between a nuisance and the multi-day
 * outage that motivated this service.
 *
 * It reads page_metrics only to pick which pages to harvest links from, and
 * tolerates slightly stale rows: the set of top pages barely moves day to day,
 * and any of them yields the same redirect links.
 *
 * TIMEOUT
 * ───────
 * 120 s covers 10 page fetches plus up to 25 redirect probes. Redirect probes
 * are cheap — they read the Location header without following it, so no
 * merchant round-trip is involved.
 */
class DetectBrokenRedirects implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30, 60];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(BrokenRedirectService $service): void
    {
        $count = $service->detectForMonitor($this->monitor);

        Log::info('DetectBrokenRedirects: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectBrokenRedirects job failed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
