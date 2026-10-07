<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\HreflangAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Audits one multi-locale site's hreflang reciprocity.
 *
 * Scoped to a Site: hreflang describes the relationship BETWEEN a site's
 * locales, so it cannot be judged from any single monitor.
 *
 * Daily cadence. hreflang changes only when a template or plugin changes, and
 * the damage is a gradual ranking drift rather than an outage — but that drift
 * is invisible without this check, which is why it runs at all.
 *
 * Timeout covers one homepage fetch per locale, plus margin for the slowest
 * multi-locale sites in the fleet.
 */
class DetectHreflangIssues implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [60, 300];

    public function __construct(public readonly Site $site)
    {
        $this->onQueue('monitors');
    }

    public function handle(HreflangAuditService $service): void
    {
        $count = $service->detectForSite($this->site);

        Log::info('DetectHreflangIssues: completed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectHreflangIssues job failed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
