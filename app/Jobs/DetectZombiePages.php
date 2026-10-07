<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\ZombiePageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds a site's published pages that search has never seen.
 *
 * Scoped to a Site: the comparison is between the site's own sitemap and the
 * page metrics collected for that hostname, neither of which belongs to a
 * monitor.
 *
 * Daily cadence, though the signal moves on the order of weeks — it costs one
 * sitemap fetch and one indexed query, and running it alongside the other
 * site-level detectors keeps the schedule simple.
 *
 * Timeout covers a sitemap index plus its children on the largest sites here.
 */
class DetectZombiePages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public array $backoff = [60, 300];

    public function __construct(public readonly Site $site)
    {
        $this->onQueue('monitors');
    }

    public function handle(ZombiePageService $service): void
    {
        $count = $service->detectForSite($this->site);

        Log::info('DetectZombiePages: completed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectZombiePages job failed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
