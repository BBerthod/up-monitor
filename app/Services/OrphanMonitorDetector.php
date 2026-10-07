<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;

/**
 * Detects active HTTP monitors that are not linked to any Site (site_id IS NULL)
 * and creates or maintains a single INFO-level Insight per team to surface them.
 *
 * Design goals:
 *   - One Insight per team at most.  updateOrCreate on a deterministic title keeps
 *     the count accurate without accumulating rows.
 *   - Auto-resolves (acknowledges) the Insight when all monitors have been assigned.
 *   - Idempotent: safe to call repeatedly without side effects.
 *
 * Fitting InsightType:
 *   HEALTH_DROP is the closest semantically (structural health of the monitoring
 *   setup) and doesn't require a new enum case.  The title makes the meaning clear.
 */
class OrphanMonitorDetector
{
    /**
     * Evaluate orphan monitors for every team and maintain Insights accordingly.
     *
     * Returns the total count of orphan monitors found across all teams.
     */
    public function evaluateAll(): int
    {
        $total = 0;

        Team::cursor()->each(function (Team $team) use (&$total): void {
            $total += $this->evaluateTeam($team);
        });

        return $total;
    }

    /**
     * Evaluate orphan monitors for a single team.
     *
     * @return int Number of orphan monitors found (0 means healthy).
     */
    public function evaluateTeam(Team $team): int
    {
        $orphanIds = Monitor::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->whereNull('site_id')
            ->pluck('id')
            ->all();

        $count = count($orphanIds);
        $marker = $this->insightMarker($team->id);

        if ($count === 0) {
            // All monitors are assigned — auto-resolve any open Insight.
            $this->resolveInsight($team, $marker);

            return 0;
        }

        // Create or update the Insight.  We match on the unique marker stored in
        // the payload so we never create duplicate rows across multiple runs.
        $existing = Insight::withoutGlobalScopes()
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->where('payload->marker', $marker)
            ->first();

        if ($existing !== null) {
            // Update the count and the monitor list if it changed.
            if ($existing->payload['monitor_count'] !== $count) {
                $existing->update([
                    'title' => $this->buildTitle($count),
                    'payload' => $this->buildPayload($orphanIds, $marker),
                ]);
            }
        } else {
            Insight::create([
                'team_id' => $team->id,
                // Legacy string column is NOT NULL — 'portfolio' is the existing
                // convention for team-level insights (see WhatChangedService).
                'site' => 'portfolio',
                'site_id' => null,
                'server_id' => null,
                'monitor_id' => null,
                'type' => InsightType::HEALTH_DROP->value,
                'severity' => InsightSeverity::INFO->value,
                'title' => $this->buildTitle($count),
                'payload' => $this->buildPayload($orphanIds, $marker),
                'impact_score' => 0.0,
                'detected_at' => now(),
            ]);
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildTitle(int $count): string
    {
        return $count === 1
            ? '1 monitor not linked to any site'
            : "{$count} monitors not linked to any site";
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array<string, mixed>
     */
    private function buildPayload(array $monitorIds, string $marker): array
    {
        return [
            'marker' => $marker,
            'monitor_ids' => $monitorIds,
            'monitor_count' => count($monitorIds),
        ];
    }

    /**
     * Deterministic string that uniquely identifies the "orphan monitors" Insight
     * for a given team.  Used as a deduplication key in the payload.
     */
    private function insightMarker(int $teamId): string
    {
        return "orphan_monitors:team:{$teamId}";
    }

    /**
     * Acknowledge all open orphan-monitor Insights for this team (auto-resolve).
     */
    private function resolveInsight(Team $team, string $marker): void
    {
        $affected = Insight::withoutGlobalScopes()
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->where('payload->marker', $marker)
            ->update(['acknowledged_at' => now()]);

        if ($affected > 0) {
            Cache::forget(TriageService::cacheKey($team->id));
        }
    }
}
