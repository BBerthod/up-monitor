<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\SitemapHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Audits one site's sitemap for staleness and non-canonical entries.
 *
 * Scoped to a Site rather than a Monitor: a sitemap belongs to a hostname, and
 * dispatching per monitor would audit the same document several times on sites
 * that have more than one monitor.
 *
 * TIMEOUT
 * ───────
 * 180 s covers an index fetch plus up to 10 child sitemaps plus 40 HEAD probes.
 * Generous because sitemap-index files on cold caches have been measured in the
 * 10-20 s range in this fleet.
 */
class DetectSitemapHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public array $backoff = [60, 300];

    public function __construct(public readonly Site $site)
    {
        $this->onQueue('monitors');
    }

    public function handle(SitemapHealthService $service): void
    {
        $count = $service->detectForSite($this->site);

        Log::info('DetectSitemapHealth: completed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectSitemapHealth job failed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
