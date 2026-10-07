<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Adds a global team scope to child models that do not have a direct team_id column.
 * Isolation is achieved by filtering through the monitor relationship:
 *   WHERE EXISTS (SELECT 1 FROM monitors WHERE monitors.id = {table}.monitor_id AND monitors.team_id = ?)
 *
 * Safe in queue/job context: when no authenticated user is present the scope is skipped,
 * because jobs operate on a single monitor's data they already fetched by ID.
 */
trait ScopedByMonitorTeam
{
    protected static function bootScopedByMonitorTeam(): void
    {
        static::addGlobalScope('team_via_monitor', function (Builder $builder): void {
            if (! auth()->check() || auth()->user()->team_id === null) {
                return;
            }

            $teamId = auth()->user()->team_id;

            $builder->whereHas('monitor', function (Builder $q) use ($teamId): void {
                $q->where('monitors.team_id', $teamId);
            });
        });
    }
}
