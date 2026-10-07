<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\PageMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Detects pages with sustained organic traffic loss ("content decay").
 *
 * DESIGN PHILOSOPHY
 * ──────────────────
 * Content decay is a durable, multi-week signal — not a one-day blip. We
 * detect it by comparing two ROLLING AVERAGES: the mean of every snapshot
 * captured in the last comparison_period_days (default 28 — "current") against
 * the mean of every older snapshot within the look-back window (default 84
 * days — "baseline"). This mirrors the 4-week-vs-4-week comparison GSC itself
 * uses in its UI.
 *
 * WHY NOT TWO SINGLE POINTS (fixed 2026-09-07)
 * ─────────────────────────────────────────────
 * An earlier version compared the single OLDEST-in-window snapshot against
 * the single MOST RECENT snapshot. Each PageMetric row is itself already a
 * trailing 28-day GSC aggregate (see KpiCollector::collectGscQueries), so a
 * one-day collection glitch (delayed GSC data, a transient dip) reading as
 * "0 clicks" on the single day picked as "current" produced a false "-100 %"
 * alert even when the page's real trend was flat or improving — verified
 * against GSC's own 4-week/4-week comparison on several production alerts.
 * The same two-point design also had a blind spot: a real, sustained decline
 * that didn't happen to land exactly on the two extreme snapshots compared
 * went undetected. Averaging both sides over comparison_period_days fixes both.
 *
 * BOOTSTRAP SAFETY
 * ────────────────
 * When the table is freshly populated (no historical data yet), detection is
 * intentionally silent.  We require at least min_history_days (default 14)
 * between the oldest baseline and newest current snapshot before raising any
 * signal.  This means the feature "wakes up" progressively as data
 * accumulates — no false positives on day 1.
 *
 * VOLUME FLOOR
 * ────────────
 * Below min_click_volume (default 5) average baseline clicks, a decline is
 * ignored regardless of its percentage — losing a single click out of one
 * (1 → 0) is not a content-decay signal, it's noise.
 *
 * WEEKLY VOLUME FLOOR AND SITE-WIDE CONTEXT
 * ──────────────────────────────────────────
 * Each page_metrics row is a trailing comparison_period_days aggregate, so the
 * baseline is also expressed per week and must reach min_weekly_clicks (default 10).
 * A page losing X % when the WHOLE site lost almost as much (seasonality, a
 * tracking gap) is not content decay either: the page's decline must exceed the
 * site's GSC clicks decline over the same windows by site_relative_margin points.
 * When the site has no usable GSC history the page is judged on its own, as before.
 *
 * DECAY CRITERIA (both must be true to avoid CTR-only shifts):
 *   - average clicks declined ≥ threshold_pct (default 30 %)
 *   - average impressions also declined (confirming the page lost visibility, not just CTR)
 *
 * IDEMPOTENCE
 * ────────────
 * Before creating new CONTENT_DECAY insights we delete all existing unacknowledged
 * ones for the monitor.  This means each daily run produces a fresh, de-duplicated
 * set of insights rather than piling up duplicates.
 *
 * IMPACT SCORE
 * ────────────
 * impact_score = absolute average clicks lost (baseline_avg_clicks − current_avg_clicks).
 * This lets ActionPlanService prioritise pages that lost 200 clicks over pages
 * that lost 5 clicks, even if the percentage decline is similar.
 */
class ContentDecayService
{
    public function __construct(private readonly KpiCollector $kpiCollector) {}

