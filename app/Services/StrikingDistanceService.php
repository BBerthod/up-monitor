<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use Illuminate\Support\Facades\Log;

/**
 * Detects "striking distance" SEO opportunities for a site.
 *
 * A striking-distance keyword is one that ranks in position 11–20 with high
 * impressions but low clicks — meaning a small position improvement would
 * unlock significant organic traffic. This is the classic "quick win" category
 * in SEO: the content already exists and Google trusts it, it just needs a
 * targeted optimisation nudge.
 *
 * Algorithm
 * ─────────
 * 1. Fetch the 28-day GSC query+page breakdown via KpiCollector.
 * 2. Filter to rows inside the configurable position window (default 11–20).
 * 3. Score each row: estimated monthly click gain = impressions × (target_ctr% − actual_ctr%) / 100.
 *    Target CTR is the industry-average CTR for position 8 (3.2 %), representing
 *    a realistic first-page improvement.
 * 4. Rank by impact_score, keep top N (config: striking_distance.top_n).
 * 5. Persist as Insight rows, replacing any non-acknowledged entries from the
 *    previous run so the list stays fresh without losing user acknowledgements.
 */
class StrikingDistanceService
{
    /**
     * Approximate organic CTR by position (industry averages, %).
     * Source: multiple aggregated studies; used for impact estimation only.
     * Position 8 (3.2 %) is the target because it represents a realistic first-page
     * improvement that avoids over-optimistic projections.
     */
    private const CTR_BY_POSITION = [
        1 => 28.0,
        2 => 15.0,
        3 => 11.0,
        4 => 8.0,
        5 => 7.0,
        6 => 5.0,
        7 => 4.0,
        8 => 3.2,
        9 => 2.8,
        10 => 2.5,
    ];

    /** Target position we estimate the page could realistically reach (position 8 = 3.2 % CTR). */
    private const TARGET_POSITION = 8;

    public function __construct(private readonly KpiCollector $kpiCollector) {}

    /**
     * Find the top striking-distance opportunities for the given monitor.
     *
     * Returns rows sorted by impact_score descending, capped at top_n.
     *
     * @return list<array{query: string, page: string, position: float, impressions: float, ctr: float, impact_score: float}>
     */
    public function findForMonitor(Monitor $monitor): array
    {
        $rows = $this->kpiCollector->collectGscQueries($monitor);

        if (empty($rows)) {
            return [];
        }

        $config = config('monitoring.striking_distance');
        $minPosition = (int) ($config['min_position'] ?? 11);
        $maxPosition = (int) ($config['max_position'] ?? 20);
        $minImpress = (int) ($config['min_impressions'] ?? 100);
        $topN = (int) ($config['top_n'] ?? 20);

        $targetCtr = self::CTR_BY_POSITION[self::TARGET_POSITION]; // 3.2 %

        $opportunities = [];

        foreach ($rows as $row) {
            // Apply position + impression thresholds.
            if ($row['position'] < $minPosition || $row['position'] > $maxPosition) {
                continue;
            }
            if ($row['impressions'] < $minImpress) {
                continue;
            }

            // Estimated monthly click gain if we reach the target position.
            // ctr values are already in %; divide by 100 to convert back to fraction.
            $gain = max(0.0, $targetCtr - $row['ctr']); // percentage points
            $impactScore = round($row['impressions'] * $gain / 100, 2);

            $opportunities[] = [
                'query' => $row['query'],
                'page' => $row['page'],
                'position' => $row['position'],
                'impressions' => $row['impressions'],
                'ctr' => $row['ctr'],
                'impact_score' => $impactScore,
            ];
        }

        // Sort by estimated gain descending, then keep only top N.
        usort($opportunities, static fn ($a, $b): int => $b['impact_score'] <=> $a['impact_score']);

        return array_slice($opportunities, 0, $topN);
    }

    /**
     * Detect striking-distance opportunities for a monitor and persist them as Insight rows.
     *
     * Idempotence strategy: delete all non-acknowledged STRIKING_DISTANCE insights
     * for this SITE (not this monitor) before inserting the new batch. The run is
     * dispatched against a "representative" monitor for the hostname (see
     * DispatchInsights), and that representative can change between runs — purging
     * on monitor_id left the previous representative's insights orphaned forever.
     * site_id is stable across representative changes; monitors with no Site fall
     * back to the hostname string. Acknowledged insights are intentionally
     * preserved — the user has already seen them and decided to act (or not), so
     * re-detecting them would create noise and override their decision.
     *
     * team_id is always set explicitly because this method runs inside a queue job
     * where auth() returns null and the ScopedByTeam global scope is therefore inactive.
     *
     * @return int Number of new insights created.
     */
    public function detectForMonitor(Monitor $monitor): int
    {
        $opportunities = $this->findForMonitor($monitor);

        $siteName = $this->kpiCollector->siteNameFromUrl($monitor->url);

        // Remove stale unacknowledged insights from the previous run.
        // withoutGlobalScopes() is mandatory here: no auth() in job context.
        Insight::withoutGlobalScopes()
            // Dropping the global scope drops tenant isolation with it. site_id
            // carries its own team, but the hostname fallback does not: two teams
            // monitoring the same domain would purge each other's insights.
            ->where('team_id', $monitor->team_id)
            ->when(
                $monitor->site_id !== null,
                fn ($query) => $query->where('site_id', $monitor->site_id),
                fn ($query) => $query->where('site', $siteName),
            )
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->whereNull('acknowledged_at')
            ->delete();

        if (empty($opportunities)) {
            return 0;
        }

        $detectedAt = now();
        $created = 0;

        foreach ($opportunities as $opp) {
            Insight::create([
                'team_id' => $monitor->team_id,
                'site' => $siteName,
                'site_id' => $monitor->site_id,
                'monitor_id' => $monitor->id,
                'type' => InsightType::STRIKING_DISTANCE,
                'severity' => InsightSeverity::OPPORTUNITY,
                'title' => sprintf(
                    'Position %d on "%s" — %s impressions/mo',
                    (int) round($opp['position']),
                    $opp['query'],
                    number_format((int) $opp['impressions'])
                ),
                'payload' => [
                    'query' => $opp['query'],
                    'page' => $opp['page'],
                    'position' => $opp['position'],
                    'impressions' => $opp['impressions'],
                    'ctr' => $opp['ctr'],
                    'estimated_gain' => $opp['impact_score'],
                ],
                'impact_score' => $opp['impact_score'],
                'detected_at' => $detectedAt,
            ]);

            $created++;
        }

        Log::info('StrikingDistanceService: insights created', [
            'monitor_id' => $monitor->id,
            'site' => $siteName,
            'count' => $created,
        ]);

        return $created;
    }
}
