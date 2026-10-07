<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

/**
 * Detects "what changed this week" across a team's portfolio of sites.
 *
 * Algorithm
 * ─────────
 * 1. Derive the set of distinct sites from the team's monitors (hostname-level,
 *    de-duplicated, same as HealthScoreService / StrikingDistanceService).
 * 2. For each site × metric pair, compare the 7-day moving average (this week)
 *    against the preceding 7-day window (last week) using KpiSnapshot helpers.
 * 3. Emit a "change" entry for every delta that exceeds the significance
 *    threshold AND meets the absolute volume floor (see VOLUME FLOOR NOTE).
 * 4. Run two bonus detections: Google core-update fingerprint (portfolio-wide
 *    ranking movement) and broken GA4 tag (zero users while GSC shows traffic).
 *
 * POSITION INVERSION NOTE
 * ───────────────────────
 * For GSC position, semantics are inverted: a *lower* number means a *better*
 * rank. A delta_pct of −10 % means position went from 10 → 9, which is an
 * improvement. Every place that interprets "direction" for position must flip
 * the sign compared to all other metrics. This is the main gotcha in this
 * service and is explicitly handled — see computeDelta() and the METRICS map.
 *
 * VOLUME FLOOR NOTE
 * ─────────────────
 * Pure-relative thresholds fire on near-zero-traffic sites where micro-moves
 * like 3→2 clicks produce -33% "WARNING" signals with zero actionable value.
 * meetsVolumeFloor() guards each delta: for clicks/impressions/users it checks
 * max(current, previous) against a per-metric minimum; for position/ctr it
 * proxies volume via the site's recent GSC impressions average. Floors are
 * configured in config('monitoring.what_changed.min_volume') and can be tuned
 * per-deployment via env vars (WHAT_CHANGED_MIN_CLICKS, etc.).
 *
 * IMPLAUSIBLE SURGE NOTE
 * ──────────────────────
 * A rise is not automatically good news. isImplausibleSurge() re-files a large GA4
 * users increase as WARNING when GSC clicks did not rise with it: analytics counts
 * whoever loads the page, search counts who chose the site, and a gap between the two
 * usually means bots. Production filed webcompare.com "Users +416%" as a positive INFO
 * while its search clicks were falling.
 *
 * IDEMPOTENCE
 * ───────────
 * At the start of forTeam(), all non-acknowledged TRAFFIC_CHANGE / POSITION_CHANGE /
 * CTR_CHANGE insights for the team are wiped before the new batch is written.
 * This ensures weekly runs don't accumulate stale rows. Acknowledged insights
 * are intentionally preserved (the user has acted on or dismissed them).
 *
 * AUTH CONTEXT
 * ────────────
 * This service is designed to be called from queue jobs where auth() returns
 * null and the ScopedByTeam global scope is inactive. team_id is therefore
 * always supplied explicitly, and withoutGlobalScopes() is used for any
 * Insight reads/deletes to avoid the "no auth, no scope, returns empty" trap.
 */
class WhatChangedService
{
    /**
     * Metrics to evaluate per site, keyed by "{source}:{metric}".
     *
     * lower_is_better: true means a negative delta_pct is an improvement
     *   (only applies to GSC position — lower rank number = better).
     *
     * @var list<array{source: KpiSource, metric: string, label: string, type: InsightType, lower_is_better: bool}>
     */
    private const METRICS = [
        [
            'source' => KpiSource::GSC,
            'metric' => 'clicks_28d',
            'label' => 'Clicks',
            'type' => InsightType::TRAFFIC_CHANGE,
            'lower_is_better' => false,
        ],
        [
            'source' => KpiSource::GSC,
            'metric' => 'impressions_28d',
            'label' => 'Impressions',
            'type' => InsightType::TRAFFIC_CHANGE,
            'lower_is_better' => false,
        ],
        [
            'source' => KpiSource::GSC,
            'metric' => 'position_28d',
            'label' => 'Avg position',
            'type' => InsightType::POSITION_CHANGE,
            'lower_is_better' => true,  // lower rank number = better — see class docblock
        ],
        [
            'source' => KpiSource::GSC,
            'metric' => 'ctr_28d',
            'label' => 'CTR',
            'type' => InsightType::CTR_CHANGE,
            'lower_is_better' => false,
        ],
        [
            'source' => KpiSource::GA4,
            'metric' => 'users_28d',
            'label' => 'Users',
            'type' => InsightType::TRAFFIC_CHANGE,
            'lower_is_better' => false,
        ],
    ];