    /**
     * Detect content decay for all qualifying pages belonging to the monitor.
     *
     * @return int Number of CONTENT_DECAY insights created.
     */
    public function detectForMonitor(Monitor $monitor): int
    {
        $site = $this->kpiCollector->siteNameFromUrl($monitor->url);

        $windowDays = (int) config('monitoring.content_decay.window_days', 84);
        $periodDays = (int) config('monitoring.content_decay.comparison_period_days', 28);
        $minHistoryDays = (int) config('monitoring.content_decay.min_history_days', 14);
        $thresholdPct = (float) config('monitoring.content_decay.threshold_pct', 30);
        $minClickVolume = (float) config('monitoring.content_decay.min_click_volume', 5);

        $minWeeklyClicks = (float) config('monitoring.content_decay.min_weekly_clicks', 10);
        $siteRelativeMargin = (float) config('monitoring.content_decay.site_relative_margin', 20);

        $currentPeriodStart = now()->subDays($periodDays);

        $siteDeclinePct = $this->siteClicksDeclinePct($site, $windowDays, $currentPeriodStart);

        // Every snapshot within the look-back window, aggregated by page in a
        // single SQL query (GROUP BY + conditional aggregates), instead of
        // hydrating one Eloquent model per row. A site can accumulate up to
        // ~1000 pages × 84 days of daily snapshots (~84k rows) — hydrating
        // that many PageMetric models (with decimal/datetime casts) exhausted
        // the worker's 256M memory_limit in production and killed the
        // DetectContentDecay job. Aggregating server-side keeps the result to
        // one row per page.
        $rows = PageMetric::query()
            ->where('site', $site)
            ->where('captured_at', '>=', now()->subDays($windowDays))
            ->toBase()
            ->selectRaw(
                'page,'.
                'COUNT(*) FILTER (WHERE captured_at < ?) AS baseline_count,'.
                'COUNT(*) FILTER (WHERE captured_at >= ?) AS current_count,'.
                'AVG(clicks) FILTER (WHERE captured_at < ?) AS baseline_clicks,'.
                'AVG(clicks) FILTER (WHERE captured_at >= ?) AS current_clicks,'.
                'AVG(impressions) FILTER (WHERE captured_at < ?) AS baseline_impressions,'.
                'AVG(impressions) FILTER (WHERE captured_at >= ?) AS current_impressions,'.
                'MIN(captured_at) FILTER (WHERE captured_at < ?) AS baseline_oldest,'.
                'MAX(captured_at) FILTER (WHERE captured_at >= ?) AS current_newest',
                [
                    $currentPeriodStart, $currentPeriodStart,
                    $currentPeriodStart, $currentPeriodStart,
                    $currentPeriodStart, $currentPeriodStart,
                    $currentPeriodStart, $currentPeriodStart,
                ]
            )
            ->groupBy('page')
            ->get();

        if ($rows->isEmpty()) {
            // No data yet — bootstrap phase, stay silent.
            return 0;
        }

        $decayed = [];

        foreach ($rows as $row) {
            $page = $row->page;

            // Need at least one snapshot on each side of the split to compare.
            if ((int) $row->baseline_count === 0 || (int) $row->current_count === 0) {
                continue;
            }

            // Require a meaningful gap between the oldest baseline snapshot and the
            // newest current snapshot — avoids false positives when two snapshots
            // were captured on the same day or within the same collection batch.
            $baselineOldest = Carbon::parse($row->baseline_oldest);
            $currentNewest = Carbon::parse($row->current_newest);
            $historyDays = $baselineOldest->diffInDays($currentNewest);
            if ($historyDays < $minHistoryDays) {
                continue;
            }

            $baselineClicks = (float) $row->baseline_clicks;
            $currentClicks = (float) $row->current_clicks;
            $baselineImpressions = (float) $row->baseline_impressions;
            $currentImpressions = (float) $row->current_impressions;

            // Only measure decline when there was meaningful baseline traffic.
            // Below min_click_volume, losing a single click reads as "-100 %"
            // without being a meaningful decay signal (e.g. 1 → 0 clicks).
            if ($baselineClicks < $minClickVolume || $baselineImpressions <= 0) {
                continue;
            }

            // Weekly volume floor: rows are trailing $periodDays aggregates.
            if ($baselineClicks / ($periodDays / 7) < $minWeeklyClicks) {
                continue;
            }

            $clicksDeclinePct = (($baselineClicks - $currentClicks) / $baselineClicks) * 100;

            // A decline shared by the whole site is context, not content decay.
            if ($siteDeclinePct !== null && $clicksDeclinePct - $siteDeclinePct < $siteRelativeMargin) {
                continue;
            }
            $impressionsDecline = $currentImpressions < $baselineImpressions;

            // Both conditions must hold: sustained click loss AND impressions also dropped.
            // A CTR drop without impression loss could just be a SERP layout change.
            if ($clicksDeclinePct >= $thresholdPct && $impressionsDecline) {
                $decayed[] = [
                    'page' => $page,
                    'clicks_before' => round($baselineClicks, 2),
                    'clicks_now' => round($currentClicks, 2),
                    'impressions_before' => round($baselineImpressions, 2),
                    'impressions_now' => round($currentImpressions, 2),
                    'decline_pct' => round($clicksDeclinePct, 1),
                    'weeks' => (int) ceil($historyDays / 7),
                    // Absolute average clicks lost drives prioritisation in ActionPlanService.
                    'impact_score' => max(0, round($baselineClicks - $currentClicks, 2)),
                ];
            }
        }

        if (empty($decayed)) {
            return 0;
        }

        $siteScope = fn ($query) => $query
            // Dropping the global scope drops tenant isolation with it. site_id
            // carries its own team, but the hostname fallback does not: two teams
            // monitoring the same domain would purge each other's insights.
            ->where('team_id', $monitor->team_id)
            ->when(
                $monitor->site_id !== null,
                fn ($query) => $query->where('site_id', $monitor->site_id),
                fn ($query) => $query->where('site', $site),
            );

        $firstDetectedAtByPage = Insight::firstDetectedAtByPayloadKeyForOpen(
            InsightType::CONTENT_DECAY,
            $siteScope,
            'page',
        );

        // Idempotence: wipe previous unacknowledged CONTENT_DECAY insights for this
        // SITE (not this monitor) so each run produces a clean, current picture.
        //
        // The run is dispatched against a "representative" monitor for the
        // hostname (see DispatchInsights), and that representative can change
        // between runs (monitor disabled/deleted, or a Site gains a second
        // subdomain). Purging on monitor_id left the previous representative's
        // insights orphaned — never deleted, never acknowledged — producing
        // duplicate CONTENT_DECAY insights for the same page. site_id is stable
        // across representative changes for monitors attached to a Site; for
        // monitors with no Site we fall back to the hostname string, which is
        // the only stable key available in that case.
        Insight::openUnacknowledgedOfType(InsightType::CONTENT_DECAY, $siteScope)
            ->delete();

        $created = 0;

        foreach ($decayed as $d) {
            Insight::create([
                'team_id' => $monitor->team_id,
                'site' => $site,
                'site_id' => $monitor->site_id,
                'monitor_id' => $monitor->id,
                'type' => InsightType::CONTENT_DECAY->value,
                'severity' => InsightSeverity::WARNING->value,
                'title' => sprintf(
                    'Content decay: %s lost %s%% clicks over %d weeks',
                    $d['page'],
                    $d['decline_pct'],
                    $d['weeks'],
                ),
                'payload' => [
                    'page' => $d['page'],
                    'clicks_before' => $d['clicks_before'],
                    'clicks_now' => $d['clicks_now'],
                    'impressions_before' => $d['impressions_before'],
                    'impressions_now' => $d['impressions_now'],
                    'decline_pct' => $d['decline_pct'],
                    'weeks' => $d['weeks'],
                ],
                // Absolute clicks lost → higher = more urgent to fix.
                'impact_score' => $d['impact_score'],
                'detected_at' => $firstDetectedAtByPage[$d['page']] ?? now(),
            ]);

            $created++;
        }

        Log::info('ContentDecayService: detection complete', [
            'monitor_id' => $monitor->id,
            'site' => $site,
            'pages_checked' => $rows->count(),
            'decayed' => $created,
        ]);

        return $created;
    }

    /**
     * Relative decline (%) of the site's GSC clicks between the baseline and the
     * current window, from kpi_snapshots; null when either window is empty or the
     * baseline is zero (not enough data to qualify a page decline against).
     */
    private function siteClicksDeclinePct(string $site, int $windowDays, Carbon $currentPeriodStart): ?float
    {
        $row = KpiSnapshot::query()
            ->where('site', $site)
            ->where('source', KpiSource::GSC->value)
            ->where('metric', 'clicks_28d')
            ->where('captured_at', '>=', now()->subDays($windowDays))
            ->toBase()
            ->selectRaw(
                'AVG(value) FILTER (WHERE captured_at < ?) AS baseline,'.
                'AVG(value) FILTER (WHERE captured_at >= ?) AS current',
                [$currentPeriodStart, $currentPeriodStart]
            )
            ->first();

        if ($row === null || $row->baseline === null || $row->current === null || (float) $row->baseline <= 0.0) {
            return null;
        }

        return (((float) $row->baseline - (float) $row->current) / (float) $row->baseline) * 100;
    }
}
