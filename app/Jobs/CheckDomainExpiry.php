<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\DomainExpiryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Iterates every active site and delegates expiry detection to DomainExpiryService.
 *
 * DESIGN
 * ──────
 * Each site is processed in isolation inside a try/catch so a single RDAP failure
 * (e.g. unsupported TLD, network timeout) cannot prevent the remaining sites from
 * being checked.
 *
 * The job runs via the 'monitors' queue so it shares the same worker pool as other
 * infrastructure-health jobs and benefits from the same retry/timeout budget.
 *
 * FREQUENCY
 * ─────────
 * Domain expiry dates change at most once (on renewal) and are only meaningful at
 * a day granularity. A daily check at 03:30 UTC is more than sufficient — avoid
 * the temptation to run this more frequently.  Scheduling is in routes/console.php.
 *
 * CURSOR ITERATION
 * ────────────────
 * Sites are streamed via cursor() to avoid loading all rows into memory at once.
 * withoutGlobalScopes() bypasses the ScopedByTeam global scope so all teams are
 * covered in a single job run without requiring auth() context.
 */
class CheckDomainExpiry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [60, 300];

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(DomainExpiryService $service): void
    {
        $checked = 0;
        $insights = 0;
        $failed = 0;

        Site::withoutGlobalScopes()
            ->where('is_active', true)
            ->cursor()
            ->each(function (Site $site) use ($service, &$checked, &$insights, &$failed): void {
                try {
                    $insights += $service->detectForSite($site);
                    $checked++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('CheckDomainExpiry: failed to process site', [
                        'site_id' => $site->id,
                        'primary_domain' => $site->primary_domain,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue — one failing site must not abort the whole run.
                }
            });

        Log::info('CheckDomainExpiry: run complete', [
            'checked' => $checked,
            'insights' => $insights,
            'failed' => $failed,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('CheckDomainExpiry job failed permanently', [
            'job' => static::class,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
