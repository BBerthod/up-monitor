<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckServerHeartbeatCommand extends Command
{
    protected $signature = 'servers:heartbeat-check
                            {--stale-minutes=15 : Minutes without a metric before declaring a server silent}';

    protected $description = 'Alert when a monitored server has stopped reporting metrics (agent may be down).';

    public function handle(): int
    {
        $thresholdMinutes = max(10, (int) $this->option('stale-minutes'));

        $servers = Server::withoutGlobalScopes()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Server $s): bool => $s->hasMonitoringConfigured());

        $alerted = 0;

        foreach ($servers as $server) {
            $last = $server->latestMetric();

            if ($last === null) {
                // Never received a metric — agent not yet configured or deployed.
                Log::info('CheckServerHeartbeatCommand: server has never reported metrics, skipping', [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                ]);

                continue;
            }

            // abs(): Carbon's diffInMinutes is signed, and captured_at is in the
            // past, so the raw diff is negative — abs() gives the true age.
            $age = (int) abs(now()->diffInMinutes($last->captured_at));

            if ($age < $thresholdMinutes) {
                continue;
            }

            // Server is silent. Check anti-doublon before creating a new Insight.
            if ($this->heartbeatAlertExists($server)) {
                continue;
            }

            Log::warning('CheckServerHeartbeatCommand: server silent, creating heartbeat alert', [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'last_seen_minutes' => $age,
                'threshold_minutes' => $thresholdMinutes,
            ]);

            Insight::create([
                'team_id' => $server->team_id,
                'site' => $server->name,
                'server_id' => $server->id,
                'monitor_id' => null,
                'type' => InsightType::SERVER_HEALTH->value,
                'severity' => InsightSeverity::CRITICAL->value,
                'title' => sprintf('Server %s: no metrics for %dm (agent may be down)', $server->name, $age),
                'payload' => [
                    'metric' => 'heartbeat',
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'last_seen_minutes' => $age,
                    'sites' => $server->sites()->pluck('primary_domain')->all(),
                ],
                'impact_score' => $age,
                'detected_at' => now(),
            ]);

            $alerted++;
        }

        if ($alerted > 0) {
            $this->warn("{$alerted} server(s) declared silent and alerted.");
        } else {
            $this->info('All monitored servers are reporting metrics on time.');
        }

        return self::SUCCESS;
    }

    /**
     * True when an unacknowledged SERVER_HEALTH Insight with metric='heartbeat'
     * already exists for this server. Mirrors ServerHealthDetector::alertExists().
     *
     * withoutGlobalScopes() is required: this command runs without an auth() session
     * so the ScopedByTeam global scope would reject any query.
     */
    private function heartbeatAlertExists(Server $server): bool
    {
        return Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->whereNull('acknowledged_at')
            ->where('payload->server_id', $server->id)
            ->where('payload->metric', 'heartbeat')
            ->exists();
    }
}
