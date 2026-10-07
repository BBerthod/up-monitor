<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\SslExpiryDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Detects imminent or expired SSL certificates for a single HTTPS monitor and
 * persists the finding as an Insight row via SslExpiryDetector.
 *
 * Dispatched per-monitor by DispatchInsights so that a single slow TLS lookup
 * cannot stall the rest of the fleet.  Mirrors the structure of DetectStrikingDistance.
 */
class DetectSslExpiry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [30, 60];

    public function __construct(public readonly Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(SslExpiryDetector $detector): void
    {
        $count = $detector->detectForMonitor($this->monitor);

        Log::info('DetectSslExpiry: completed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'insights' => $count,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DetectSslExpiry job failed', [
            'monitor_id' => $this->monitor->id,
            'url' => $this->monitor->url,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
