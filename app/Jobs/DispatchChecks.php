<?php

namespace App\Jobs;

use App\Models\Monitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchChecks implements ShouldQueue
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
        Monitor::withoutGlobalScopes()
            ->active()
            ->dueForCheck()
            ->cursor()
            ->each(function (Monitor $monitor): void {
                try {
                    RunCheck::dispatch($monitor);
                } catch (Throwable $e) {
                    // A single monitor must not poison the entire dispatch loop.
                    Log::error('DispatchChecks: failed to dispatch RunCheck for monitor', [
                        'monitor_id' => $monitor->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
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
