<?php

namespace App\Http\Traits;

use App\Support\SiteScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared helper for controllers that need to filter their list queries by the
 * active site-scope lens.
 *
 * Usage:
 *
 *   use FiltersBySiteScope;
 *
 *   $query = Monitor::query();
 *   $this->applySiteScope($query);   // default column: site_id
 *
 *   // For join-based queries (site_id lives on a joined table):
 *   $this->applySiteScope($query, 'monitors.site_id');
 *
 * Behaviour by mode:
 *   - all        → no WHERE clause added
 *   - site       → WHERE {column} = {site_id}
 *   - unassigned → WHERE {column} IS NULL
 */
trait FiltersBySiteScope
{
    /**
     * Apply the active site-scope lens to an Eloquent query builder.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    protected function applySiteScope(Builder $query, string $column = 'site_id'): void
    {
        if (! app()->bound(SiteScope::class)) {
            return;
        }

        $scope = app(SiteScope::class);

        if ($scope->isSite() && $scope->site !== null) {
            $query->where($column, $scope->site->id);

            return;
        }

        if ($scope->isUnassigned()) {
            $query->whereNull($column);
        }

        // MODE_ALL: no filter — fall through.
    }

    /**
     * Convenience accessor so a controller can read the active SiteScope directly.
     */
    protected function currentSiteScope(): SiteScope
    {
        if (app()->bound(SiteScope::class)) {
            return app(SiteScope::class);
        }

        return SiteScope::all();
    }
}
