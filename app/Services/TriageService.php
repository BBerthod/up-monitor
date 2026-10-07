<?php

namespace App\Services;

use App\Enums\InsightDomain;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Feeds the triage inbox: badges, encarts, and the filtered insight list.
 *
 * DESIGN NOTES
 * ─────────────────────────────────────────────────────────────────────────────
 * • No auth() dependency — used from both web requests (HandleInertiaRequests)
 *   and CLI/job context. team_id always supplied explicitly; queries call
 *   withoutGlobalScopes() so ScopedByTeam never blocks them.
 *
 * • open() returns a Builder to keep queries composable. Callers can chain
 *   additional where/orderBy clauses without loading all rows prematurely.
 *
 * • counts() uses a single SQL GROUP BY to avoid N+1 badge queries on every
 *   Inertia page load. INFO and OPPORTUNITY are excluded — they are
 *   opportunities surfaced by the copilot, not triage items.
 *
 * • topItems() eager-loads site, server, monitor to avoid N+1 when the caller
 *   renders a preview card (e.g. the dashboard encart).
 */
class TriageService
{
    /**
     * Severities that count towards triage badges.
     * INFO and OPPORTUNITY are copilot signals, not action items.
     */
    private const BADGE_SEVERITIES = [
        InsightSeverity::WARNING->value,
        InsightSeverity::CRITICAL->value,
    ];

    // ──────────────────────────────────────────────────────────
    // Core query builder
    // ──────────────────────────────────────────────────────────

    /**
     * Base query for open insights of a team: unacknowledged, not snoozed,
     * ordered by impact_score descending.
     *
     * withoutGlobalScopes() is mandatory — this service is called from contexts
     * where auth() may be null (Inertia middleware resolves user explicitly).
     */
    public function open(Team $team): Builder
    {
        return Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->unacknowledged()
            ->notSnoozed()
            ->orderByDesc('impact_score');
    }

    // ──────────────────────────────────────────────────────────
    // Chainable filters
    // ──────────────────────────────────────────────────────────

    /**
     * Narrow an open() builder to insights linked to a specific site.
     *
     * @param  Builder  $query  Must be an open() builder (team already scoped).
     */
    public function forSite(Builder $query, Site|int $site): Builder
    {
        $id = $site instanceof Site ? $site->id : $site;

        return $query->where('site_id', $id);
    }

    /**
     * Narrow to insights linked to a specific server.
     */
    public function forServer(Builder $query, Server|int $server): Builder
    {
        $id = $server instanceof Server ? $server->id : $server;

        return $query->where('server_id', $id);
    }

    /**
     * Narrow to insights linked to a specific monitor.
     */
    public function forMonitor(Builder $query, Monitor|int $monitor): Builder
    {
        $id = $monitor instanceof Monitor ? $monitor->id : $monitor;

        return $query->where('monitor_id', $id);
    }

    /**
     * Narrow to insights belonging to a given domain.
     *
     * The mapping from domain → types is authoritative on InsightDomain
     * (domain owns what belongs to it). If no types exist for the domain
     * the query returns an empty set via whereIn on an empty array.
     */
    public function forDomain(Builder $query, InsightDomain $domain): Builder
    {
        $types = InsightDomain::typesForDomain($domain);

        return $query->whereIn('type', $types);
    }

    // ──────────────────────────────────────────────────────────
    // Aggregates
    // ──────────────────────────────────────────────────────────

    /**
     * Count open triage insights for a team in ONE SQL query.
     *
     * Only WARNING and CRITICAL count — INFO/OPPORTUNITY are copilot signals.
     *
     * Returns:
     *   [
     *     'total'    => int,   // all badge-severity open insights
     *     'critical' => int,
     *     'domains'  => [
     *       'availability'    => int,
     *       'seo_business'    => int,
     *       'infrastructure'  => int,
     *       'alerting'        => int,
     *     ],
     *   ]
     *
     * @return array{total: int, critical: int, domains: array<string, int>}
     */
    public function counts(Team $team): array
    {
        // Single GROUP BY query — type + severity + count(*)
        $rows = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNull('acknowledged_at')
            ->where(fn (Builder $q) => $q
                ->whereNull('snoozed_until')
                ->orWhere('snoozed_until', '<=', now())
            )
            ->whereIn('severity', self::BADGE_SEVERITIES)
            ->selectRaw('type, severity, COUNT(*) as cnt')
            ->groupBy('type', 'severity')
            // toBase(): aggregation rows must stay raw — hydrating Insight
            // models would apply the enum casts on type/severity and break
            // the string-keyed aggregation below.
            ->toBase()
            ->get();

        // Aggregate in PHP — cheap enough on small result sets (11 types × 2
        // severities = 22 rows max).
        $total = 0;
        $critical = 0;

        // Initialise domain counters.
        $domainCounts = [];
        foreach (InsightDomain::cases() as $domain) {
            $domainCounts[$domain->value] = 0;
        }

        // Pre-build type → domain map to avoid N calls to domain() in the loop.
        $typeToDomain = [];
        foreach (InsightType::cases() as $insightType) {
            $typeToDomain[$insightType->value] = $insightType->domain()->value;
        }

        foreach ($rows as $row) {
            $count = (int) $row->cnt;
            $total += $count;

            if ($row->severity === InsightSeverity::CRITICAL->value) {
                $critical += $count;
            }

            $domainKey = $typeToDomain[$row->type] ?? null;
            if ($domainKey !== null && array_key_exists($domainKey, $domainCounts)) {
                $domainCounts[$domainKey] += $count;
            }
        }

        return [
            'total' => $total,
            'critical' => $critical,
            'domains' => $domainCounts,
        ];
    }

