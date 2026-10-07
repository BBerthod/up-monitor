<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\AdSenseCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls one site's AdSense earnings into kpi_snapshots.
 *
 * Scoped to a Site because AdSense reports are filtered by domain, and because
 * whether the site is on AdSense at all is decided by Site::ad_networks.
 *
 * Daily cadence, aligned with the other KPI collectors: AdSense figures are
 * themselves estimates that settle over hours, so polling more often would add
 * churn rather than information.
 */
class CollectAdSenseMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [60, 300];

    public function __construct(public readonly Site $site)
    {
        $this->onQueue('monitors');
    }

    public function handle(AdSenseCollector $collector): void
    {
        $count = $collector->collectForSite($this->site);

        Log::info('CollectAdSenseMetrics: completed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'snapshots' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('CollectAdSenseMetrics job failed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
