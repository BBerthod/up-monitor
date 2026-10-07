<?php

namespace App\Services;

use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Team;

/**
 * Builds a prioritised SEO action plan for a team by aggregating all
 * unacknowledged, actionable insights across every site in the portfolio.
 *
 * DESIGN NOTES
 * ─────────────────────────────────────────────────────────────────────────────
 * • No auth() dependency: works from both web requests (DashboardController)
 *   and job context (SendDigests) where auth() is null and the ScopedByTeam
 *   global scope is inactive.  team_id is always supplied explicitly; queries
 *   call withoutGlobalScopes().
 *
 * • Excluded types — UPTIME_INCIDENT and HEALTH_DROP are operational alerts,
 *   not SEO tasks the user can address directly.  They belong in the
 *   "Alerts" section of the digest, not in an action plan.
 *
 * • Priority scoring — ROI heuristic: impact / effort.
 *   Impact and effort are both calibrated heuristics, not ML predictions.
 *   They are intentionally simple so the plan remains interpretable and the
 *   formula can be recalibrated later with real click-through data.
 *
 * • Impact normalisation per type — impact_score carries different magnitudes:
 *     STRIKING_DISTANCE → estimated clicks/month (can be > 100)
 *     TRAFFIC/POSITION/CTR changes → abs(delta_pct) stored in impact_score
 *     PERF_REGRESSION → severity score already in [0-100] range
 *   We map each to a 0-100 scale via the IMPACT_STRATEGY constants below.
 */
class ActionPlanService
{
    /**
     * Effort heuristic per insight type.
     * Scale: 1 = minutes of work, 5 = multiple days of engineering.
     *
     * REVENUE_AT_RISK is effort 1 because fixing a broken page is usually a
     * quick deploy or redirect — and it unblocks revenue immediately.  The low
     * effort combined with a high impact (clicks at risk) gives it the highest
     * ROI score in the plan, which is intentional: a broken earner is always
     * the top priority.
     */
    private const EFFORT_BY_TYPE = [
        InsightType::STRIKING_DISTANCE->value => 2,  // rewrite a title / add internal links
        InsightType::CTR_CHANGE->value => 2,          // rewrite title / meta description
        InsightType::POSITION_CHANGE->value => 3,     // on-page SEO work
        InsightType::TRAFFIC_CHANGE->value => 3,      // root-cause investigation + on-page fixes
        InsightType::PERF_REGRESSION->value => 4,     // technical optimisation (Core Web Vitals, etc.)
        InsightType::CONTENT_DECAY->value => 3,       // refresh/update stale content
        InsightType::REVENUE_AT_RISK->value => 1,     // fix a broken page — urgent and usually quick
        InsightType::AFFILIATE_LEAK->value => 1,      // fix a tag/link config — minutes of work
        InsightType::AFFILIATE_REDIRECT_BROKEN->value => 1, // restore a rewrite rule — one deploy
    ];

    /**
     * Insight types that are actionable SEO tasks.
     * HEALTH_DROP and UPTIME_INCIDENT are excluded — they are operational
     * alerts handled separately in the digest Alerts section.
     */
    private const ACTIONABLE_TYPES = [
        InsightType::STRIKING_DISTANCE->value,
        InsightType::TRAFFIC_CHANGE->value,
        InsightType::POSITION_CHANGE->value,
        InsightType::CTR_CHANGE->value,
        InsightType::PERF_REGRESSION->value,
        InsightType::CONTENT_DECAY->value,
        InsightType::REVENUE_AT_RISK->value,
        InsightType::AFFILIATE_LEAK->value,
        InsightType::AFFILIATE_REDIRECT_BROKEN->value,
    ];