    /**
     * Return the N highest-impact open triage items (WARNING/CRITICAL only)
     * with their relationships eager-loaded to avoid N+1 in preview cards.
     *
     * Ordering strategy: primary sort is impact_score DESC (SQL). businessWeight()
     * is applied as a PHP tiebreaker on a small candidate pool (limit*2 rows) so
     * REVENUE_AT_RISK (weight=10) surfaces above CTR_CHANGE (weight=1) at equal
     * impact_score. Volumes are deliberately small (3-10 rows), cost is negligible.
     *
     * @return Collection<int, Insight>
     */
    public function topItems(Team $team, int $limit = 3): Collection
    {
        // Fetch limit*2 rows so the PHP tiebreaker has enough candidates.
        $candidates = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNull('acknowledged_at')
            ->where(fn (Builder $q) => $q
                ->whereNull('snoozed_until')
                ->orWhere('snoozed_until', '<=', now())
            )
            ->whereIn('severity', self::BADGE_SEVERITIES)
            ->orderByDesc('impact_score')
            ->limit($limit * 2)
            ->with(['linkedSite', 'server', 'monitor'])
            ->get();

        return $candidates
            ->sortByDesc(function (Insight $insight): float {
                $typeEnum = $insight->type instanceof InsightType
                    ? $insight->type
                    : InsightType::tryFrom((string) $insight->type);

                // impact_score (primary) + businessWeight as tiny fractional
                // tiebreaker: impact=50,weight=10 beats impact=50,weight=1
                // but never beats impact=51,weight=0.
                return (float) $insight->impact_score + ($typeEnum?->businessWeight() ?? 0) * 0.001;
            })
            ->take($limit)
            ->values();
    }

    /**
     * Count open insights per monitor, grouped by domain — ONE SQL query.
     *
     * Counts ALL open insights (not just badge-severity WARNING/CRITICAL) so
     * the portfolio view shows every actionable signal per site regardless of
     * severity. ALERTING domain is excluded — those rows have no monitor_id.
     *
     * Returns a map keyed by monitor_id (int):
     *   [
     *     42 => ['availability' => 1, 'seo_business' => 3, 'infrastructure' => 0],
     *     …
     *   ]
     *
     * Sites with zero insights across all domains are omitted from the map —
     * callers can safely use ($map[$monitorId] ?? null) with a null-check.
     *
     * @return array<int, array{availability: int, seo_business: int, infrastructure: int}>
     */
    public function countsBySite(Team $team): array
    {
        // Single GROUP BY query — monitor_id + type + count(*).
        // Rows where monitor_id IS NULL are skipped in aggregation below (they
        // can't be matched to a portfolio entry anyway).
        $rows = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNotNull('monitor_id')
            ->whereNull('acknowledged_at')
            ->where(fn (Builder $q) => $q
                ->whereNull('snoozed_until')
                ->orWhere('snoozed_until', '<=', now())
            )
            ->selectRaw('monitor_id, type, COUNT(*) as cnt')
            ->groupBy('monitor_id', 'type')
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Pre-build type → domain map (same pattern as counts()).
        $typeToDomain = [];
        foreach (InsightType::cases() as $insightType) {
            $typeToDomain[$insightType->value] = $insightType->domain()->value;
        }

        // Domains we surface per site (ALERTING has no monitor_id link).
        $tracked = [
            InsightDomain::AVAILABILITY->value => 0,
            InsightDomain::SEO_BUSINESS->value => 0,
            InsightDomain::INFRASTRUCTURE->value => 0,
        ];

        $result = [];

        foreach ($rows as $row) {
            $monitorId = (int) $row->monitor_id;
            $domainKey = $typeToDomain[$row->type] ?? null;

            if ($domainKey === null || ! array_key_exists($domainKey, $tracked)) {
                continue;
            }

            if (! isset($result[$monitorId])) {
                $result[$monitorId] = $tracked; // initialise with zeroes
            }

            $result[$monitorId][$domainKey] += (int) $row->cnt;
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────
    // Cache key helper (shared with Insight model event + ServerHealthDetector)
    // ──────────────────────────────────────────────────────────

    public static function cacheKey(int $teamId): string
    {
        return "triage:counts:{$teamId}";
    }
}
