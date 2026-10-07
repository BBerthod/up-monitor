<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\ServerHealthDetector;
use App\Services\ServerMetricsCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatcher job: collects CPU/RAM/disk metrics from the Dokploy monitoring API
 * for every active server and runs the health detector after each collection.
 *
 * Collection strategy
 * ───────────────────
 * Iterates every active Server row (withoutGlobalScopes to bypass ScopedByTeam
 * which is unavailable in job context) and calls:
 *   1. ServerMetricsCollector::collect($server) → persists a ServerMetric row.
 *   2. ServerHealthDetector::evaluate($server, $metric) → creates SERVER_HEALTH
 *      Insight rows when thresholds are breached.
 *
 * Per-server isolation: an exception on one server is logged and swallowed so
 * the remaining servers are always processed.
 *
 * Scheduled every 5 minutes (see routes/console.php).
 */
class DispatchServerMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('monitors');
    }

    public function handle(ServerMetricsCollector $collector, ServerHealthDetector $detector): void
    {
        // withoutGlobalScopes() is required: no auth() context in jobs means
        // ScopedByTeam would otherwise return zero rows.
        Server::withoutGlobalScopes()
            ->where('is_active', true)
            ->cursor()
            ->each(function (Server $server) use ($collector, $detector): void {
                try {
                    $metric = $collector->collect($server);

                    if ($metric === null) {
                        // Collector already logged the reason (not configured,
                        // no API token, API failure, etc.). Skip gracefully.
                        return;
                    }

                    $detector->evaluate($server, $metric);
                } catch (Throwable $e) {
                    Log::error('Server metrics collection failed for server', [
                        'server_id' => $server->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Do not re-throw — continue with the next server.
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
