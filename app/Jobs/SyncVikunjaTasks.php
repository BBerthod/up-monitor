<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\Vikunja\VikunjaClient;
use App\Services\Vikunja\VikunjaTaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the Vikunja board in step with Up's insights.
 *
 * Runs the three phases in order, for every team:
 *
 *   observe    → refresh the link table from live insights (sets first_seen_at)
 *   promote    → create cards for problems that have persisted long enough
 *   reconcile  → close cards whose problem is gone, acknowledge insights whose
 *                card a human has completed
 *
 * The order matters. observe() must run first so a problem that just resolved has
 * a stale last_seen_at by the time reconcile() looks at it, and promote() must run
 * before reconcile() so a card created in this very sweep is not immediately
 * examined for closure.
 *
 * WHY PULL AND NOT WEBHOOKS
 * ─────────────────────────
 * Vikunja registers webhooks per PROJECT, so covering the fleet would mean
 * creating and maintaining one webhook per site project — plus a public endpoint
 * on Up to receive them. Up already knows every task id it created, so re-reading
 * them costs a handful of GETs per sweep and exposes nothing.
 *
 * Every-fifteen-minutes is deliberate: the fastest signal that can reach the board
 * (server health) is pushed every five minutes, and nothing here is urgent enough
 * to justify tighter polling. Alerting stays the job of the notification channels;
 * the Kanban is for work, not for paging.
 */
class SyncVikunjaTasks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [60];

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(VikunjaTaskService $service, VikunjaClient $client): void
    {
        if (! $client->isConfigured()) {
            Log::debug('SyncVikunjaTasks: integration disabled, skipping');

            return;
        }

        $totals = ['observed' => 0, 'promoted' => 0, 'closed' => 0, 'acknowledged' => 0];

        foreach (Team::query()->cursor() as $team) {
            try {
                $totals['observed'] += $service->observe($team);
                $totals['promoted'] += $service->promote($team);

                $reconciled = $service->reconcile($team);
                $totals['closed'] += $reconciled['closed'];
                $totals['acknowledged'] += $reconciled['acknowledged'];
            } catch (Throwable $e) {
                Log::error('SyncVikunjaTasks: team sweep failed', [
                    'team_id' => $team->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue — one team's board must not block another's.
            }
        }

        // Only log when something actually happened: a quiet sweep every fifteen
        // minutes would drown the log it is supposed to make readable.
        if (array_sum($totals) > 0) {
            Log::info('SyncVikunjaTasks: sweep complete', $totals);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('SyncVikunjaTasks job failed', [
            'job' => static::class,
            'error' => $e->getMessage(),
        ]);
    }
}
