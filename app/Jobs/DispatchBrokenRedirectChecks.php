<?php

namespace App\Jobs;

use App\Enums\MonitorType;
use App\Models\Monitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatcher: fans out affiliate-redirect checks across sites that do affiliate
 * business, on a much tighter cadence than the daily insight cycle.
 *
 * ONE DISPATCH PER SITE
 * ─────────────────────
 * Redirect health is a property of the SITE (its rewrite rules), not of an
 * individual monitor. Dispatching per monitor would probe the same rewrite rule
 * N times for a site that has several monitors and produce duplicate insights —
 * the same fan-out bug already fixed in DispatchInsights. The representative
 * monitor for a site is the one with the smallest id, chosen deterministically
 * so ownership of the insight does not flip between runs.
 *
 * Sites without merchant_domains are skipped here rather than inside the
 * service, so a site that does no affiliate business costs nothing at all.
 */
class DispatchBrokenRedirectChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(): void
    {
        $dispatched = 0;
        $failed = 0;
        $seenSites = [];

        $monitors = Monitor::withoutGlobalScopes()
            ->active()
            ->where('type', MonitorType::HTTP->value)
            ->whereNotNull('site_id')
            ->with('site')
            ->orderBy('id')
            ->get();

        foreach ($monitors as $monitor) {
            $site = $monitor->site;

            // Skip sites that declare no merchants: nothing to redirect to.
            if ($site === null
                || ! is_array($site->merchant_domains)
                || $site->merchant_domains === []) {
                continue;
            }

            // One dispatch per site — see the class docblock.
            if (isset($seenSites[$site->id])) {
                continue;
            }

            $seenSites[$site->id] = true;

            try {
                DetectBrokenRedirects::dispatch($monitor);
                $dispatched++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('DispatchBrokenRedirectChecks: failed to dispatch', [
                    'monitor_id' => $monitor->id,
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue — one bad dispatch must not abort the fleet.
            }
        }

        Log::info('DispatchBrokenRedirectChecks: fan-out complete', [
            'dispatched' => $dispatched,
            'failed' => $failed,
        ]);
    }
}
