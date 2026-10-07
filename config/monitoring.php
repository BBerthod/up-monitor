<?php

return [
    // How long monitors are silenced after a Dokploy deploy event (in minutes).
    'deploy_silence_minutes' => (int) env('DEPLOY_SILENCE_MINUTES', 10),

    // LOT4.2 server correlation — window (seconds) during which monitor-down alerts for
    // the same server are collapsed: the first alert fires immediately, subsequent alerts
    // within the window are suppressed to avoid N-site spam when an entire server goes down.
    'server_correlation_window_seconds' => (int) env('SERVER_ALERT_CORRELATION_WINDOW', 120),

    // How long server metric rows are kept before PruneServerMetrics deletes them.
    'server_metrics_retention_days' => (int) env('SERVER_METRICS_RETENTION_DAYS', 30),

    // KPI snapshot retention. Deliberately the widest window in this file:
    // the table holds the daily health_score that trends are narrated from,
    // and the 28-day series that year-on-year comparison reads. Several sites
    // here have a seasonal shape, and a shorter window would hide last year's
    // peak exactly when it is being compared against. 400 days keeps a full
    // year plus a month of margin.
    'kpi_snapshots' => [
        'retention_days' => (int) env('KPI_SNAPSHOTS_RETENTION_DAYS', 400),
    ],

    'notification_cooldown_minutes' => env('NOTIFICATION_COOLDOWN_MINUTES', 5),

    // Anti-flapping: when a new failure occurs within this many minutes after an
    // incident was resolved, the resolved incident is REOPENED instead of creating
    // a new one. This collapses rapid down/up oscillations into a single incident
    // and prevents notification spam. Set to 0 to disable (every failure = new incident).
    'flap_window_minutes' => (int) env('FLAP_WINDOW_MINUTES', 10),

    // Business KPI regression thresholds (percentage, negative = decrease).
    // Minor: -10% over 7 days; Major: -25% over 7 days OR -40% over 30 days.
    'kpi_regression_minor_7d' => (float) env('KPI_REGRESSION_MINOR_7D', -10),
    'kpi_regression_major_7d' => (float) env('KPI_REGRESSION_MAJOR_7D', -25),
    'kpi_regression_major_30d' => (float) env('KPI_REGRESSION_MAJOR_30D', -40),

    // Composite health-score dimension weights (must sum to 1.0).
    // Override via env to tune per-environment without touching code.
    'health_score' => [
        'weights' => [
            'uptime' => (float) env('HEALTH_WEIGHT_UPTIME', 0.40),
            'seo' => (float) env('HEALTH_WEIGHT_SEO', 0.30),
            'perf' => (float) env('HEALTH_WEIGHT_PERF', 0.20),
            'ttfb' => (float) env('HEALTH_WEIGHT_TTFB', 0.10),

            // Monetization — only applied to sites that declare an ad network or
            // a merchant. On every other site this weight is REDISTRIBUTED across
            // the four dimensions above rather than counted as neutral, so the
            // four weights still sum to 1.0 on their own and a brochure site is
            // not marked down for earning nothing.
            //
            // 0.15 is deliberately below SEO: revenue is the outcome that matters
            // most, but it is also the noisiest signal and the one most often
            // missing, so it informs the grade without dominating it.
            'monetization' => (float) env('HEALTH_WEIGHT_MONETIZATION', 0.15),
        ],

        // SEO data quality settings used during KPI collection.
        'seo' => [
            // Minimum impressions for a GSC query row to be included in the
            // cleaned average position calculation inside KpiCollector::collectGsc().
            // Filters out phantom / spam queries (typically position 60-98, 0 clicks)
            // that drag position_28d down and produce an unjustified health grade of F.
            // Set to 0 to disable filtering and revert to the raw aggregate position.
            'position_min_query_impressions' => (int) env('HEALTH_SEO_POSITION_MIN_IMPRESSIONS', 10),
        ],
    ],

    // Striking-distance SEO opportunity detection thresholds.
    // Queries in position min_position..max_position with at least min_impressions
    // impressions over 28 days are scored; the top_n are surfaced as Insights.
    'striking_distance' => [
        'min_position' => (int) env('STRIKING_MIN_POSITION', 11),
        'max_position' => (int) env('STRIKING_MAX_POSITION', 20),
        'min_impressions' => (int) env('STRIKING_MIN_IMPRESSIONS', 100),
        'top_n' => (int) env('STRIKING_TOP_N', 20),
    ],

    // WhatChangedService — week-over-week KPI diff settings.
    'what_changed' => [
        // Relative change threshold (%) below which a delta is considered noise.
        'significance_pct' => (float) env('WHAT_CHANGED_SIGNIFICANCE_PCT', 10),
        // Fraction of sites that must move in position the same week to flag a probable Google core update.
        'core_update_site_fraction' => (float) env('CORE_UPDATE_SITE_FRACTION', 0.5),
        // Minimum sites required before core-update detection is meaningful.
        'core_update_min_sites' => (int) env('CORE_UPDATE_MIN_SITES', 4),

        // TRAFFIC_CHANGE declines on GSC clicks and GA4 users must reach this relative
        // drop (%). GSC impressions declines never fire (Google's count drifts freely).
        'traffic_change' => [
            'min_decline_pct' => (float) env('WHAT_CHANGED_TRAFFIC_MIN_DECLINE_PCT', 30),
        ],

        // Absolute volume floor per metric — a delta is ignored unless max(current, previous)
        // reaches this value. Kills noise from near-zero-traffic sites where a move from 3→2
        // clicks (-33%) fires a WARNING that carries no actionable signal.
        //
        // For position_28d and ctr_28d these metrics have no "volume" of their own;
        // the floor is checked against the site's GSC impressions (the proxy that best
        // indicates whether the ranking signal is statistically meaningful).
        'min_volume' => [
            'clicks_28d' => (float) env('WHAT_CHANGED_MIN_CLICKS', 10),
            'impressions_28d' => (float) env('WHAT_CHANGED_MIN_IMPRESSIONS', 100),
            'users_28d' => (float) env('WHAT_CHANGED_MIN_USERS', 50),
            'position_28d' => (float) env('WHAT_CHANGED_MIN_POSITION_IMPRESSIONS', 30),

            // 100 impressions over 28 days (≈ 3/day) proved an order of magnitude too
            // low: garden-site-b.fr fired "CTR -90.9% (0.5% -> 0.0%)" off a SINGLE
            // lost click (255 impressions / 28 days ≈ 9/day, 0 clicks all month), and
            // reviewsite.com fired "CTR -43.2% (0.0% -> 0.0%)" — a percentage change
            // between two values that both display as zero. 1000 impressions over the
            // window (≈ 36/day) is the point where one click stops moving CTR by tens
            // of percent. See also min_clicks below, which is the real gate.
            'ctr_28d' => (float) env('WHAT_CHANGED_MIN_CTR_IMPRESSIONS', 1000),
        ],

        // Additional gate for ctr_28d only: a click-through RATE is uninterpretable
        // without clicks to rate. Impressions alone let a 1 -> 0 click move register as
        // a -100% CTR collapse. Requiring a real click baseline is what actually kills
        // this class of false positive; the impression floor above only bounds it.
        'min_ctr_clicks' => (float) env('WHAT_CHANGED_MIN_CTR_CLICKS', 10),

        // IMPLAUSIBLE TRAFFIC SURGE
        // A jump in GA4 users is only good news if search demand moved with it. When
        // users explode while GSC clicks stay flat or fall, the extra sessions did not
        // come from search — the usual explanation is bot traffic, and filing that as a
        // cheerful INFO actively misleads the operator.
        //
        // Measured in production on webcompare.com: users +359% (1669 -> 7662) while GSC
        // clicks went -14% (5 -> 4.3) and impressions barely moved (+3.6%). The detector
        // reported "Users +416%" as INFO.
        //
        // A surge at or above surge_pct whose corroborating GSC clicks fail to rise by at
        // least corroboration_pct is re-filed as WARNING — to be investigated, not
        // celebrated. Set surge_pct to 0 to disable.
        'implausible_surge' => [
            'surge_pct' => (float) env('WHAT_CHANGED_SURGE_PCT', 100),
            'corroboration_pct' => (float) env('WHAT_CHANGED_SURGE_CORROBORATION_PCT', 10),
        ],
    ],

    // SEO alerts — push WARNING/CRITICAL insights to notification channels.
    'seo_alerts' => [
        // Max SEO alerts dispatched per team per run (anti-spam). CRITICAL prioritised.
        'max_per_run' => (int) env('SEO_ALERTS_MAX_PER_RUN', 10),

        // Insight types allowed to reach an EMAIL channel. Only one channel type is
        // active in production today, and every other insight category (SEO, revenue,
        // affiliation, warming...) already has an independent visibility path via the
        // Vikunja inbox projection, notified_at or not. Restricting to the genuinely
        // time-critical categories (a server in distress, a site down, or a critical
        // scheduled task no longer running) keeps email as an interrupt channel instead
        // of a daily digest nobody reads.
        // Non-email channel types (Slack, webhook, ...) are not restricted by this list.
        'email_alertable_types' => [
            \App\Enums\InsightType::SERVER_HEALTH->value,
            \App\Enums\InsightType::UPTIME_INCIDENT->value,
            \App\Enums\InsightType::HEARTBEAT_MISSED->value,
        ],
    ],

    // Content decay detection — identifies pages losing organic traffic over time.
    // Decay is measured by comparing the AVERAGE of the last comparison_period_days
    // of snapshots ("current") against the average of the older snapshots within
    // the look-back window ("baseline") — a rolling 4-week-vs-4-week style
    // comparison, not two single points. See ContentDecayService docblock.
    'content_decay' => [
        // Minimum impressions (per collection run) required to track a page.
        // Pages below this threshold produce unreliable decay signals.
        'min_impressions' => (int) env('DECAY_MIN_IMPRESSIONS', 50),

        // How far back (days) to look for the baseline snapshots (12 weeks default).
        'window_days' => (int) env('DECAY_WINDOW_DAYS', 84),

        // Length (days) of each averaging window compared against the other —
        // "current" is the last N days, "baseline" is everything older than
        // that within window_days. Mirrors GSC's own 4-week comparison view.
        'comparison_period_days' => (int) env('DECAY_COMPARISON_PERIOD_DAYS', 28),

        // Minimum age gap (days) between the oldest baseline and newest current
        // snapshot before raising a decay signal — prevents false positives
        // during bootstrap.
        'min_history_days' => (int) env('DECAY_MIN_HISTORY_DAYS', 14),

        // Click decline percentage (relative) required to flag a page as decayed.
        // Must coincide with an absolute impressions drop to filter CTR-only shifts.
        'threshold_pct' => (float) env('DECAY_THRESHOLD_PCT', 30),

        // Minimum average baseline clicks required before a decline is even
        // considered. Below this, losing a single click reads as "-100 %"
        // without being a meaningful content-decay signal (e.g. 1 → 0 clicks).
        'min_click_volume' => (float) env('DECAY_MIN_CLICK_VOLUME', 5),

        // Baseline clicks per WEEK required (page_metrics rows are 28-day aggregates,
        // so the baseline average is divided by comparison_period_days / 7).
        'min_weekly_clicks' => (float) env('DECAY_MIN_WEEKLY_CLICKS', 10),

        // The page must lose at least this many percentage points MORE than the whole
        // site's GSC clicks lost over the same windows (kpi_snapshots clicks_28d).
        // A page at -74 % while the site is at -80 % is seasonality, not decay.
        // Ignored when the site has no usable history.
        'site_relative_margin' => (float) env('DECAY_SITE_RELATIVE_MARGIN', 20),
    ],

    // Lighthouse performance regression detection.
    //
    // The score thresholds are hardcoded in PerfRegressionDetector (10 pts = WARNING,
    // 20 pts = CRITICAL); what lives here is the NOISE FLOOR for the Core Web Vitals.
    //
    // Without a floor, any upward tick of a metric already sitting above Google's
    // "poor" threshold fires an insight — production produced titles such as
    // "LCP worsened (4953 → 4953)" (delta 0 ms) and "(4080 → 4082)" (2 ms), which
    // drowned the real signals. A vital must now worsen by BOTH a relative and an
    // absolute amount to count: relative alone flags 2 ms moves on fast pages,
    // absolute alone flags irrelevant 300 ms moves on a 12 s page.
    'perf_regression' => [
        // BASELINE. The latest audits are judged against the MEDIAN of the baseline_runs
        // audits that precede them (at least min_baseline_runs, otherwise the delta rules
        // stay silent), and the score / LCP / CLS must be degraded past the thresholds
        // below in ALL of the last consecutive_runs audits. A single noisy PSI run is
        // neither a baseline nor a verdict. The absolute LCP level alert is unaffected.
        'baseline_runs' => (int) env('PERF_BASELINE_RUNS', 5),
        'min_baseline_runs' => (int) env('PERF_MIN_BASELINE_RUNS', 3),
        'consecutive_runs' => (int) env('PERF_CONSECUTIVE_RUNS', 2),

        // LCP must worsen by at least this share of the previous value (%) AND by
        // at least min_delta_lcp_ms in absolute terms.
        'min_delta_pct' => (float) env('PERF_MIN_DELTA_PCT', 10),
        'min_delta_lcp_ms' => (float) env('PERF_MIN_DELTA_LCP_MS', 300),

        // CLS is unitless and small; 0.02 is the smallest shift a user can perceive.
        'min_delta_cls' => (float) env('PERF_MIN_DELTA_CLS', 0.02),

        // The composite score must worsen by at least min_delta_pct (points, shared
        // with the WARNING threshold constant of 10) AND by at least this share of
        // the previous score (%). Deliberately HIGHER than min_delta_pct above: the
        // score is bounded to [0, 100], so a relative floor expressed in the same
        // percentage as an absolute-points threshold of 10 could never bind (10% of
        // a ≤100 score is at most 10 points, i.e. never stricter than the absolute
        // gate). A distinct, higher default is what actually filters the PSI mobile
        // score's run-to-run lab jitter on already-good sites (e.g. 92 → 82, a
        // "real-looking" 10-point WARNING that is only 10.9% of its base).
        'min_delta_score_pct' => (float) env('PERF_MIN_DELTA_SCORE_PCT', 15),

        // ABSOLUTE LEVEL ALERT (independent of any delta).
        //
        // A delta-only detector is blind to a page that is catastrophically slow but
        // stable: garden-site-a.fr sits at ~16 s LCP and jokes.example at ~12 s, and
        // neither ever alerted — while 2 ms "regressions" filled the inbox. Level and
        // trend are different questions and both deserve an answer.
        //
        // Set to 0 to disable. 8000 ms is 2x Google's "poor" threshold: deliberately
        // conservative so this fires on the genuinely broken, not the merely slow.
        'critical_lcp_ms' => (float) env('PERF_CRITICAL_LCP_MS', 8000),

        // The LCP above is a SIMULATED lab value: on an underpowered PSI worker it can
        // read 12 s for a page the same run actually painted in 1.5 s. The CRITICAL is
        // only raised when the run's observed LCP confirms it (rows without an observed
        // value keep the old behaviour).
        'critical_lcp_observed_ms' => (float) env('PERF_CRITICAL_LCP_OBSERVED_MS', 4000),

        // HOST COMPARABILITY. PSI runs land on workers of very different CPU power
        // (lighthouseResult.environment.benchmarkIndex): the same unchanged page scored
        // LCP 4.1 s on a 1352 worker and 12.2 s on a 402 one. Delta rules (score, LCP)
        // are skipped when the current worker is slower than this share of the previous
        // one — the two runs measure the hardware, not the page. 0 disables the guard.
        'min_benchmark_ratio' => (float) env('PERF_MIN_BENCHMARK_RATIO', 0.7),
    ],

    // Health-grade drop detection (HealthDropDetector).
    'health_drop' => [
        // Minimum score delta required before a grade change is reported.
        // Grade boundaries are hard cut-offs every 10 points, so a 1-point move can
        // straddle one (91 → 89 = "A -> B") and fire a WARNING that carries no
        // information. This hysteresis suppresses boundary jitter while leaving real
        // degradations untouched.
        'min_score_delta' => (float) env('HEALTH_DROP_MIN_SCORE_DELTA', 3),
    ],

    // Per-keyword rank tracking, built on keyword_metrics.
    //
    // The GSC query rows behind this were already being fetched on every run and
    // thrown away after aggregation, so a site's average position was knowable
    // but "which keyword did we lose, and when" was not.
    'keyword_tracking' => [
        // Impressions below which a keyword row is not even stored. Deliberately
        // far lower than the page-level filter: a keyword row is one point in a
        // series rather than a signal on its own, and long-tail queries are what
        // a rank tracker exists to follow. This only drops single-impression
        // noise that would otherwise inflate the table for nothing.
        'min_impressions' => (float) env('KEYWORD_MIN_IMPRESSIONS', 3),

        // Same rolling-window shape as content decay: recent 28-day captures
        // versus the older captures available in an 84-day look-back.
        'window_days' => (int) env('KEYWORD_WINDOW_DAYS', 84),
        'comparison_period_days' => (int) env('KEYWORD_COMPARISON_PERIOD_DAYS', 28),

        // Minimum average baseline impressions per capture before a drop is
        // worth reporting. Gated on the BASELINE, not the current value: the
        // question is whether the keyword used to matter, and gating on current
        // impressions would silence exactly the keywords that collapsed.
        'drop_min_impressions' => (float) env('KEYWORD_DROP_MIN_IMPRESSIONS', 100),

        // Minimum positions lost before reporting. Below ~5 the move is mostly
        // GSC averaging noise across a 28-day window.
        'drop_min_positions' => (float) env('KEYWORD_DROP_MIN_POSITIONS', 5),

        // A page-one exit is CRITICAL only when average clicks also fell by
        // this percentage; rank volatility without traffic loss stays WARNING.
        'drop_critical_click_decline_pct' => (float) env('KEYWORD_DROP_CRITICAL_CLICK_DECLINE_PCT', 30),

        // A drop is only reported when the keyword stood at or above this position
        // BEFORE the fall, and the loss is significant: at least drop_min_clicks_lost
        // average clicks lost, or baseline impressions >= drop_significant_impressions.
        'drop_max_from_position' => (float) env('KEYWORD_DROP_MAX_FROM_POSITION', 20),
        'drop_min_clicks_lost' => (float) env('KEYWORD_DROP_MIN_CLICKS_LOST', 5),
        'drop_significant_impressions' => (float) env('KEYWORD_DROP_SIGNIFICANT_IMPRESSIONS', 500),

        // Keywords listed in a single drop insight, worst first.
        'drop_top_n' => (int) env('KEYWORD_DROP_TOP_N', 10),

        // Combined impressions required before flagging cannibalisation. Two
        // pages splitting a query nobody searches for is not a problem.
        'cannibal_min_impressions' => (float) env('KEYWORD_CANNIBAL_MIN_IMPRESSIONS', 100),
        'cannibal_top_n' => (int) env('KEYWORD_CANNIBAL_TOP_N', 10),

        // Queries whose best position is at or above this rank are ignored: the
        // site already holds the top of the results (brand queries, language variants).
        'cannibal_ignore_best_position' => (float) env('KEYWORD_CANNIBAL_IGNORE_BEST_POSITION', 3),

        // Retention. Wider than the 28-day comparison window so year-on-year
        // look-backs stay possible without the table growing without bound.
        'retention_days' => (int) env('KEYWORD_RETENTION_DAYS', 400),
    ],

    // Zombie pages — published but never seen in search.
    //
    // ContentDecayService skips anything whose baseline is zero, so a page that
    // NEVER had traffic is invisible to it by construction. The pages that get
    // attention are the ones that used to work; the ones that never worked at
    // all are the ones nobody hears about.
    'zombie_pages' => [
        // Tracking history required before judging. A site whose collection
        // started last week cannot tell a zombie from a page that has not had
        // time to rank.
        'min_history_days' => (int) env('ZOMBIE_MIN_HISTORY_DAYS', 30),

        // Both floors must be cleared before reporting. Ratio alone fires on a
        // three-page site with one quiet page; count alone fires on a large site
        // where the same absolute number is a rounding error.
        'min_ratio' => (float) env('ZOMBIE_MIN_RATIO', 0.5),
        'min_count' => (int) env('ZOMBIE_MIN_COUNT', 5),

        // Sites publishing more pages than this are programmatic catalogues, where
        // de-indexation is a known state rather than a per-site finding. Skipped.
        'max_published' => (int) env('ZOMBIE_MAX_PUBLISHED', 1000),

        // URLs listed in the insight payload. The count above conveys the scale;
        // the sample is there to start looking.
        'sample_size' => (int) env('ZOMBIE_SAMPLE_SIZE', 10),

        // Shared traversal ceiling. Webcompare.de already exposes 227 child
        // sitemaps, so 1,000 covers the current portfolio in full while still
        // preventing an accidental or malicious unbounded crawl.
        'max_child_sitemaps' => (int) env('ZOMBIE_MAX_CHILD_SITEMAPS', 1000),
    ],

    // Broken-page detection — cross-references high-traffic GSC pages with their
    // real HTTP status to surface "revenue at risk" when an earner is broken.
    'broken_pages' => [
        // Minimum monthly clicks required before a page is worth probing.
        // Pages below this threshold have negligible traffic impact.
        'min_clicks' => (int) env('BROKEN_MIN_CLICKS', 5),

        // Maximum pages tested per monitor per run — caps outbound HTTP requests
        // so a monitor with thousands of pages doesn't overwhelm the queue worker.
        'max_pages_per_monitor' => (int) env('BROKEN_MAX_PAGES', 30),

        // Click threshold above which a broken page triggers CRITICAL severity.
        // Below this threshold the insight is WARNING.
        'high_traffic_clicks' => (int) env('BROKEN_HIGH_TRAFFIC_CLICKS', 50),
    ],

    // Affiliate redirect health — walks the outbound /go/{ASIN} hop itself.
    //
    // This is the blind spot that let 100% of affiliate links 404 for days in
    // production: uptime checks the site root (200), BrokenPageService only
    // probes pages GSC knows about (a redirect endpoint has no impressions), and
    // AffiliateAuditService filters to amazon.* hosts (a /go/ link is internal).
    'affiliate_redirects' => [
        // How a redirect endpoint is recognised in page markup. Kept configurable
        // because the path prefix is a site convention, not a standard.
        'path_pattern' => (string) env('AFFILIATE_REDIRECT_PATTERN', '#/go/[A-Za-z0-9]+#i'),

        // Pages scanned per run to harvest redirect links. Small on purpose: a
        // handful of top pages yields plenty of links, and one broken rewrite
        // rule breaks them all — no need to crawl wide to find it.
        'max_pages_per_monitor' => (int) env('AFFILIATE_REDIRECT_MAX_PAGES', 10),

        // Hard cap on redirects probed per run, bounding outbound requests.
        'max_links_per_run' => (int) env('AFFILIATE_REDIRECT_MAX_LINKS', 25),

        // Minimum number of conclusive probes required before opening or
        // resolving an alert. Smaller samples are treated as indeterminate.
        'min_sample' => (int) env('AFFILIATE_REDIRECT_MIN_SAMPLE', 3),

        // Optional shared secret understood by monitored /go/ endpoints. When
        // present, it bypasses browser proof and returns the redirect directly.
        'monitor_token' => env('AFFILIATE_MONITOR_TOKEN'),

        // Failure share at or above which the break is treated as systemic
        // (CRITICAL) rather than a few stale ASINs (WARNING). Half of a sample
        // failing cannot be explained by individual dead products.
        'systemic_failure_ratio' => (float) env('AFFILIATE_REDIRECT_SYSTEMIC_RATIO', 0.5),
    ],

    // Sitemap health — freshness and canonical-status audit.
    //
    // Both thresholds come from real breakages in this fleet: a sitemap served
    // with lastmod frozen at 2023 for two and a half years (cached file committed
    // to the repo and restored on every deploy), and one where 83% of URLs
    // answered 301 after a slug migration was never followed by a regeneration.
    'sitemap_health' => [
        // URLs probed per audit. The signal is a ratio, so a few dozen samples
        // answer it — fetching thousands would only cost time.
        'sample_size' => (int) env('SITEMAP_SAMPLE_SIZE', 40),

        // Age of the NEWEST lastmod above which the file is considered stale.
        // A year: a lastmod can be perfectly accurate on a site whose content has
        // not changed, so age alone is only ever an INFO hint, and only past this
        // threshold. WARNING/CRITICAL need a real signal (redirects/broken URLs).
        'max_lastmod_age_days' => (int) env('SITEMAP_MAX_LASTMOD_AGE_DAYS', 365),

        // Age above which staleness COMBINED with an unhealthy URL sample is
        // CRITICAL rather than WARNING.
        'critical_age_days' => (int) env('SITEMAP_CRITICAL_AGE_DAYS', 180),

        // Share of sampled URLs allowed to answer 3xx. A sitemap is a statement
        // of canonical URLs: a redirect in it hands the crawler an address the
        // site itself says is wrong. A few are normal churn; a fifth is not.
        'max_redirect_ratio' => (float) env('SITEMAP_MAX_REDIRECT_RATIO', 0.2),

        // Share allowed to answer 4xx/5xx. Much stricter — these lead nowhere.
        'max_broken_ratio' => (float) env('SITEMAP_MAX_BROKEN_RATIO', 0.05),

        // Total child sitemaps visited across the whole index tree. This covers
        // the current large portfolio indexes (Webcompare.de: 227 children) while
        // retaining a hard network budget. Above the cap, files are selected
        // evenly across the index instead of taking a permanently biased prefix.
        'max_child_sitemaps' => (int) env('SITEMAP_MAX_CHILDREN', 1000),
    ],

    // Affiliate commission leak detection — crawls product pages, extracts Amazon
    // associate tags from dp/ links, and flags leaks (wrong tag) or losses (no tag).
    'affiliate' => [
        // Minimum monthly impressions before a page is audited for affiliate leaks.
        // Impressions (not clicks) are used: an indexed page already exposes its
        // affiliate links, so a wrong/missing tag should be caught before it earns
        // clicks. Low-impression pages are skipped to save queue budget.
        'min_impressions' => (int) env('AFFILIATE_MIN_IMPRESSIONS', 10),

        // Maximum pages audited per monitor per run — caps outbound HTTP requests
        // so a monitor with thousands of pages doesn't overwhelm the queue worker.
        'max_pages_per_monitor' => (int) env('AFFILIATE_MAX_PAGES', 30),

        // Click threshold above which an affiliate leak triggers CRITICAL severity.
        // Below this threshold the insight is WARNING.
        'high_traffic_clicks' => (int) env('AFFILIATE_HIGH_TRAFFIC_CLICKS', 50),
    ],

    // Server resource thresholds — used by ServerHealthDetector to create SERVER_HEALTH
    // Insights when a server metric crosses a warning or critical boundary.
    // These Insights are then picked up automatically by SeoAlertService.
    'server_health' => [
        // Disk usage thresholds (% used). CRITICAL when nearly full.
        'disk_warning' => (float) env('SERVER_DISK_WARNING', 85),
        'disk_critical' => (float) env('SERVER_DISK_CRITICAL', 92),

        // RAM usage thresholds (% used).
        'ram_warning' => (float) env('SERVER_RAM_WARNING', 88),
        'ram_critical' => (float) env('SERVER_RAM_CRITICAL', 95),

        // CPU thresholds (% used). Requires sustained breach to avoid spike noise.
        'cpu_warning' => (float) env('SERVER_CPU_WARNING', 90),
        'cpu_critical' => (float) env('SERVER_CPU_CRITICAL', 97),

        // Number of consecutive recent metrics that must ALL breach the CPU threshold
        // before alerting (filters out momentary spikes). Disk/RAM alert immediately.
        'cpu_sustained_points' => (int) env('SERVER_CPU_SUSTAINED_POINTS', 3),
        'load_warning' => (float) env('SERVER_LOAD_WARNING', 0.85),
        'load_critical' => (float) env('SERVER_LOAD_CRITICAL', 1.0),
        'load_cores_default' => (int) env('SERVER_LOAD_CORES_DEFAULT', 0),
    ],
];
