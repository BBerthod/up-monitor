<?php

namespace App\Listeners;

use App\Enums\InsightType;
use App\Events\IncidentResolved;
use App\Models\Insight;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Auto-resolves the inbox projection of an uptime incident: when the incident is
 * resolved, the matching unacknowledged UPTIME_INCIDENT insight is acknowledged
 * (the same mechanism server-health auto-resolution uses).
 *
 * Queued on `monitors`, try/catch-guarded so it never breaks the recovery path.
 */
class ResolveUptimeInsight implements ShouldQueue
{
    public string $queue = 'monitors';

    public int $tries = 3;

    public array $backoff = [30, 60];

    public function handle(IncidentResolved $event): void
    {
        $incident = $event->incident;

        // Functional-check incidents now get a mirrored UPTIME_INCIDENT insight too
        // (see CreateUptimeInsight) — resolve it the same way, or it would sit open on
        // the Vikunja board forever after the check recovers.
        try {
            // Acknowledge the open uptime insight for this monitor. Prefer the one
            // whose payload references this exact incident; fall back to any open one.
            $query = Insight::withoutGlobalScopes()
                ->where('monitor_id', $incident->monitor_id)
                ->where('type', InsightType::UPTIME_INCIDENT->value)
                ->whereNull('acknowledged_at');

            $matched = (clone $query)
                ->where('payload->incident_id', $incident->id)
                ->first();

            $insight = $matched ?? $query->orderByDesc('detected_at')->first();

            if ($insight === null) {
                return;
            }

            $insight->acknowledge();
        } catch (Throwable $e) {
            Log::error('ResolveUptimeInsight: failed to auto-resolve inbox insight', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
