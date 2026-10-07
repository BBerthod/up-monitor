<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\CmpDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks that an ad-monetised site ships exactly one consent platform.
 *
 * Scoped to a Site: consent is configured per hostname, and ad_networks — the
 * field that decides whether the check applies at all — lives on the Site.
 *
 * Daily cadence is right here. A CMP disappears through a deploy or a plugin
 * change, not spontaneously, and the loss is a gradual revenue haircut rather
 * than an outage, so there is nothing to gain from probing more often.
 */
class DetectMissingCmp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public array $backoff = [30, 120];

    public function __construct(public readonly Site $site)
    {
        $this->onQueue('monitors');
    }

    public function handle(CmpDetector $detector): void
    {
        $count = $detector->detectForSite($this->site);

        Log::info('DetectMissingCmp: completed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectMissingCmp job failed', [
            'site_id' => $this->site->id,
            'domain' => $this->site->primary_domain,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
