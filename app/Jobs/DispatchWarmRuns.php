<?php

namespace App\Jobs;

use App\Enums\WarmRunStatus;
use App\Models\WarmRun;
use App\Models\WarmSite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchWarmRuns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(): void
    {
        // Sweep orphaned runs: worker killed mid-run (deploy or crash) leaves a WarmRun stuck in
        // "running" with 0 URLs and a held Cache::lock for up to 11 minutes.  Any run that has
        // been "running" for more than 15 minutes is definitively orphaned — mark it failed so the
        // lock TTL is the only remaining obstacle (it expires in ≤11 min regardless).
        WarmRun::where('status', WarmRunStatus::RUNNING)
            ->where('started_at', '<', now()->subMinutes(15))
            ->update([
                'status' => WarmRunStatus::FAILED,
                'error_message' => 'Orphaned run: worker restarted mid-run (deploy or crash)',
                'completed_at' => now(),
            ]);

        $sites = WarmSite::withoutGlobalScopes()
            ->dueForWarming()
            ->whereDoesntHave('warmRuns', fn ($q) => $q->where('status', WarmRunStatus::RUNNING))
            ->get();

        foreach ($sites as $site) {
            RunWarmSite::dispatch($site);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Dispatcher job failed', [
            'job' => static::class,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
