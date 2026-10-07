<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\StrikingDistanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Detects striking-distance SEO opportunities for a single monitor and persists
 * them as Insight rows via StrikingDistanceService.
 *
 * Dispatched per-monitor by DispatchInsights, which fans out across all active
 * HTTP monitors. Keeping this as a per-monitor job means:
 * - Each monitor's GSC API call is isolated; a timeout does not affect others.
 * - Failed monitors can be retried independently.
 * - The queue stays responsive — no single giant job blocks the workers.
 */
class DetectStrikingDistance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public array $backoff = [30, 60];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(StrikingDistanceService $service): void
    {
        $count = $service->detectForMonitor($this->monitor);

        Log::info('DetectStrikingDistance: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectStrikingDistance job failed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
