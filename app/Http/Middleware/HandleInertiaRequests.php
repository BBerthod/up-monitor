<?php

namespace App\Http\Middleware;

use App\Enums\InsightDomain;
use App\Models\Site;
use App\Services\TriageService;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Cache key for the navSites payload of a given team.
     * Mirrors the triage:counts pattern so invalidation is predictable.
     */
    public static function navSitesCacheKey(int $teamId): string
    {
        return "nav:sites:{$teamId}";
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => fn () => $this->getAuthData(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'message' => fn () => $request->session()->get('message'),
                'newToken' => fn () => $request->session()->get('newToken'),
                'link' => fn () => $request->session()->get('link'),
                'linkText' => fn () => $request->session()->get('linkText'),
            ],
            // Triage badge counts + domain metadata.
            // Lazy closure: only executed on Inertia requests for authenticated users.
            'triage' => fn () => $this->getTriageData(),
            // Lightweight site list for the topbar site-switcher.
            // Lazy closure: only resolved on authenticated Inertia requests.
            'navSites' => fn () => $this->getNavSites(),
            // Active site-scope lens — resolved by ResolveSiteScope middleware before
            // this middleware runs.  Shape: {mode: 'all'|'site'|'unassigned', site: {id,name,domain}|null}
            'siteScope' => fn () => $this->getSiteScope(),
        ]);
    }

    private function getAuthData(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return ['user' => null, 'team' => null];
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_admin' => $user->isAdmin(),
            ],
            'team' => $user->team ? [
                'id' => $user->team->id,
                'name' => $user->team->name,
            ] : null,
        ];
    }

    /**
     * Build the siteScope payload from the SiteScope instance bound by ResolveSiteScope.
     *
     * Guests (unauthenticated) receive null — the frontend hides the scope indicator.
     *
     * @return array{mode: string, site: array{id: int, name: string, domain: string}|null}|null
     */
    private function getSiteScope(): ?array
    {
        if (auth()->guest()) {
            return null;
        }

        // ResolveSiteScope binds the instance before this middleware runs.
        // If for any reason the binding is absent (e.g. test without middleware stack),
        // fall back to a safe default.
        if (! app()->bound(SiteScope::class)) {
            return SiteScope::all()->toInertia();
        }

        return app(SiteScope::class)->toInertia();
    }

    /**
     * Build the navSites payload: a lightweight list of sites belonging to the
     * authenticated user's team, suitable for a topbar site-switcher.
     *
     * Each entry: { id, name, domain }
     *   - name  = alias when set, else the resolved primary domain
     *   - domain = resolvedPrimaryDomain()
     *
     * Cached in Redis for 60 seconds per team.  Cache is invalidated in
     * Site::booted() on created/updated/deleted events, so the list stays
     * fresh within one minute of any site change.
     *
     * Guest users (or users without a team) receive null so the frontend can
     * conditionally hide the site-switcher.
     *
     * @return list<array{id: int, name: string, domain: string}>|null
     */
    private function getNavSites(): ?array
    {
        $user = auth()->user();

        if ($user === null || $user->team === null) {
            return null;
        }

        $teamId = $user->team->id;

        return Cache::remember(
            self::navSitesCacheKey($teamId),
            60,
            function () use ($teamId): array {
                return Site::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->orderBy('alias')
                    ->get(['id', 'alias', 'primary_domain', 'domains', 'locales', 'primary_locale'])
                    ->map(function (Site $site): array {
                        $domain = $site->resolvedPrimaryDomain();

                        return [
                            'id' => $site->id,
                            'name' => $site->alias ?: $domain,
                            'domain' => $domain,
                        ];
                    })
                    ->sortBy('name')
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * Build the shared triage payload.
     *
     * Guest users get null so the frontend can hide badge UI entirely.
     *
     * Authenticated users get counts (30 s Redis cache) plus a static domain
     * metadata map so the frontend can render domain labels without a round-trip.
     *
     * The domain metadata is intentionally included here (not as a separate
     * endpoint) because it's trivially small (~4 entries) and avoids an extra
     * request that would be needed on every page. If the domain list ever grows
     * significantly, move it to a one-time JS constant in the frontend instead.
     *
     * @return array<string, mixed>|null
     */
    private function getTriageData(): ?array
    {
        $user = auth()->user();

        if ($user === null || $user->team === null) {
            return null;
        }

        $team = $user->team;

        $counts = Cache::remember(
            TriageService::cacheKey($team->id),
            30,
            fn () => app(TriageService::class)->counts($team),
        );

        // Static domain metadata — label map for the frontend.
        // Built once per request from enum cases; cheap enough to inline here.
        $domainsMeta = [];
        foreach (InsightDomain::cases() as $domain) {
            $domainsMeta[$domain->value] = $domain->label();
        }

        return [
            'counts' => $counts,
            'domains_meta' => $domainsMeta,
        ];
    }
}
