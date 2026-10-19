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
 * 180 s is a hard kill (the worker is terminated, `failed()` does not run), so the
 * service enforces its own softer wall-clock budget
 * (monitoring.sitemap_health.time_budget_seconds, 120 s) and returns a partial
 * audit instead. The index budget is up to 1000 child sitemaps
 * (monitoring.sitemap_health.max_child_sitemaps): at the 30 s per-request timeout
 * that cannot fit in 180 s on a large index, which is what the budget is for.
 */
class DetectSitemapHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

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
