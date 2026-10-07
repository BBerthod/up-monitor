<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\HealthDropDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Detects health-grade drops for a single monitor via HealthDropDetector.
 *  Dispatched per-monitor by DispatchInsights alongside DetectStrikingDistance.
 */
class DetectHealthDrop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [30, 60];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(HealthDropDetector $d): void
    {
        $c = $d->detectForMonitor($this->monitor);
        Log::info('DetectHealthDrop: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'insights' => $c,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectHealthDrop failed', [
            'monitor_id' => $this->monitor->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