    /**
     * Build the prioritised action plan for a team.
     *
     * @return array{
     *   actions: list<array{
     *     insight_id: int,
     *     site: string,
     *     type: string,
     *     severity: string,
     *     title: string,
     *     action: string,
     *     impact: int,
     *     effort: int,
     *     priority_score: float,
     *   }>,
     *   total_actionable: int,
     *   generated_at: string,
     * }
     */
    public function forTeam(Team $team, int $limit = 10): array
    {
        // Fetch all unacknowledged, actionable insights for the team.
        // withoutGlobalScopes() is mandatory in job/CLI contexts where auth() is null.
        $insights = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNull('acknowledged_at')
            ->whereIn('type', self::ACTIONABLE_TYPES)
            ->get();

        $totalActionable = $insights->count();

        $actions = $insights
            ->map(function (Insight $insight): array {
                $typeValue = $insight->type instanceof InsightType
                    ? $insight->type->value
                    : (string) $insight->type;

                $effort = self::EFFORT_BY_TYPE[$typeValue] ?? 3;
                $impact = $this->normaliseImpact($typeValue, (float) $insight->impact_score, $insight->payload ?? []);

                // ROI = impact / effort — higher = more bang for the buck.
                $priorityScore = $effort > 0 ? round($impact / $effort, 2) : 0.0;

                return [
                    'insight_id' => $insight->id,
                    'site' => $insight->site,
                    'type' => $typeValue,
                    'severity' => $insight->severity instanceof \App\Enums\InsightSeverity
                        ? $insight->severity->value
                        : (string) $insight->severity,
                    'title' => $insight->title,
                    'action' => $this->buildActionText($typeValue, $insight->site, $insight->payload ?? []),
                    'impact' => $impact,
                    'effort' => $effort,
                    'priority_score' => $priorityScore,
                ];
            })
            ->sortByDesc('priority_score')
            ->take($limit)
            ->values()
            ->all();

        return [
            'actions' => $actions,
            'total_actionable' => $totalActionable,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Normalise an insight's raw impact_score to an integer in [0, 100].
     *
     * Each type carries a different unit:
     *   STRIKING_DISTANCE → estimated clicks/month; plafond at 100 (beyond that
     *     the gain is life-changing and should obviously be done anyway).
     *   TRAFFIC/POSITION/CTR change → abs(delta_pct) stored in impact_score;
     *     multiply by 2 so a 30 % drop maps to 60/100 — feels proportionate.
     *   PERF_REGRESSION → already a severity score close to [0-100].
     *
     * This is a calibration heuristic.  The formula can be tuned once we have
     * enough historical data on which actions actually drove clicks.
     */
    private function normaliseImpact(string $type, float $impactScore, array $payload): int
    {
        $raw = match ($type) {
            InsightType::STRIKING_DISTANCE->value => $impactScore,
            InsightType::TRAFFIC_CHANGE->value,
            InsightType::POSITION_CHANGE->value,
            InsightType::CTR_CHANGE->value => abs($payload['delta_pct'] ?? $impactScore) * 2,
            InsightType::PERF_REGRESSION->value => $impactScore,
            // CONTENT_DECAY: impact_score = absolute clicks lost.
            // Clamp to 100 so a page that lost 300 clicks does not dwarf all others
            // in the priority formula — it already ranks high enough at 100.
            InsightType::CONTENT_DECAY->value => min(100, $impactScore),
            // REVENUE_AT_RISK: impact_score = monthly clicks at risk (raw).
            // Same clamping rationale: a 500-click/mo page is already top priority
            // without needing to score 500; effort=1 already lifts it to the top.
            InsightType::REVENUE_AT_RISK->value => min(100, $impactScore),
            // AFFILIATE_LEAK: impact_score = number of affected links (raw count).
            // Clamp to 100; effort=1 gives it high ROI even at modest link counts.
            InsightType::AFFILIATE_LEAK->value => min(100, $impactScore),
            // AFFILIATE_REDIRECT_BROKEN: impact_score is already the failure ratio
            // expressed 0-100, so it needs no rescaling — listed explicitly rather
            // than falling through, because the identical-looking clamp above means
            // something different (a raw count being capped).
            InsightType::AFFILIATE_REDIRECT_BROKEN->value => min(100, $impactScore),
            default => $impactScore,
        };

        return (int) min(100, max(0, round($raw)));
    }

    /**
     * Generate a concrete, human-readable action recommendation from the
     * insight type and payload.
     *
     * This is a template mapping — no AI call, intentionally deterministic so
     * the digest job never hits an external service for this data.
     */
    private function buildActionText(string $type, string $site, array $payload): string
    {
        return match ($type) {
            InsightType::STRIKING_DISTANCE->value => $this->buildStrikingDistanceAction($site, $payload),

            InsightType::CTR_CHANGE->value => sprintf(
                'Rewrite title/meta on %s to recover CTR',
                $site,
            ),

            InsightType::POSITION_CHANGE->value => (($payload['direction'] ?? 'decline') === 'decline')
                ? sprintf('Investigate ranking drop on %s', $site)
                : sprintf('Reinforce ranking gains on %s', $site),

            InsightType::TRAFFIC_CHANGE->value => sprintf(
                'Review traffic change on %s (%s%%)',
                $site,
                round((float) ($payload['delta_pct'] ?? 0), 1),
            ),

            InsightType::PERF_REGRESSION->value => sprintf(
                'Fix performance regression on %s',
                $site,
            ),

            InsightType::CONTENT_DECAY->value => sprintf(
                'Refresh content on %s (lost %s%% clicks)',
                $payload['page'] ?? $site,
                round((float) ($payload['decline_pct'] ?? 0), 1),
            ),

            InsightType::REVENUE_AT_RISK->value => sprintf(
                'Fix broken page %s — %d clicks/mo at risk',
                // Defensive access: payload may be partially populated if the
                // insight was created by an older version of BrokenPageService.
                $payload['page'] ?? $site,
                (int) ($payload['clicks'] ?? 0),
            ),

            InsightType::AFFILIATE_LEAK->value => sprintf(
                'Fix affiliate link leak on %s (%d affected links)',
                $site,
                (int) ($payload['affected_links'] ?? 0),
            ),

            InsightType::AFFILIATE_REDIRECT_BROKEN->value => sprintf(
                'Restore affiliate redirects on %s (%d of %d tested links dead)',
                $site,
                (int) ($payload['broken'] ?? 0),
                (int) ($payload['tested'] ?? 0),
            ),

            default => sprintf('Review insight on %s', $site),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildStrikingDistanceAction(string $site, array $payload): string
    {
        $page = $payload['page'] ?? $site;
        $query = trim((string) ($payload['query'] ?? ''));

        if ($query === '') {
            return sprintf('Optimize the title/meta of %s for its near-page-one queries', $page);
        }

        if (! array_key_exists('position', $payload) || $payload['position'] === null) {
            return sprintf('Optimize the title/meta of %s for "%s"', $page, $query);
        }

        $position = is_float($payload['position'])
            ? number_format($payload['position'], 1, '.', '')
            : $payload['position'];

        return sprintf(
            'Optimize the title/meta of %s for "%s" (currently position %s)',
            $page,
            $query,
            $position,
        );
    }
}
