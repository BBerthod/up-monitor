<?php

namespace App\Services;

use App\Models\KeywordMetric;
use App\Models\Monitor;
use App\Models\PageMetric;
use Illuminate\Support\Facades\Log;

/**
 * Collects per-page GSC metrics for a monitor and persists them as PageMetric rows.
 *
 * PURPOSE
 * ────────
 * KpiCollector already fetches GSC data aggregated by (query, page).  Rather than
 * re-calling the API we reuse that data and aggregate it BY PAGE, collapsing all
 * queries into a single per-page snapshot.  Each daily run adds one row per page,
 * building the time-series that ContentDecayService needs to compare.
 *
 * AGGREGATION
 * ────────────
 * - clicks / impressions: summed across all queries for the page.
 * - ctr: recomputed as clicks / impressions × 100 (not averaged — weighted naturally).
 * - position: impression-weighted average, which avoids rare high-traffic queries
 *   distorting the "typical" ranking position of the page.
 *
 * NOISE FILTER
 * ────────────
 * Pages with fewer than config('monitoring.content_decay.min_impressions') impressions
 * (default 50) are discarded.  Very low-traffic pages produce unreliable signals and
 * would dominate the decay list with statistically insignificant drops.
 */
class PageMetricsCollector
{
    public function __construct(private readonly KpiCollector $kpiCollector) {}

    /**
     * Collect GSC page-level metrics for the monitor and persist one PageMetric
     * row per qualifying page.
     *
     * @return int Number of pages persisted (0 when GSC credentials are absent).
     */
    public function collectForMonitor(Monitor $monitor): int
    {
        // Reuse the existing GSC query+page rows — no additional API call.
        $rows = $this->kpiCollector->collectGscQueries($monitor);

        if (empty($rows)) {
            // Either no GSC credentials or the API returned nothing — safe to skip.
            return 0;
        }

        $minImpressions = (int) config('monitoring.content_decay.min_impressions', 50);
        $site = $this->kpiCollector->siteNameFromUrl($monitor->url);
        $capturedAt = now();

        // Persist the raw keyword rows before collapsing them by page. These
        // rows were already fetched and were, until now, discarded after
        // aggregation — so a site's average position was knowable but "which
        // keyword did we lose, and when" was not.
        $this->persistKeywordMetrics($site, $rows, $capturedAt);

        // Aggregate by page: sum clicks/impressions, accumulate weighted position.
        $byPage = [];
        foreach ($rows as $row) {
            $page = $row['page'];
            if (! isset($byPage[$page])) {
                $byPage[$page] = ['clicks' => 0.0, 'impressions' => 0.0, 'weighted_position' => 0.0];
            }
            $byPage[$page]['clicks'] += $row['clicks'];
            $byPage[$page]['impressions'] += $row['impressions'];
            // Weight position by impressions so high-volume queries drive the average.
            $byPage[$page]['weighted_position'] += $row['position'] * $row['impressions'];
        }

        $persisted = 0;

        foreach ($byPage as $page => $agg) {
            // Skip pages below the impressions threshold — signal is too noisy.
            if ($agg['impressions'] < $minImpressions) {
                continue;
            }

            $ctr = $agg['impressions'] > 0
                ? round($agg['clicks'] / $agg['impressions'] * 100, 4)
                : 0.0;

            $position = $agg['impressions'] > 0
                ? round($agg['weighted_position'] / $agg['impressions'], 2)
                : 0.0;

            PageMetric::create([
                'site' => $site,
                // Defensive truncation: page_metrics.page is varchar(1024). A
                // single pathological GSC URL must degrade this one row rather
                // than throw and fail the whole per-site detector chain (see
                // DispatchInsights — CollectPageMetrics runs first in it).
                'page' => $this->truncatePage($page),
                'clicks' => round($agg['clicks'], 2),
                'impressions' => round($agg['impressions'], 2),
                'ctr' => $ctr,
                'position' => $position,
                'captured_at' => $capturedAt,
            ]);

            $persisted++;
        }

        Log::info('PageMetricsCollector: persisted page snapshots', [
            'monitor_id' => $monitor->id,
            'site' => $site,
            'pages' => $persisted,
        ]);

        return $persisted;
    }

    /**
     * Persist one KeywordMetric row per (query, page) pair.
     *
     * NOISE FILTER, AND WHY IT IS LOWER THAN THE PAGE ONE
     * ───────────────────────────────────────────────────
     * The page-level filter (50 impressions) exists because a page's decay
     * signal is unreliable below it. A keyword row is not a signal on its own —
     * it is one point in a series — and long-tail queries are precisely what a
     * rank tracker exists to follow. The threshold is therefore much lower, and
     * only filters out the single-impression noise that would otherwise triple
     * the table size for nothing.
     *
     * Bulk-inserted in chunks rather than row by row: a run can carry up to
     * 1000 rows per site, and 1000 individual INSERTs inside the daily chain is
     * a needless several seconds of queue time.
     *
     * @param  list<array{query: string, page: string, clicks: float, impressions: float, ctr: float, position: float}>  $rows
     */
    private function persistKeywordMetrics(string $site, array $rows, \DateTimeInterface $capturedAt): void
    {
        $minImpressions = (float) config('monitoring.keyword_tracking.min_impressions', 3);

        $payload = [];

        foreach ($rows as $row) {
            if ($row['query'] === '' || $row['page'] === '') {
                continue;
            }

            if ($row['impressions'] < $minImpressions) {
                continue;
            }

            $payload[] = [
                'site' => $site,
                'query' => $row['query'],
                'page' => $this->truncatePage($row['page']),
                'clicks' => round($row['clicks'], 2),
                'impressions' => round($row['impressions'], 2),
                'ctr' => round($row['ctr'], 4),
                'position' => round($row['position'], 2),
                'captured_at' => $capturedAt,
                'created_at' => $capturedAt,
                'updated_at' => $capturedAt,
            ];
        }

        if ($payload === []) {
            return;
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            KeywordMetric::insert($chunk);
        }

        Log::info('PageMetricsCollector: persisted keyword snapshots', [
            'site' => $site,
            'keywords' => count($payload),
        ]);
    }

    /**
     * Truncate a page URL to fit the `page` column (varchar(1024) on both
     * page_metrics and keyword_metrics).  A pathological URL should degrade
     * this one row, not throw and abort the whole run — see class docblock.
     */
    private function truncatePage(string $page): string
    {
        return mb_substr($page, 0, 1024);
    }
}
