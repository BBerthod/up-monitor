<?php

namespace App\Http\Controllers\Api;

use App\Events\ServerMetricReceived;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\ServerHealthDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ServerMetricController extends Controller
{
    /**
     * Receive a single server-metric payload pushed by a self-hosted shell agent.
     *
     * Authentication: Authorization: Bearer <plain-token>
     * The plain token is hashed (SHA-256) and looked up against ingest_token_hash.
     * The plain value never touches the database or the application logs.
     */
    public function store(Request $request, ServerHealthDetector $detector): JsonResponse
    {
        // ── 1. Authenticate via Bearer token ─────────────────────────────────
        // Auth is checked BEFORE validation so an unauthenticated caller always
        // gets a 401 — never a 422 that would leak which fields the body expects.

        $bearer = $request->bearerToken();

        if ($bearer === null) {
            return response()->json(['error' => 'Missing token'], 401);
        }

        $server = Server::withoutGlobalScopes()
            ->where('ingest_token_hash', hash('sha256', $bearer))
            ->where('is_active', true)
            ->first();

        if ($server === null) {
            return response()->json(['error' => 'Invalid or inactive server token'], 401);
        }

        // ── 2. Validate (a failure here returns 422, not 500) ─────────────────

        $validated = $request->validate([
            'cpu_percent' => ['required', 'numeric', 'between:0,100'],
            'ram_percent' => ['required', 'numeric', 'between:0,100'],
            'disk_percent' => ['required', 'numeric', 'between:0,100'],
            'ram_used_mb' => ['nullable', 'integer', 'min:0'],
            'ram_total_mb' => ['nullable', 'integer', 'min:0'],
            'disk_used_gb' => ['nullable', 'integer', 'min:0'],
            'disk_total_gb' => ['nullable', 'integer', 'min:0'],
            // System load averages (1/5/15 min). Optional — older agents omit them.
            'load_avg_1' => ['nullable', 'numeric', 'min:0'],
            'load_avg_5' => ['nullable', 'numeric', 'min:0'],
            'load_avg_15' => ['nullable', 'numeric', 'min:0'],
        ]);

        // ── 3. De-duplicate retries ───────────────────────────────────────────
        // The agent uses `curl --retry`, so a network blip after the row is
        // committed but before the 201 lands replays the POST. Without this guard
        // each replay inserts a near-identical row, which would poison the
        // sustained-CPU window. If the last metric for this server is younger than
        // the dedup window, treat the request as a duplicate and ack it (200).
        $last = $server->latestMetric();
        if ($last !== null && $last->captured_at->diffInSeconds(now()) < self::DEDUP_WINDOW_SECONDS) {
            return response()->json(['id' => $last->id, 'deduplicated' => true], 200);
        }

        // ── 4. Persist the metric ─────────────────────────────────────────────

        try {
            /** @var ServerMetric $metric */
            $metric = ServerMetric::create([
                'server_id' => $server->id,
                'cpu_percent' => $validated['cpu_percent'],
                'ram_percent' => $validated['ram_percent'],
                'disk_percent' => $validated['disk_percent'],
                'ram_used_mb' => $validated['ram_used_mb'] ?? null,
                'ram_total_mb' => $validated['ram_total_mb'] ?? null,
                'disk_used_gb' => $validated['disk_used_gb'] ?? null,
                'disk_total_gb' => $validated['disk_total_gb'] ?? null,
                'load_avg_1' => $validated['load_avg_1'] ?? null,
                'load_avg_5' => $validated['load_avg_5'] ?? null,
                'load_avg_15' => $validated['load_avg_15'] ?? null,
                'captured_at' => now(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('ServerMetricController: failed to store metric', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to store metric'], 500);
        }

        // ── 5. Evaluate thresholds — never fail the request on a detector error.
        // The metric is already persisted and acknowledged; a detector exception
        // must not turn into a 500 that makes the agent retry (and duplicate).
        try {
            $detector->evaluate($server, $metric);
        } catch (Throwable $e) {
            Log::error('ServerMetricController: threshold evaluation failed', [
                'server_id' => $server->id,
                'metric_id' => $metric->id,
                'error' => $e->getMessage(),
            ]);
        }

        // ── 6. Broadcast — a failure here must never block the 201 response.
        // The metric is already persisted; dropping a WebSocket push is acceptable.
        try {
            ServerMetricReceived::dispatch($server, $metric);
        } catch (Throwable $e) {
            Log::warning('ServerMetricController: broadcast failed', [
                'server_id' => $server->id,
                'metric_id' => $metric->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['id' => $metric->id], 201);
    }

    /**
     * Metrics arriving within this many seconds of the previous one are treated as
     * retry duplicates. Comfortably below the 5-minute collection cadence, above any
     * realistic retry delay.
     */
    private const DEDUP_WINDOW_SECONDS = 60;
}
