<?php

namespace App\Jobs;

use App\Models\Monitor;
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
 * Fan-out dispatcher: iterates teams that own at least one active HTTP monitor,
 * then calls SeoAlertService::dispatchForTeam for each.
 *
 * Running after DispatchInsights (04:00 UTC) ensures that WhatChangedService
 * insights created overnight are already persisted before we notify. This job
 * is scheduled at 05:00 UTC — see routes/console.php.
 *
 * Failure isolation: a crash for one team is caught and logged; remaining teams
 * are still processed. The job itself retries on unhandled exceptions (tries=3).
 */
class DispatchSeoAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(SeoAlertService $seoAlertService): void
    {
        // Teams with at least one active monitor — withoutGlobalScopes() because
        // this job runs without an authenticated user session.
        $teamIds = Monitor::withoutGlobalScopes()
            ->active()
            ->distinct()
            ->pluck('team_id');

        $totalDispatched = 0;
        $failed = 0;

        foreach ($teamIds as $teamId) {
            try {
                $team = Team::findOrFail($teamId);
                $dispatched = $seoAlertService->dispatchForTeam($team);
                $totalDispatched += $dispatched;
            } catch (Throwable $e) {
                $failed++;
                Log::error('DispatchSeoAlerts: failed to process team', [
                    'team_id' => $teamId,
                    'error' => $e->getMessage(),
                ]);
                // Continue — one failing team must not abort the rest.
            }
        }

        Log::info('DispatchSeoAlerts: run complete', [
            'teams_processed' => $teamIds->count(),
            'teams_failed' => $failed,
            'alerts_queued' => $totalDispatched,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DispatchSeoAlerts dispatcher job failed permanently', [
            'job' => static::class,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
