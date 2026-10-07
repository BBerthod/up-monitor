<?php

namespace App\Jobs;

use App\Enums\InsightType;
use App\Models\Heartbeat;
use App\Models\Server;
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
 * Fan-out dispatcher for urgent infrastructure alerts.
 *
 * Counterpart to DispatchSeoAlerts, which runs once daily at 05:00 and covers
 * all insight types. SERVER_HEALTH and HEARTBEAT_MISSED alerts are operationally
 * urgent: a server may be on fire, or a critical scheduled task may have stopped
 * running. Neither can wait up to 24 hours to be notified. This job runs every
 * 5 minutes and dispatches only unnotified WARNING/CRITICAL insights of those types,
 * reusing SeoAlertService::dispatchForTeam with an onlyTypes filter so notification
 * logic (priority sorting, anti-spam cap, notified_at stamp) is not duplicated.
 *
 * Teams selection: any team that owns at least one active Server or Heartbeat,
 * scoped without global scopes because this job runs without an authenticated
 * user session.
 *
 * Failure isolation: a crash for one team is caught and logged; remaining teams
 * are still processed. The job itself retries on unhandled exceptions (tries=3).
 */
class DispatchServerAlerts implements ShouldQueue
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
        // Teams that own at least one active server or heartbeat — withoutGlobalScopes()
        // because this job runs without an authenticated user session.
        $serverTeamIds = Server::withoutGlobalScopes()
            ->where('is_active', true)
            ->distinct()
            ->pluck('team_id');

        $heartbeatTeamIds = Heartbeat::withoutGlobalScopes()
            ->where('is_active', true)
            ->distinct()
            ->pluck('team_id');

        $teamIds = $serverTeamIds
            ->merge($heartbeatTeamIds)
            ->unique()
            ->values();

        $totalDispatched = 0;
        $failed = 0;

        foreach ($teamIds as $teamId) {
            try {
                $team = Team::findOrFail($teamId);
                $dispatched = $seoAlertService->dispatchForTeam(
                    $team,
                    [
                        InsightType::SERVER_HEALTH->value,
                        InsightType::HEARTBEAT_MISSED->value,
                    ],
                );
                $totalDispatched += $dispatched;
            } catch (Throwable $e) {
                $failed++;
                Log::error('DispatchServerAlerts: failed to process team', [
                    'team_id' => $teamId,
                    'error' => $e->getMessage(),
                ]);
                // Continue — one failing team must not abort the rest.
            }
        }

        Log::info('DispatchServerAlerts: run complete', [
            'teams_processed' => $teamIds->count(),
            'teams_failed' => $failed,
            'alerts_queued' => $totalDispatched,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DispatchServerAlerts dispatcher job failed permanently', [
            'job' => static::class,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
