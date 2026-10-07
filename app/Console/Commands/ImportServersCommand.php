<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportServersCommand extends Command
{
    protected $signature = 'servers:import
                            {--team=1 : Team ID to assign servers to}';

    protected $description = 'Import or update Server records from the Dokploy API (server.all).';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        $team = Team::find($teamId);

        if (! $team) {
            $this->error("Team ID {$teamId} not found.");

            return self::FAILURE;
        }

        $token = config('services.dokploy.api_token');
        $baseUrl = config('services.dokploy.base_url', 'https://dokploy.example.com');

        if (empty($token)) {
            $this->error('Dokploy API token is not configured (services.dokploy.api_token).');

            return self::FAILURE;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['x-api-key' => $token])
                ->get("{$baseUrl}/api/server.all");

            if (! $response->successful()) {
                $this->error("Dokploy API request failed (HTTP {$response->status()}).");

                Log::error('servers:import: server.all request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return self::FAILURE;
            }

            $servers = $response->json();

            if (! is_array($servers)) {
                $this->error('Unexpected response format from Dokploy (expected a JSON array).');

                return self::FAILURE;
            }

            // On single-server Dokploy installations the main server is not listed
            // as a "remote server" in server.all — the list will be empty.
            // This is expected behaviour, not an error.
            if (empty($servers)) {
                $this->warn('Dokploy returned an empty server list.');
                $this->warn('On single-server installations, the primary Dokploy host is not listed');
                $this->warn('as a remote server.  To monitor it:');
                $this->warn('  1. Open Dokploy → Settings → Monitoring.');
                $this->warn('  2. Copy the metrics URL and token.');
                $this->warn('  3. Create the Server record manually and fill in metrics_url / metrics_token.');

                return self::SUCCESS;
            }

            $imported = 0;
            $updated = 0;
            $tableRows = [];

            foreach ($servers as $s) {
                $dokployServerId = $s['serverId'] ?? $s['id'] ?? null;

                if ($dokployServerId === null) {
                    $this->warn('Skipping a server entry with no serverId.');

                    continue;
                }

                $dokployServerId = (string) $dokployServerId;
                $name = (string) ($s['name'] ?? $dokployServerId);

                // metrics_url / metrics_token are NOT provided by server.all.
                // They must be filled in manually from Dokploy Settings → Monitoring.
                // We intentionally exclude them from the upsert so that any values
                // the user has already entered are never overwritten.
                $wasRecentlyCreated = false;

                $server = Server::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('dokploy_server_id', $dokployServerId)
                    ->first();

                if ($server) {
                    $server->update(['name' => $name]);
                } else {
                    Server::create([
                        'team_id' => $team->id,
                        'dokploy_server_id' => $dokployServerId,
                        'name' => $name,
                        'is_active' => true,
                    ]);
                    $wasRecentlyCreated = true;
                }

                if ($wasRecentlyCreated) {
                    $imported++;
                    $status = 'imported';
                } else {
                    $updated++;
                    $status = 'updated';
                }

                $tableRows[] = [$dokployServerId, $name, $status];
            }

            $this->table(['Dokploy Server ID', 'Name', 'Status'], $tableRows);
            $this->info("{$imported} server(s) imported, {$updated} updated.");

            if ($imported > 0) {
                $this->warn('Remember to set metrics_url and metrics_token for newly imported servers');
                $this->warn('(Dokploy → Settings → Monitoring → copy URL and token).');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('servers:import: unexpected exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