    public function __construct(private readonly KpiCollector $kpiCollector) {}

    // ──────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────

    /**
     * Compute week-over-week KPI changes for all sites belonging to the team.
     *
     * Persists significant deltas as Insight rows and returns a summary array
     * suitable for consumption by the weekly digest job or the copilot dashboard.
     *
     * @return array{
     *   changes: list<array{site: string, metric: string, label: string, previous: float, current: float, delta_pct: float, direction: string}>,
     *   core_update_suspected: bool,
     *   ga4_broken_sites: list<string>,
     * }
     */
    public function forTeam(Team $team): array
    {
        $config = config('monitoring.what_changed');
        $significancePct = (float) ($config['significance_pct'] ?? 10);

        /** @var array<string,float> $minVolume Absolute volume floors — keyed by metric name. */
        $minVolume = $config['min_volume'] ?? [
            'clicks_28d' => 10.0,
            'impressions_28d' => 100.0,
            'users_28d' => 50.0,
            'position_28d' => 30.0,
            'ctr_28d' => 100.0,
        ];

        // ── 1. Derive site list, site→monitor_id map, and site→site_id map ────
        // Multiple monitors may point at the same hostname (e.g. HTTP + ping).
        // We keep the first monitor encountered as the anchor for monitor_id on
        // Insights — any monitor from the same site is equivalent for linking.
        $siteMonitorMap = [];  // site => monitor_id
        $siteSiteIdMap = [];  // site => site_id (nullable — null when monitor has no linked Site)

        foreach ($team->monitors as $monitor) {
            $site = $this->kpiCollector->siteNameFromUrl($monitor->url);

            if (! isset($siteMonitorMap[$site])) {
                $siteMonitorMap[$site] = $monitor->id;
                $siteSiteIdMap[$site] = $monitor->site_id;
            }
        }

        $sites = array_keys($siteMonitorMap);

        // ── 2. Idempotence: wipe stale unacknowledged insights for this run ──
        // withoutGlobalScopes() is mandatory: no auth() in job context.
        Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('type', [
                InsightType::TRAFFIC_CHANGE->value,
                InsightType::POSITION_CHANGE->value,
                InsightType::CTR_CHANGE->value,
            ])
            ->whereNull('acknowledged_at')
            ->delete();

        // ── 3. Compute deltas per site × metric ──────────────────────────────
        $changes = [];
        $detectedAt = now();

        // Track which sites moved in position this week — needed for core-update detection.
        $sitesWithPositionMovement = 0;

        foreach ($sites as $site) {
            $monitorId = $siteMonitorMap[$site];

            foreach (self::METRICS as $def) {
                $source = $def['source'];
                $metric = $def['metric'];
                $lowerIsBetter = $def['lower_is_better'];

                // This week: last 7 days anchored to now().
                $current = KpiSnapshot::averageOver($site, $source, $metric, 7);

                // Last week: the 7-day window ending 7 days ago (days -14 → -7).
                $previous = KpiSnapshot::averageBetween($site, $source, $metric, 14, 7);

                // Skip if data is absent or previous is zero (division by zero risk).
                if ($current === null || $previous === null || $previous == 0.0) {
                    continue;
                }

                $deltaPct = ($current - $previous) / abs($previous) * 100;

                // Noise filter — ignore changes smaller than the significance threshold.
                if (abs($deltaPct) < $significancePct) {
                    continue;
                }

                // Traffic declines: Google's impressions count drifts without any effect on
                // visits, so an impressions-only drop is not an alert; clicks and users must
                // fall by at least min_decline_pct (never below the significance threshold).
                if ($def['type'] === InsightType::TRAFFIC_CHANGE && $deltaPct < 0) {
                    if ($metric === 'impressions_28d') {
                        continue;
                    }

                    $minDeclinePct = max($significancePct, (float) ($config['traffic_change']['min_decline_pct'] ?? 30));

                    if (abs($deltaPct) < $minDeclinePct) {
                        continue;
                    }
                }

                // Volume floor — ignore statistically meaningless deltas on near-zero-traffic
                // sites. A 3→2 click move is -33% but carries zero signal. meetsVolumeFloor()
                // checks max(current, previous) for direct metrics; for position/ctr it proxies
                // through GSC impressions because those metrics have no intrinsic "volume".
                if (! $this->meetsVolumeFloor($site, $metric, $current, $previous, $minVolume)) {
                    continue;
                }

                // Determine semantic direction, inverting for position.
                // For clicks/impressions/ctr/users: positive delta = improvement.
                // For position: negative delta = improvement (rank got numerically lower = better).
                $direction = $lowerIsBetter
                    ? ($deltaPct < 0 ? 'improvement' : 'decline')
                    : ($deltaPct > 0 ? 'improvement' : 'decline');

                // Track position movement count for core-update heuristic.
                if ($metric === 'position_28d') {
                    $sitesWithPositionMovement++;
                }

                $changes[] = [
                    'site' => $site,
                    'metric' => $metric,
                    'label' => $def['label'],
                    'previous' => $previous,
                    'current' => $current,
                    'delta_pct' => round($deltaPct, 2),
                    'direction' => $direction,
                ];

                // Severity: WARNING for regressions, INFO for improvements.
                // A large improvement is good news, not an alert — unless it is not
                // credible, in which case it is the opposite of good news.
                $severity = $direction === 'decline'
                    ? InsightSeverity::WARNING
                    : InsightSeverity::INFO;

                $implausible = $direction === 'improvement'
                    && $this->isImplausibleSurge($site, $metric, $deltaPct);

                if ($implausible) {
                    $severity = InsightSeverity::WARNING;
                }

                $this->persistChangeInsight(
                    team: $team,
                    site: $site,
                    siteId: $siteSiteIdMap[$site] ?? null,
                    monitorId: $monitorId,
                    def: $def,
                    previous: $previous,
                    current: $current,
                    deltaPct: $deltaPct,
                    direction: $direction,
                    severity: $severity,
                    detectedAt: $detectedAt,
                    implausible: $implausible,
                );
            }
        }

        // Sort changes by |delta_pct| descending — most impactful first.
        usort($changes, static fn (array $a, array $b): int => abs($b['delta_pct']) <=> abs($a['delta_pct'])
        );

        // ── 4. Bonus detections ───────────────────────────────────────────────
        $coreUpdateSuspected = $this->detectCoreUpdate(
            team: $team,
            sites: $sites,
            sitesWithPositionMovement: $sitesWithPositionMovement,
            config: $config,
            detectedAt: $detectedAt,
        );

        $ga4BrokenSites = $this->detectBrokenGa4(
            team: $team,
            sites: $sites,
            siteMonitorMap: $siteMonitorMap,
            siteSiteIdMap: $siteSiteIdMap,
            detectedAt: $detectedAt,
        );

        Log::info('WhatChangedService: run complete', [
            'team_id' => $team->id,
            'sites' => count($sites),
            'changes' => count($changes),
            'core_update_suspected' => $coreUpdateSuspected,
            'ga4_broken_sites' => $ga4BrokenSites,
        ]);

        return [
            'changes' => $changes,
            'core_update_suspected' => $coreUpdateSuspected,
            'ga4_broken_sites' => $ga4BrokenSites,
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Persist a single week-over-week change as an Insight row.
     *
     * impact_score = |delta_pct| so the digest can rank insights by magnitude.
     *
     * @param  array{source: KpiSource, metric: string, label: string, type: InsightType, lower_is_better: bool}  $def
     */
    private function persistChangeInsight(
        Team $team,
        string $site,
        ?int $siteId,
        int $monitorId,
        array $def,
        float $previous,
        float $current,
        float $deltaPct,
        string $direction,
        InsightSeverity $severity,
        \DateTimeInterface $detectedAt,
        bool $implausible = false,
    ): void {
        $title = $this->buildTitle(
            label: $def['label'],
            site: $site,
            previous: $previous,
            current: $current,
            deltaPct: $deltaPct,
            direction: $direction,
            metric: $def['metric'],
        );

        // Name the suspicion in the title. A bare "Users +416%" filed as WARNING would
        // read as a reporting bug; the operator needs to know why it is not good news.
        if ($implausible) {
            $title .= ' — not corroborated by search clicks, check for bot traffic';
        }

        Insight::create([
            'team_id' => $team->id,
            'site' => $site,
            'site_id' => $siteId,
            'monitor_id' => $monitorId,
            'type' => $def['type'],
            'severity' => $severity,
            'title' => $title,
            'payload' => [
                'metric' => $def['metric'],
                'source' => $def['source']->value,
                'previous' => $previous,
                'current' => $current,
                'delta_pct' => $deltaPct,
                'direction' => $direction,
                'implausible_surge' => $implausible,
            ],
            'impact_score' => abs($deltaPct),
            'detected_at' => $detectedAt,
        ]);
    }

    /**
     * Is this apparent improvement too large to be credible?
     *
     * Applies to GA4 users only. Analytics counts whoever loads the page; GSC counts
     * who chose the site in a search result. When the former multiplies while the
     * latter is flat or falling, the extra sessions did not come from search, and bot
     * traffic is the usual explanation. Reporting that as a cheerful INFO
     * ("Users +416%") is worse than saying nothing.
     *
     * GSC clicks are the corroborating signal rather than impressions: impressions
     * move with Google's serving decisions, clicks with actual human intent.
     *
     * Deliberately narrow — only GA4 users, only upward, only past a large threshold.
     * A genuine campaign or viral spike will also trip it, which is acceptable: the
     * insight says "check this", not "this is bots".
     */
    private function isImplausibleSurge(string $site, string $metric, float $deltaPct): bool
    {
        if ($metric !== 'users_28d') {
            return false;
        }

        $config = config('monitoring.what_changed.implausible_surge', []);
        $surgePct = (float) ($config['surge_pct'] ?? 100);

        if ($surgePct <= 0.0 || $deltaPct < $surgePct) {
            return false;
        }

        $clicksCurrent = KpiSnapshot::averageBetween($site, KpiSource::GSC, 'clicks_28d', 7, 0);
        $clicksPrevious = KpiSnapshot::averageBetween($site, KpiSource::GSC, 'clicks_28d', 14, 7);

        // No GSC coverage to compare against — cannot judge, so stay silent rather
        // than accuse.
        if ($clicksCurrent === null || $clicksPrevious === null || $clicksPrevious <= 0.0) {
            return false;
        }

        $clicksDeltaPct = ($clicksCurrent - $clicksPrevious) / abs($clicksPrevious) * 100;
        $corroborationPct = (float) ($config['corroboration_pct'] ?? 10);

        return $clicksDeltaPct < $corroborationPct;
    }

    /**
     * Build a concise, human-readable title for a KPI change insight.
     *
     * Examples:
     *   "Clicks -23% on example.com (1,240 → 955)"
     *   "Avg position improved 8.2 → 5.1 on example.com"
     *   "CTR +12% on example.com (2.1% → 2.4%)"
     */
    private function buildTitle(
        string $label,
        string $site,
        float $previous,
        float $current,
        float $deltaPct,
        string $direction,
        string $metric,
    ): string {
        $sign = $deltaPct >= 0 ? '+' : '';
        $isPercentMetric = $metric === 'ctr_28d';
        $isPosition = $metric === 'position_28d';

        if ($isPosition) {
            // Position phrasing uses direction word rather than raw ± sign because
            // the numerical direction is confusing without context (lower = better).
            $verb = $direction === 'improvement' ? 'improved' : 'declined';

            return sprintf(
                '%s %s %.1f → %.1f on %s',
                $label,
                $verb,
                $previous,
                $current,
                $site,
            );
        }

        if ($isPercentMetric) {
            return sprintf(
                '%s %s%.1f%% on %s (%.1f%% → %.1f%%)',
                $label,
                $sign,
                $deltaPct,
                $site,
                $previous,
                $current,
            );
        }

        // Integer-style metrics (clicks, impressions, users).
        return sprintf(
            '%s %s%.0f%% on %s (%s → %s)',
            $label,
            $sign,
            $deltaPct,
            $site,
            number_format((int) round($previous)),
            number_format((int) round($current)),
        );
    }

    /**
     * Detect a probable Google core update signature.
     *
     * Heuristic: if a large fraction of the portfolio's sites all experience a
     * significant position change in the same week, it is very unlikely to be
     * site-specific work — it looks like an algorithmic ranking event. We flag it
     * as an INFO insight so the operator knows to check search news before
     * over-reacting to individual site regressions.
     *
     * @param  list<string>  $sites
     */
    private function detectCoreUpdate(
        Team $team,
        array $sites,
        int $sitesWithPositionMovement,
        array $config,
        \DateTimeInterface $detectedAt,
    ): bool {
        $minSites = (int) ($config['core_update_min_sites'] ?? 4);
        $minFraction = (float) ($config['core_update_site_fraction'] ?? 0.5);
        $totalSites = count($sites);

        if ($totalSites < $minSites) {
            return false;
        }

        $fraction = $sitesWithPositionMovement / $totalSites;

        if ($fraction < $minFraction) {
            return false;
        }

        // Wipe any previous non-acknowledged core-update insight for this team
        // so we don't accumulate one per weekly run.
        Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('type', InsightType::POSITION_CHANGE->value)
            ->where('site', 'portfolio')
            ->whereNull('acknowledged_at')
            ->delete();

        Insight::create([
            'team_id' => $team->id,
            'site' => 'portfolio',
            'monitor_id' => null,
            'type' => InsightType::POSITION_CHANGE,
            'severity' => InsightSeverity::INFO,
            'title' => 'Portfolio-wide ranking movement detected — probable Google core update',
            'payload' => [
                'sites_total' => $totalSites,
                'sites_with_position_change' => $sitesWithPositionMovement,
                'fraction' => round($fraction, 2),
            ],
            'impact_score' => round($fraction * 100, 2),
            'detected_at' => $detectedAt,
        ]);

        return true;
    }

    /**
     * Detect sites where the GA4 tag appears broken.
     *
     * A site is flagged when:
     *   - GA4 users_28d average over the last 7 days == 0 (or null / absent)
     *   - GSC clicks_28d average over the last 7 days > 0
     *
     * Real traffic is reaching the site (GSC confirms search clicks) but GA4 is
     * reporting nothing — the most likely explanation is a broken or missing tag.
     * This is surfaced as a WARNING because it silently invalidates all GA4-based
     * decisions while producing no obvious error in the app.
     *
     * @param  list<string>  $sites
     * @param  array<string, int>  $siteMonitorMap
     * @param  array<string, int|null>  $siteSiteIdMap
     * @return list<string>
     */
    private function detectBrokenGa4(
        Team $team,
        array $sites,
        array $siteMonitorMap,
        array $siteSiteIdMap,
        \DateTimeInterface $detectedAt,
    ): array {
        $brokenSites = [];

        foreach ($sites as $site) {
            $ga4Users = KpiSnapshot::averageOver($site, KpiSource::GA4, 'users_28d', 7);
            $gscClicks = KpiSnapshot::averageOver($site, KpiSource::GSC, 'clicks_28d', 7);

            // GA4 absent or zero while GSC shows real traffic → probable broken tag.
            if (($ga4Users === null || $ga4Users == 0.0) && $gscClicks !== null && $gscClicks > 0.0) {
                $brokenSites[] = $site;

                Insight::create([
                    'team_id' => $team->id,
                    'site' => $site,
                    'site_id' => $siteSiteIdMap[$site] ?? null,
                    'monitor_id' => $siteMonitorMap[$site],
                    'type' => InsightType::TRAFFIC_CHANGE,
                    'severity' => InsightSeverity::WARNING,
                    'title' => sprintf(
                        'GA4 reporting zero users while Search Console shows traffic on %s — tracking tag likely broken',
                        $site,
                    ),
                    'payload' => [
                        'gsc_clicks' => $gscClicks,
                        'ga4_users' => $ga4Users ?? 0,
                    ],
                    'impact_score' => $gscClicks,  // magnitude proxy: how much traffic is untracked
                    'detected_at' => $detectedAt,
                ]);
            }
        }

        return $brokenSites;
    }

    /**
     * Determine whether a metric delta meets the absolute volume floor.
     *
     * Volume floor rationale
     * ──────────────────────
     * Pure-percentage thresholds fire on near-zero-traffic sites: 3→2 clicks is
     * −33%, a "WARNING" that no operator can act on. This guard suppresses such
     * noise before an Insight is persisted.
     *
     * How the floor is measured
     * ─────────────────────────
     * - clicks_28d / impressions_28d / users_28d  : max(current, previous) ≥ floor.
     *   "max" is used so that a recovery (e.g. 2→15) is not filtered out: the
     *   post-recovery value shows real traffic has returned.
     *
     * - position_28d / ctr_28d : these metrics have no intrinsic "volume". A site
     *   ranked at position 50 with 1 impression is meaningless; a site ranked at
     *   position 50 with 10 000 impressions is highly actionable. We therefore proxy
     *   volume via the site's recent 7-day GSC impressions average. If impressions
     *   are below the floor (or absent), the position/ctr signal is suppressed.
     *
     * - ctr_28d, second gate : impressions alone are not enough. A rate needs a
     *   numerator. Production fired "CTR -90.9% (0.5% -> 0.0%)" on a site with 0 clicks
     *   for the whole month (one single lost click) and "CTR -43.2% (0.0% -> 0.0%)" —
     *   a change between two values that both render as zero. CTR deltas therefore also
     *   require a real click baseline (monitoring.what_changed.min_ctr_clicks).
     *
     * @param  array<string,float>  $minVolume  Keyed by metric name, sourced from config.
     */
    private function meetsVolumeFloor(
        string $site,
        string $metric,
        float $current,
        float $previous,
        array $minVolume,
    ): bool {
        $floor = (float) ($minVolume[$metric] ?? 0.0);

        // No floor configured → always passes.
        if ($floor <= 0.0) {
            return true;
        }

        if ($metric === 'position_28d' || $metric === 'ctr_28d') {
            // Proxy: use the site's most recent 7-day GSC impressions average.
            // If impressions data is absent the site has no GSC coverage — skip.
            $impressions = KpiSnapshot::averageOver($site, KpiSource::GSC, 'impressions_28d', 7);

            if ($impressions === null || $impressions < $floor) {
                return false;
            }

            // CTR needs a second, stricter gate: a rate is meaningless without a click
            // baseline. On a site sitting at 0 clicks, a single click appearing or
            // vanishing swings CTR by 100% while carrying no signal at all.
            if ($metric === 'ctr_28d') {
                $minClicks = (float) config('monitoring.what_changed.min_ctr_clicks', 10);

                if ($minClicks > 0.0) {
                    $clicks = KpiSnapshot::averageOver($site, KpiSource::GSC, 'clicks_28d', 7);

                    return $clicks !== null && $clicks >= $minClicks;
                }
            }

            return true;
        }

        // Direct metrics: max(current, previous) must reach the floor.
        return max($current, $previous) >= $floor;
    }
}
