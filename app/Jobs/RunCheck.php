<?php

namespace App\Jobs;

use App\Events\MonitorChecked;
use App\Models\Monitor;
use App\Services\CheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15];

    public int $timeout = 45;

    public function __construct(public Monitor $monitor)
    {
        $this->onQueue('monitors');
    }

    public function handle(CheckService $checkService): void
    {
        $check = $checkService->check($this->monitor);

        MonitorChecked::dispatch($this->monitor, $check);
    }

    public function failed(Throwable $e): void
    {
        Log::error('RunCheck job failed', [
            'monitor_id' => $this->monitor->id,
            'error' => $e->getMessage(),
        ]);
    }
}
