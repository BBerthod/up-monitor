<?php

namespace App\Observers;

use App\Jobs\MapSiteToVikunjaProject;
use App\Models\Site;
use App\Services\Vikunja\VikunjaClient;

/**
 * Every new site must earn a Vikunja project the moment it exists, or its
 * alerts silently drain into the shared Inbox until someone remembers to run
 * `vikunja:doctor --map --apply` by hand (the defect this observer closes).
 *
 * `vikunja:doctor --map --apply` running daily (see routes/console.php) is the
 * safety net for sites created outside Eloquent (raw inserts, seeders run with
 * events disabled) and for board projects created after the site itself — this
 * observer is the fast path for the common case.
 *
 * Gated on isConfigured() here, not just inside the job: the integration is
 * off by default (local/dev/most test runs), and a site is created constantly
 * throughout the suite — dispatching a job every time, even one that turns out
 * to be a no-op, would trip every unrelated `Queue::assertNothingPushed()`
 * elsewhere in the codebase.
 */
class SiteObserver
{
    public function created(Site $site): void
    {
        if (! app(VikunjaClient::class)->isConfigured()) {
            return;
        }

        MapSiteToVikunjaProject::dispatch($site->id);
    }
}
