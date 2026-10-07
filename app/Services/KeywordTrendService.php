<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\KeywordMetric;
use App\Models\Monitor;
use Illuminate\Support\Facades\Log;

/**
 * Turns the keyword history into two signals: keywords that lost rank, and
 * keywords served by more than one page.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Up could already say a site's average position had moved. It could never say
 * WHICH keyword moved — which is where every ranking investigation actually
 * starts. Worse, an aggregate hides the interesting case entirely: a site can
 * hold a flat average while its best commercial keyword falls off page one,
 * because a hundred long-tail queries drifted up to compensate.
 *
 * TWO DETECTORS, ONE SERVICE
 * ──────────────────────────
 * Both signals share the same persisted history and lifecycle.
 *
 *  - DROP: a keyword that fell by a meaningful number of positions AND had
 *    enough impressions for the move to mean anything.
 *  - CANNIBALISATION: one query answered by several pages on the same day.
 *    Google picks one, usually not the one you would; the rest split authority.
 *
 * WHY POSITION DELTAS ARE NOT SYMMETRIC
 * ─────────────────────────────────────
 * Falling from 3 to 8 and from 43 to 48 are both "minus five", and they are not
 * remotely the same event: the first leaves page one, the second moves within a
 * region nobody visits. Severity therefore keys off where the keyword LANDED,
 * not the size of the fall. Drop detection compares rolling windows rather
 * than two individual captures, with position weighted by impressions.
 */
class KeywordTrendService
{
    public function __construct(private readonly KpiCollector $kpiCollector) {}

