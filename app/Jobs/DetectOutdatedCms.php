<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\WordPressVersionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks one WordPress site's served core version against upstream.
 *
 * Daily cadence: WordPress ships releases on the order of weeks, and the check
 * costs one homepage fetch plus a release list shared across the whole fleet.
 *
 * Scoped to a Site because the CMS version is a property of the deployment, and
 * because Site::type is what decides whether the check applies at all.
 */
class DetectOutdatedCms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public array $backoff = [60, 300];

    public function __construct(public readonly Site $site)
    {
        $this->onQueue('monitors');
    }

    public function handle(WordPressVersionService $service): void
    {
        $count = $service->detectForSite($this->site);

        Log::info('DetectOutdatedCms: completed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectOutdatedCms job failed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
