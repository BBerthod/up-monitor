<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\KeywordTrendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Detects keyword rank drops and cannibalisation for a monitor's site.
 *
 * CHAIN POSITION
 * ──────────────
 * Must run AFTER CollectPageMetrics, which is what persists the keyword rows
 * this reads. Running it before would compare yesterday's snapshot against
 * itself and never see the current day's movement.
 *
 * Purely database-bound — no outbound HTTP — so the timeout is short.
 */
class DetectKeywordTrends implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public array $backoff = [30, 120];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(KeywordTrendService $service): void
    {
        $count = $service->detectForMonitor($this->monitor);

        Log::info('DetectKeywordTrends: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectKeywordTrends job failed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
