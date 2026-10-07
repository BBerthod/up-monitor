<?php

namespace App\Jobs;

use App\Models\FunctionalCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchFunctionalChecks implements ShouldQueue
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
        FunctionalCheck::dueForCheck()
            ->cursor()
            ->each(fn (FunctionalCheck $check) => RunFunctionalCheck::dispatch($check));
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
