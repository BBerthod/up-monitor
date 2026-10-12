<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\CheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by the Dokploy deploy hook, delayed until the post-deploy silence
 * window ends. Sends the "down" alert that was swallowed during the window for
 * monitors that are still down. See CheckService::reemitDeferredDownAlert().
 */
class ReemitDownAlertsAfterDeployWindow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30];

    /**
     * @param  array<int, int>  $monitorIds
     */
    public function __construct(public array $monitorIds)
    {
        $this->onQueue('monitors');
    }

    public function handle(CheckService $checkService): void
    {
        Monitor::withoutGlobalScopes()
            ->whereIn('id', $this->monitorIds)
            ->where('is_active', true)
            ->get()
            ->each(fn (Monitor $monitor) => $checkService->reemitDeferredDownAlert($monitor));
    }
}
