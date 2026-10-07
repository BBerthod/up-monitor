<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Adds a global team scope to models that have a direct team_id column.
 * Restricts all queries to the authenticated user's team.
 *
 * Safe in queue/job context: when no authenticated user is present the scope is skipped,
 * because jobs filter explicitly by team_id on the data they operate on.
 */
trait ScopedByTeam
{
    protected static function bootScopedByTeam(): void
    {
        static::addGlobalScope('team', function (Builder $builder): void {
            if (! auth()->check() || auth()->user()->team_id === null) {
                return;
            }

            $builder->where('team_id', auth()->user()->team_id);
        });
    }
}