    /**
     * Detect keyword drops and cannibalisation for a monitor's site.
     *
     * @return int Number of insights created.
     */
    public function detectForMonitor(Monitor $monitor): int
    {
        $site = $this->kpiCollector->siteNameFromUrl($monitor->url);

        // Idempotence: replace unacknowledged findings on every run so the list
        // always reflects the current state. Purge on the SITE (not the
        // monitor): the run is dispatched against a "representative" monitor
        // for the hostname (see DispatchInsights), and that representative can
        // change between runs — purging on monitor_id left the previous
        // representative's insights orphaned forever, producing duplicate
        // KEYWORD_DROP / KEYWORD_CANNIBALISATION insights. site_id is stable
        // across representative changes; monitors with no Site fall back to
        // the hostname string.
        Insight::withoutGlobalScopes()
            // Dropping the global scope drops tenant isolation with it. site_id
            // carries its own team, but the hostname fallback does not: two teams
            // monitoring the same domain would purge each other's insights.
            ->where('team_id', $monitor->team_id)
            ->when(
                $monitor->site_id !== null,
                fn ($query) => $query->where('site_id', $monitor->site_id),
                fn ($query) => $query->where('site', $site),
            )
            ->whereIn('type', [
                InsightType::KEYWORD_DROP->value,
                InsightType::KEYWORD_CANNIBALISATION->value,
            ])
            ->whereNull('acknowledged_at')
            ->delete();

        $created = 0;
        $created += $this->detectDrops($monitor, $site);
        $created += $this->detectCannibalisation($monitor, $site);

        return $created;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Keyword drops
    // ─────────────────────────────────────────────────────────────────────────

    private function detectDrops(Monitor $monitor, string $site): int
    {
        $windowDays = (int) config('monitoring.keyword_tracking.window_days', 84);
        $periodDays = (int) config('monitoring.keyword_tracking.comparison_period_days', 28);
        $minImpressions = (float) config('monitoring.keyword_tracking.drop_min_impressions', 100);
        $minDelta = (float) config('monitoring.keyword_tracking.drop_min_positions', 5);
        $maxFromPosition = (float) config('monitoring.keyword_tracking.drop_max_from_position', 20);
        $minClicksLost = (float) config('monitoring.keyword_tracking.drop_min_clicks_lost', 5);
        $significantImpressions = (float) config('monitoring.keyword_tracking.drop_significant_impressions', 500);
        $criticalClickDeclinePct = (float) config('monitoring.keyword_tracking.drop_critical_click_decline_pct', 30);
        $topN = (int) config('monitoring.keyword_tracking.drop_top_n', 10);

        $currentPeriodStart = now()->subDays($periodDays);

        $snapshotsPerQuery = KeywordMetric::query()
            ->where('site', $site)
            ->where('captured_at', '>=', now()->subDays($windowDays))
            ->orderBy('captured_at')
            ->get()
            ->groupBy('query');

        if ($snapshotsPerQuery->isEmpty()) {
            return 0;
        }

        $drops = [];

        foreach ($snapshotsPerQuery as $query => $snapshots) {
            $baselineSnapshots = $snapshots->filter(
                fn (KeywordMetric $snapshot): bool => $snapshot->captured_at->lt($currentPeriodStart)
            );
            $currentSnapshots = $snapshots->filter(
                fn (KeywordMetric $snapshot): bool => $snapshot->captured_at->gte($currentPeriodStart)
            );

            if ($baselineSnapshots->isEmpty() || $currentSnapshots->isEmpty()) {
                continue;
            }

            $before = $this->summariseWindow($baselineSnapshots);
            $now = $this->summariseWindow($currentSnapshots);

            // Each stored capture already covers trailing 28-day GSC data, so the
            // floor uses average impressions per capture instead of double-counting
            // overlapping captures by summing the whole window.
            if ($before['impressions'] < $minImpressions) {
                continue;
            }

            // Position is golf scoring: larger is worse, so a drop is positive.
            $delta = $now['position'] - $before['position'];

            if ($delta < $minDelta) {
                continue;
            }

            // A fall that starts beyond the first two pages happens where nobody looks.
            if ($before['position'] > $maxFromPosition) {
                continue;
            }

            // The loss must also be significant: clicks actually lost, or enough
            // impressions that the keyword mattered.
            if (max(0.0, $before['clicks'] - $now['clicks']) < $minClicksLost
                && $before['impressions'] < $significantImpressions) {
                continue;
            }

            $clickDeclinePct = $before['clicks'] > 0
                ? (($before['clicks'] - $now['clicks']) / $before['clicks']) * 100
                : 0.0;

            $drops[] = [
                'query' => $query,
                'from' => round($before['position'], 2),
                'to' => round($now['position'], 2),
                'delta' => round($delta, 2),
                'impressions' => round($before['impressions'], 0),
                'clicks_lost' => round(max(0.0, $before['clicks'] - $now['clicks']), 2),
                'click_decline_pct' => round(max(0.0, $clickDeclinePct), 1),
            ];
        }

        if ($drops === []) {
            return 0;
        }

        // Rank by where the keyword landed, then by how far it fell: leaving
        // page one matters more than a long slide deep in the results.
        usort($drops, fn (array $a, array $b): int => [$a['to'], -$a['delta']] <=> [$b['to'], -$b['delta']]);

        $drops = array_slice($drops, 0, $topN);
        $worst = $drops[0];

        // A rank drop alone is WARNING; CRITICAL requires a page-one exit corroborated by significant click loss.
        $severity = $worst['to'] > 10 && $worst['from'] <= 10
            && $worst['click_decline_pct'] >= $criticalClickDeclinePct
            ? InsightSeverity::CRITICAL   // fell off page one
            : InsightSeverity::WARNING;

        $title = count($drops) === 1
            ? sprintf(
                'Keyword "%s" fell from position %.1f to %.1f',
                $worst['query'],
                $worst['from'],
                $worst['to'],
            )
            : sprintf(
                '%d keywords lost rank — worst: "%s" %.1f → %.1f',
                count($drops),
                $worst['query'],
                $worst['from'],
                $worst['to'],
            );

        Insight::create([
            'team_id' => $monitor->team_id,
            'site' => $site,
            'site_id' => $monitor->site_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::KEYWORD_DROP->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => [
                'window_days' => $windowDays,
                'comparison_period_days' => $periodDays,
                'drops' => $drops,
            ],
            // Impressions at stake on the worst keyword: what the drop is
            // costing in visibility, on the same scale as other SEO insights.
            'impact_score' => round(min(100, $worst['impressions'] / 10), 2),
            'detected_at' => now(),
        ]);

        Log::info('KeywordTrendService: keyword drops detected', [
            'monitor_id' => $monitor->id,
            'site' => $site,
            'drops' => count($drops),
        ]);

        return 1;
    }

    /**
     * Collapse all query/page rows in one comparison window into one signal.
     *
     * Position is weighted by impressions (the same semantics as GSC), while
     * clicks and impressions are averaged per capture so multiple ranking pages
     * and overlapping trailing-28-day captures do not inflate the volume floor.
     *
     * @param  \Illuminate\Support\Collection<int, KeywordMetric>  $snapshots
     * @return array{position: float, clicks: float, impressions: float}
     */
    private function summariseWindow(\Illuminate\Support\Collection $snapshots): array
    {
        $captures = $snapshots->groupBy(
            fn (KeywordMetric $snapshot): string => $snapshot->captured_at->toIso8601String()
        );
        $totalImpressions = (float) $snapshots->sum('impressions');
        $weightedPosition = $totalImpressions > 0
            ? (float) $snapshots->sum(
                fn (KeywordMetric $snapshot): float => (float) $snapshot->position * (float) $snapshot->impressions
            ) / $totalImpressions
            : (float) $snapshots->avg('position');

        return [
            'position' => $weightedPosition,
            'clicks' => (float) $captures->map->sum('clicks')->avg(),
            'impressions' => (float) $captures->map->sum('impressions')->avg(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cannibalisation
    // ─────────────────────────────────────────────────────────────────────────

    private function detectCannibalisation(Monitor $monitor, string $site): int
    {
        $minImpressions = (float) config('monitoring.keyword_tracking.cannibal_min_impressions', 100);
        $topN = (int) config('monitoring.keyword_tracking.cannibal_top_n', 10);
        $ignoreBestPosition = (float) config('monitoring.keyword_tracking.cannibal_ignore_best_position', 3);

        $latestAt = KeywordMetric::where('site', $site)->max('captured_at');

        if ($latestAt === null) {
            return 0;
        }

        // Queries answered by more than one page in the same capture.
        // toBase(): aggregation, not model hydration — the decimal casts would
        // otherwise be applied to raw aggregate columns.
        $rows = KeywordMetric::query()
            ->toBase()
            ->select('query')
            ->selectRaw('COUNT(DISTINCT page) as page_count')
            ->selectRaw('SUM(impressions) as impressions')
            ->selectRaw('MIN(position) as best_position')
            ->where('site', $site)
            ->where('captured_at', $latestAt)
            ->where('query', 'not like', '%site:%')
            ->groupBy('query')
            ->havingRaw('COUNT(DISTINCT page) > 1')
            ->havingRaw('MIN(position) > ?', [$ignoreBestPosition])
            ->havingRaw('SUM(impressions) >= ?', [$minImpressions])
            ->orderByDesc('impressions')
            ->limit($topN)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $findings = $rows->map(fn ($row): array => [
            'query' => $row->query,
            'pages' => (int) $row->page_count,
            'impressions' => round((float) $row->impressions, 0),
            'best_position' => round((float) $row->best_position, 2),
        ])->all();

        $worst = $findings[0];

        Insight::create([
            'team_id' => $monitor->team_id,
            'site' => $site,
            'site_id' => $monitor->site_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::KEYWORD_CANNIBALISATION->value,
            // Never CRITICAL: this is a structural content issue to schedule,
            // not an incident to page anyone about.
            'severity' => InsightSeverity::WARNING->value,
            'title' => sprintf(
                '%d keyword(s) served by multiple pages — worst: "%s" across %d pages',
                count($findings),
                $worst['query'],
                $worst['pages'],
            ),
            'payload' => [
                'captured_at' => $latestAt,
                'findings' => $findings,
            ],
            'impact_score' => round(min(100, $worst['impressions'] / 10), 2),
            'detected_at' => now(),
        ]);

        Log::info('KeywordTrendService: cannibalisation detected', [
            'monitor_id' => $monitor->id,
            'site' => $site,
            'queries' => count($findings),
        ]);

        return 1;
    }
}
