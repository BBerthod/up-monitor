<?php

namespace App\Jobs;

use App\Events\LighthouseAuditCompleted;
use App\Exceptions\GooglePSI400Exception;
use App\Exceptions\GooglePSI403Exception;
use App\Exceptions\GooglePSI429Exception;
use App\Models\Monitor;
use App\Services\LighthouseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunLighthouseAudit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public array $backoff = [1800]; // retry after 30 min on quota/rate limit errors

    public function __construct(public Monitor $monitor)
    {
        // Use redis_long connection: retry_after=700s > $timeout=180s, preventing
        // premature requeue that could cause duplicate audits.
        $this->onConnection('redis_long')->onQueue('lighthouse');
    }

    public function handle(LighthouseService $lighthouseService): void
    {
        try {
            $score = $lighthouseService->audit($this->monitor);
        } catch (GooglePSI400Exception $e) {
            // The URL is structurally unauditable (JSON endpoint, redirect
            // guard, etc.) — retrying will never succeed. End the job
            // cleanly (no exception, no retry) and stop future dispatches
            // for this monitor, since the URL won't become auditable on its own.
            Log::warning('RunLighthouseAudit: PSI 400, disabling lighthouse_enabled for monitor', [
                'monitor_id' => $this->monitor->id,
                'url' => $this->monitor->url,
                'error' => $e->getMessage(),
            ]);
            $this->monitor->update(['lighthouse_enabled' => false]);

            return;
        } catch (GooglePSI403Exception $e) {
            // Key invalid/revoked — release back to queue with a 1-hour delay
            // to give an admin time to rotate the key in config.
            Log::critical('RunLighthouseAudit: PSI key 403, releasing with 1h delay', [
                'monitor_id' => $this->monitor->id,
                'error' => $e->getMessage(),
            ]);
            $this->release(3600);

            return;
        } catch (GooglePSI429Exception $e) {
            // Quota exhausted — release back to queue with a 30-minute delay.
            Log::warning('RunLighthouseAudit: PSI quota 429, releasing with 30 min delay', [
                'monitor_id' => $this->monitor->id,
                'error' => $e->getMessage(),
            ]);
            $this->release(1800);

            return;
        }

        LighthouseAuditCompleted::dispatch($this->monitor, $score);
    }

    public function failed(Throwable $e): void
    {
        Log::error('RunLighthouseAudit job failed', [
            'monitor_id' => $this->monitor->id,
            'error' => $e->getMessage(),
        ]);
    }
}
