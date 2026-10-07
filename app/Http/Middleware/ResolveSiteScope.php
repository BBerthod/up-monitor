<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\SiteScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active site-scope lens from the `site` query parameter and
 * persists the choice in the session under the `site_scope` key.
 *
 * Rules:
 *   - `site=all`          → clear the scope (show everything)
 *   - `site=unassigned`   → show monitors / resources not linked to any site
 *   - `site={id}` (int)   → scope to that specific site
 *
 * Security (anti-IDOR):
 *   If the given ID does not belong to the authenticated user's team we silently
 *   ignore the value and clear the scope, instead of returning a 403. A 403 would
 *   confirm that the ID is a valid site on another team.
 *
 * The resolved scope is bound in the container as a singleton so that controllers
 * can retrieve it via `app(SiteScope::class)` or via dependency injection.
 */
class ResolveSiteScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only run for authenticated users that belong to a team.
        if ($user === null || $user->team_id === null) {
            app()->instance(SiteScope::class, SiteScope::all());

            return $next($request);
        }

        $siteParam = $request->query('site');

        if ($siteParam !== null) {
            $scope = $this->resolveFromParam((string) $siteParam, $user->team_id);
            $request->session()->put('site_scope', $scope->toSession());
        } else {
            // No query param — restore from session (or default to all).
            $sessionData = $request->session()->get('site_scope');
            $scope = $sessionData !== null
                ? SiteScope::fromSession($sessionData)
                : SiteScope::all();
        }

        app()->instance(SiteScope::class, $scope);

        return $next($request);
    }

    /**
     * Translate the raw `site` query-param value into a SiteScope.
     *
     * Unknown site IDs (not owned by the team) silently fall back to `all`.
     */
    private function resolveFromParam(string $param, int $teamId): SiteScope
    {
        if ($param === 'all') {
            return SiteScope::all();
        }

        if ($param === 'unassigned') {
            return SiteScope::unassigned();
        }

        // Numeric → look up the site, but only within the authenticated team.
        if (ctype_digit($param)) {
            $site = Site::withoutGlobalScopes()
                ->where('id', (int) $param)
                ->where('team_id', $teamId)
                ->first(['id', 'alias', 'primary_domain', 'domains', 'locales', 'primary_locale']);

            if ($site !== null) {
                return SiteScope::forSite($site);
            }
        }

        // Unknown / wrong-team: silently clear the scope (anti-IDOR).
        return SiteScope::all();
    }
}
