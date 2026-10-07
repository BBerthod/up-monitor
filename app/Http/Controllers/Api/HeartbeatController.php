<?php

namespace App\Http\Controllers\Api;

use App\Enums\InsightType;
use App\Http\Controllers\Controller;
use App\Models\Heartbeat;
use App\Models\Insight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives pings from scheduled tasks running outside Up.
 *
 * WHY THE TOKEN MAY TRAVEL IN THE PATH
 * ────────────────────────────────────
 * The Bearer header is the better form and is supported first. But the callers
 * here are crontab lines and wp-cron hooks, where the realistic integration is
 * a single trailing command:
 *
 *   0 3 * * * /usr/local/bin/rebuild.sh && curl -fsS https://up.example/api/heartbeat/TOKEN
 *
 * Refusing that shape would mean the feature goes unused on exactly the tasks
 * it exists for. The same trade-off is already accepted for event ingestion.
 * The token is single-purpose — it can record a ping and nothing else — so the
 * exposure is bounded to a forged all-clear, which the operator would notice
 * as a task that reports success while producing no work.
 */
class HeartbeatController extends Controller
{
    /**
     * Record a ping and clear any open alert for this heartbeat.
     */
    public function ping(Request $request, ?string $token = null): JsonResponse
    {
        // Header first, path as the documented fallback. Auth is resolved before
        // anything else so an unknown token always yields 401 rather than
        // leaking whether the endpoint expects a body.
        $plainToken = $request->bearerToken() ?? $token;

        if ($plainToken === null || $plainToken === '') {
            return response()->json(['error' => 'Missing token'], 401);
        }

        $heartbeat = Heartbeat::findByToken($plainToken);

        if ($heartbeat === null) {
            return response()->json(['error' => 'Invalid or inactive heartbeat token'], 401);
        }

        $wasOverdue = $heartbeat->isOverdue();
        $wasAlerted = $heartbeat->alerted_at !== null;

        $heartbeat->forceFill([
            'last_ping_at' => now(),
            // Clearing the alert stamp here is what arms the next outage: while
            // it is set, the sweep stays quiet, so leaving it would silence the
            // heartbeat permanently after its first failure.
            'alerted_at' => null,
        ])->save();

        // Recovery: close the open insight so the inbox reflects reality without
        // waiting for someone to acknowledge a resolved problem by hand.
        if ($wasAlerted) {
            $resolved = Insight::withoutGlobalScopes()
                ->where('team_id', $heartbeat->team_id)
                ->where('type', InsightType::HEARTBEAT_MISSED->value)
                ->whereNull('acknowledged_at')
                ->where('payload->heartbeat_id', $heartbeat->id)
                ->update(['acknowledged_at' => now()]);

            Log::info('HeartbeatController: heartbeat recovered', [
                'heartbeat_id' => $heartbeat->id,
                'name' => $heartbeat->name,
                'insights_resolved' => $resolved,
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'name' => $heartbeat->name,
            'recovered' => $wasOverdue || $wasAlerted,
            'next_due_at' => $heartbeat->fresh()->dueAt()?->toIso8601String(),
        ]);
    }
}
