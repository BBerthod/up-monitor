<?php

namespace App\Jobs;

use App\Models\FunctionalCheck;
use App\Services\FunctionalCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunFunctionalCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15];

    public int $timeout = 90;

    public function __construct(public FunctionalCheck $check)
    {
        $this->onQueue('monitors');
    }

    public function handle(FunctionalCheckService $service): void
    {
        $service->run($this->check);
    }

    public function failed(Throwable $e): void
    {
        Log::error('RunFunctionalCheck job failed', [
            'functional_check_id' => $this->check->id,
            'error' => $e->getMessage(),
        ]);
    }
}
