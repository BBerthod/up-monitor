<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Collects the most recent server metrics point from the Dokploy API.
 *
 * WHY this service exists: each Server row stores Dokploy monitoring agent
 * credentials (metrics_url + metrics_token).  This service bridges those
 * credentials with the Dokploy `server.getServerMetrics` endpoint and maps
 * the raw payload onto a ServerMetric row.
 *
 * API notes:
 *   - Authentication uses the `x-api-key` header (NOT `Authorization: Bearer`).
 *     This is the GLOBAL Dokploy API token, not the per-server monitoring token.
 *   - The per-server monitoring credentials (metrics_url + metrics_token) are
 *     passed as query parameters — they are the agent's credentials, separate
 *     from the Dokploy API key.
 *   - `dataPoints=1` returns an array with the single most recent point; we take
 *     the LAST element of the array as the current snapshot.
 *   - All numeric fields in the response are ALREADY calculated percentages/values
 *     (cpu = %, memUsed = %, diskUsed = %).  Do NOT divide further.
 *
 * This service NEVER throws — it returns null on any failure and logs context so
 * callers can degrade gracefully.
 */
class ServerMetricsCollector
{
    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $apiToken = '',
    ) {
        // Allow constructor injection or fall back to config at call time.
        // We read config lazily in collect() so tests can override env.
    }

    /**
     * Collect and persist the most recent metric point for a server.
     *
     * Returns the created ServerMetric row, or null when:
     *   - Monitoring is not configured on the server (graceful skip).
     *   - The Dokploy API token is not set (local dev without Dokploy).
     *   - The API call fails for any reason.
     *   - The response payload is invalid or missing required fields.
     */
    public function collect(Server $server): ?ServerMetric
    {
        // Graceful skip when this server has no Dokploy pull credentials.
        // Push-only servers (ingest token, self-hosted agent) report via the
        // /api/servers/metrics endpoint instead — not through this collector.
        if (! $server->hasDokployMonitoring()) {
            Log::debug('ServerMetricsCollector: Dokploy monitoring not configured, skipping', [
                'server_id' => $server->id,
                'server_name' => $server->name,
            ]);

            return null;
        }

        $token = $this->apiToken ?: config('services.dokploy.api_token');
        $baseUrl = $this->baseUrl ?: config('services.dokploy.base_url', 'https://dokploy.example.com');

        // No global Dokploy API token → silently degrade (common in local dev).
        if (empty($token)) {
            Log::debug('ServerMetricsCollector: no api_token configured, skipping', [
                'server_id' => $server->id,
            ]);

            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['x-api-key' => $token])
                ->get("{$baseUrl}/api/server.getServerMetrics", [
                    'url' => $server->metrics_url,
                    'token' => $server->metrics_token,
                    'dataPoints' => '1',
                ]);

            if (! $response->successful()) {
                Log::warning('ServerMetricsCollector: API request failed', [
                    'server_id' => $server->id,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $payload = $response->json();

            if (! is_array($payload) || empty($payload)) {
                Log::warning('ServerMetricsCollector: empty or invalid payload', [
                    'server_id' => $server->id,
                ]);

                return null;
            }

            // The most recent data point is the LAST element of the array.
            $point = end($payload);

            if (! is_array($point)) {
                Log::warning('ServerMetricsCollector: data point is not an array', [
                    'server_id' => $server->id,
                ]);

                return null;
            }

            $attributes = $this->mapPoint($point, $server->id);

            if ($attributes === null) {
                return null;
            }

            return ServerMetric::create($attributes);
        } catch (\Throwable $e) {
            Log::error('ServerMetricsCollector: exception during collection', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Map a raw Dokploy metrics data point onto ServerMetric column values.
     *
     * Returns null (+ logs a warning) if any required field is absent or
     * non-numeric — we never persist a partially invalid row.
     *
     * Field mapping (EXACT):
     *   cpu_percent   = (float) data['cpu']              — already a % (0-100)
     *   ram_percent   = (float) data['memUsed']          — already a % (0-100)
     *   ram_used_mb   = (int)   data['memUsedGB'] × 1024
     *   ram_total_mb  = (int)   data['memTotal']  × 1024
     *   disk_percent  = (float) data['diskUsed']         — already a % (0-100)
     *   disk_total_gb = (int)   data['totalDisk']
     *   disk_used_gb  = (int)   data['totalDisk'] × data['diskUsed'] / 100
     *   captured_at   = parse data['timestamp'] if present and valid, else now()
     *
     * @param  array<mixed>  $point
     * @return array<string, mixed>|null
     */
    private function mapPoint(array $point, int $serverId): ?array
    {
        // Validate required numeric fields.
        foreach (['cpu', 'memUsed', 'diskUsed'] as $required) {
            if (! isset($point[$required]) || ! is_numeric($point[$required])) {
                Log::warning('ServerMetricsCollector: required field missing or non-numeric, skipping point', [
                    'server_id' => $serverId,
                    'missing' => $required,
                ]);

                return null;
            }
        }

        $cpu = (float) $point['cpu'];
        $memUsed = (float) $point['memUsed'];
        $memUsedGb = is_numeric($point['memUsedGB'] ?? null) ? (float) $point['memUsedGB'] : 0.0;
        $memTotal = is_numeric($point['memTotal'] ?? null) ? (float) $point['memTotal'] : 0.0;
        $diskUsed = (float) $point['diskUsed'];
        $totalDisk = is_numeric($point['totalDisk'] ?? null) ? (float) $point['totalDisk'] : 0.0;

        // Resolve captured_at: use the API timestamp when present and parseable,
        // otherwise fall back to now() so the row always has a valid timestamp.
        $capturedAt = now();

        if (! empty($point['timestamp'])) {
            try {
                $parsed = Carbon::parse($point['timestamp']);
                // Sanity-check: accept only timestamps within a 24-hour window.
                // abs() guards against a future/garbage timestamp yielding a
                // negative diff that would slip past a bare "<= 24" check.
                if (abs($parsed->diffInHours(now())) <= 24) {
                    $capturedAt = $parsed;
                }
            } catch (\Throwable) {
                // Unparseable timestamp → keep now().
            }
        }

        return [
            'server_id' => $serverId,
            'cpu_percent' => $cpu,
            'ram_percent' => $memUsed,
            'ram_used_mb' => (int) round($memUsedGb * 1024),
            'ram_total_mb' => (int) round($memTotal * 1024),
            'disk_percent' => $diskUsed,
            'disk_total_gb' => (int) round($totalDisk),
            'disk_used_gb' => (int) round($totalDisk * $diskUsed / 100),
            'captured_at' => $capturedAt,
            'created_at' => now(),
        ];
    }
}
