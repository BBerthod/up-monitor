<?php

namespace App\Jobs;

use App\Enums\InsightType;
use App\Models\Team;
use App\Services\SeoAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fan-out dispatcher for revenue-blocking alerts that must not wait for the
 * daily 05:00 SEO alert run.
 *
 * Mirrors DispatchServerAlerts exactly, for a different urgency class: a broken
 * affiliate redirect earns nothing from the moment it breaks, and the site looks
 * perfectly healthy from the outside while it happens. Discovering it the next
 * morning is discovering it a day late — which is precisely how the production
 * incident unfolded.
 *
 * Reuses SeoAlertService::dispatchForTeam with an onlyTypes filter so priority
 * sorting, the anti-spam cap and the notified_at stamp are not duplicated here.
 *
 * Teams selection: every team, since affiliate business is a property of a site
 * rather than of an infrastructure object we could filter on cheaply. The
 * underlying query is already scoped to unnotified WARNING/CRITICAL insights of
 * these types, so teams with nothing to say cost one empty query.
 */
class DispatchRevenueAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(SeoAlertService $seoAlertService): void
    {
        $teamIds = Team::withoutGlobalScopes()->pluck('id');

        $totalDispatched = 0;
        $failed = 0;

        foreach ($teamIds as $teamId) {
            try {
                $team = Team::findOrFail($teamId);
                $dispatched = $seoAlertService->dispatchForTeam(
                    $team,
                    [InsightType::AFFILIATE_REDIRECT_BROKEN->value],
                );
                $totalDispatched += $dispatched;
            } catch (Throwable $e) {
                $failed++;
                Log::error('DispatchRevenueAlerts: failed to process team', [
                    'team_id' => $teamId,
                    'error' => $e->getMessage(),
                ]);
                // Continue — one failing team must not abort the rest.
            }
        }

        Log::info('DispatchRevenueAlerts: run complete', [
            'teams_processed' => $teamIds->count(),
            'teams_failed' => $failed,
            'alerts_queued' => $totalDispatched,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DispatchRevenueAlerts dispatcher job failed permanently', [
            'job' => static::class,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
