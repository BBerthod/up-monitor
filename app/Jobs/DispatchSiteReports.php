<?php

namespace App\Jobs;

use App\Enums\ReportFrequency;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily fan-out: picks every active Site due for its periodic report today and
 * dispatches one SendSiteReport job per site.
 *
 * Due today means:
 *   - report_frequency = weekly  AND today is Monday, OR
 *   - report_frequency = monthly AND today is the 1st of the month,
 *   AND last_report_sent_at is null or was before today (idempotency guard —
 *   a re-run of this job on the same day, or a scheduler overlap, does not
 *   re-send a report that already went out today).
 *
 * A single site failing to dispatch does not stop the rest — errors are caught
 * and logged per site, same pattern as SendWeeklyReports.
 */
class DispatchSiteReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 60, 120];

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $today = Carbon::now();
        $startOfToday = $today->copy()->startOfDay();

        $dueFrequencies = [];
        if ($today->isMonday()) {
            $dueFrequencies[] = ReportFrequency::WEEKLY->value;
        }
        if ($today->day === 1) {
            $dueFrequencies[] = ReportFrequency::MONTHLY->value;
        }

        if ($dueFrequencies === []) {
            return;
        }

        Site::withoutGlobalScopes()
            ->active()
            ->whereIn('report_frequency', $dueFrequencies)
            ->where(fn ($q) => $q
                ->whereNull('last_report_sent_at')
                ->orWhere('last_report_sent_at', '<', $startOfToday))
            ->chunkById(50, function ($sites) {
                foreach ($sites as $site) {
                    try {
                        SendSiteReport::dispatch($site->id, $site->report_frequency->value);
                    } catch (Throwable $e) {
                        Log::error('DispatchSiteReports: failed to dispatch for site', [
                            'site_id' => $site->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    public function failed(Throwable $e): void
    {
        Log::error('DispatchSiteReports job failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
