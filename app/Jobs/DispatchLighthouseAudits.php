<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Support\LighthouseAuditability;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchLighthouseAudits implements ShouldQueue
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
        $index = 0;

        Monitor::withoutGlobalScopes()
            ->active()
            ->where('type', 'http')
            ->where('lighthouse_enabled', true)
            ->cursor()
            ->each(function (Monitor $monitor) use (&$index): void {
                // Defense-in-depth: excludes monitors that are structurally not
                // Lighthouse-auditable pages, even when `lighthouse_enabled` wasn't
                // manually turned off (e.g. a newly created JSON health-check
                // monitor or an affiliate redirect guard). See LighthouseAuditability.
                if (! LighthouseAuditability::isAuditable($monitor)) {
                    return;
                }

                RunLighthouseAudit::dispatch($monitor)
                    ->delay(now()->addSeconds($index * 20));

                $index++;
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
