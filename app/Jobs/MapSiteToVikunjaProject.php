<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Vikunja\SiteProjectMapper;
use App\Services\Vikunja\VikunjaClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Maps a freshly created Site to its Vikunja project, so it does not spend
 * however long it takes for someone to remember `vikunja:doctor --map --apply`
 * silently dumping every one of its future alerts into the shared Inbox.
 *
 * Queued rather than run inline from the SiteObserver: mapping does a live
 * HTTP round trip to Vikunja, and nothing about creating a site — an admin
 * action or a bulk `sites:import` run — should wait on that.
 *
 * No match is not a failure of this job: SiteProjectMapper::mapOne() raises a
 * VIKUNJA_UNMAPPED_SITE insight itself in that case, which is the intended
 * outcome (visible, not silent) — not something to retry blindly against.
 */
class MapSiteToVikunjaProject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [60];

    public int $timeout = 30;

    public function __construct(public readonly int $siteId)
    {
        $this->onQueue('monitors');
    }

    public function handle(SiteProjectMapper $mapper, VikunjaClient $client): void
    {
        if (! $client->isConfigured()) {
            return;
        }

        $site = Site::withoutGlobalScopes()->find($this->siteId);

        if ($site === null) {
            // Deleted between dispatch and processing — nothing to map.
            return;
        }

        if ($site->vikunja_project_id !== null) {
            // Already mapped by the time this ran (e.g. a retry racing a manual
            // `vikunja:doctor --map --apply`) — nothing left to do.
            return;
        }

        $mapper->mapOne($site);
    }

    public function failed(Throwable $e): void
    {
        Log::error('MapSiteToVikunjaProject job failed', [
            'site_id' => $this->siteId,
            'error' => $e->getMessage(),
        ]);
    }
}
