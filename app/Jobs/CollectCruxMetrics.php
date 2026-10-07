<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\CruxCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls one site's field Core Web Vitals from the Chrome UX Report.
 *
 * Daily cadence, and no more: CrUX aggregates over a rolling 28-day window, so
 * consecutive readings differ by roughly a twenty-eighth of the data. Polling
 * more often would produce near-identical rows and buy nothing.
 *
 * Scoped to a Site because the query is origin-level, which is what gives
 * smaller sites enough traffic to clear CrUX's reporting threshold.
 */
class CollectCruxMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [60, 300];

    public function __construct(public readonly Site $site)
    {
        $this->onQueue('monitors');
    }

    public function handle(CruxCollector $collector): void
    {
        $count = $collector->collectForSite($this->site);

        Log::info('CollectCruxMetrics: completed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'snapshots' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('CollectCruxMetrics job failed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
