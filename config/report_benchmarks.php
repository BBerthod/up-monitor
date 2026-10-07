<?php

/**
 * Single place for every "where does this site stand" scale shown as a gauge
 * on the periodic site report (mail + PDF). See App\Support\ReportBenchmarks
 * for the classification/geometry logic that reads this file.
 *
 * LENIENT SCALES, WIDE GREEN (owner decision, supersedes an earlier "split
 * Google's 3 zones at the midpoint" rule)
 * ─────────────────────────────────────────────────────────────────────────
 * Every metric now carries THREE EXPLICIT interior cut points (`boundaries`,
 * ascending along the raw value axis) rather than a computed good/poor split.
 * For the Google-sourced metrics (response time, Core Web Vitals, Lighthouse)
 * the green zone deliberately covers Google's own "good" zone PLUS the
 * easier half of "needs improvement" — a site does not need to hit Google's
 * strict bar to read as healthy to a non-technical client. `source` must
 * stay honest about this: "échelle construite à partir des seuils Google",
 * never "bon selon Google" (the gauge is more lenient than Google's own
 * pass/fail line).
 *
 * `direction` controls which end of the value axis is "good":
 *   - lower_is_better: low values are good.
 *   - higher_is_better: high values are good.
 *
 * BAR GEOMETRY IS FIXED, NOT PROPORTIONAL (owner decision #2)
 * ─────────────────────────────────────────────────────────────────────────
 * Every gauge bar uses the SAME four widths regardless of the metric's own
 * scale — green 45% / yellow 20% / orange 20% / red 15%, always ordered
 * green→red left to right (i.e. "good" is always on the left, whatever the
 * metric's direction). See ReportBenchmarks::FIXED_WIDTHS. `scale_min` /
 * `scale_max` are used only to clamp an out-of-range value for the marker,
 * not to size the segments.
 *
 * FREQUENCY-SCALED METRIC (incidents)
 * ─────────────────────────────────────────────────────────────────────────
 * `incidents` gives its WEEKLY boundaries/scale; `monthly_multiplier` is the
 * single number that turns them into the monthly scale (×4) — see
 * ReportBenchmarks::classifyScaled().
 *
 * WORLD COMPARISON (owner decision — supersedes an earlier "compare against
 * other Radiank sites" design, which is gone entirely)
 * ─────────────────────────────────────────────────────────────────────────
 * `world` is optional per metric, one of two forms (see ReportBenchmarks
 * docblock for how each renders) — sourced ONLY from HTTP Archive's Web
 * Almanac 2025 (July 2025 data, mobile), never invented:
 *   - ['type' => 'good_share', 'value' => float]: % of world sites meeting
 *     GOOGLE's own "good" threshold, given separately as `google_good`
 *     (a stricter line than this gauge's lenient green zone above).
 *   - ['type' => 'median', 'value' => float]: world median reading, shown as
 *     a tick mark only (no sentence — Web Almanac doesn't publish a
 *     good-share for this metric).
 * Absent (no `world` key, or `null`) means no sourced world figure exists —
 * never approximate one. Lighthouse Performance/SEO/Best Practices have none:
 * the only Web Almanac breakdown for those is 2020, before Lighthouse's
 * scoring algorithm changed, so it would not be a valid comparison today.
 *
 * A metric with no config entry, or a report with no value for it, never
 * renders a gauge — there is no synthetic fallback.
 */
return [
    'health' => [
        'label' => 'Score de santé',
        'unit' => '',
        'decimals' => 0,
        'direction' => 'higher_is_better',
        'boundaries' => [40, 55, 70],
        'scale_min' => 0,
        'scale_max' => 100,
        'source' => 'score composite Radiank (disponibilité, vitesse, référencement, alertes)',
    ],

    'uptime' => [
        'label' => 'Disponibilité',
        'unit' => '%',
        'decimals' => 2,
        'direction' => 'higher_is_better',
        'boundaries' => [98.0, 99.0, 99.5],
        'scale_min' => 97.0,
        'scale_max' => 100.0,
        'source' => 'repères usuels d\'hébergement',
    ],

    'incidents' => [
        'label' => 'Incidents',
        'unit' => 'min',
        'decimals' => 0,
        'direction' => 'lower_is_better',
        // Weekly baseline — classifyScaled() multiplies by `monthly_multiplier`
        // for a monthly report.
        'boundaries' => [30, 60, 180],
        'scale_min' => 0,
        'scale_max' => 240,
        'monthly_multiplier' => 4,
        'source' => 'repères usuels de disponibilité',
    ],

    'response_time' => [
        'label' => 'Temps de réponse serveur',
        'unit' => 'ms',
        'decimals' => 0,
        'direction' => 'lower_is_better',
        'boundaries' => [1300, 1800, 2700],
        'scale_min' => 0,
        'scale_max' => 3500,
        'source' => 'échelle construite à partir des seuils Google (web.dev, TTFB)',
        // Google's actual "good" TTFB threshold (stricter than the lenient
        // green boundary above) — used only for the world good_share sentence.
        'google_good' => 800,
        'world' => ['type' => 'good_share', 'value' => 44.0],
    ],

    'lcp' => [
        'label' => 'LCP — chargement du contenu principal',
        'unit' => 's',
        'decimals' => 1,
        'direction' => 'lower_is_better',
        'boundaries' => [3.25, 4.0, 6.0],
        'scale_min' => 0,
        'scale_max' => 8.0,
        'source' => 'échelle construite à partir des seuils Google (Core Web Vitals — LCP, données terrain CrUX p75)',
        'google_good' => 2.5,
        'world' => ['type' => 'good_share', 'value' => 62.0],
    ],

    'inp' => [
        'label' => 'INP — réactivité aux interactions',
        'unit' => 'ms',
        'decimals' => 0,
        'direction' => 'lower_is_better',
        'boundaries' => [350, 500, 750],
        'scale_min' => 0,
        'scale_max' => 1000,
        'source' => 'échelle construite à partir des seuils Google (Core Web Vitals — INP, données terrain CrUX p75)',
        'google_good' => 200,
        'world' => ['type' => 'good_share', 'value' => 77.0],
    ],

    'cls' => [
        'label' => 'CLS — stabilité visuelle',
        'unit' => '',
        'decimals' => 3,
        'direction' => 'lower_is_better',
        'boundaries' => [0.175, 0.25, 0.375],
        'scale_min' => 0,
        'scale_max' => 0.5,
        'source' => 'échelle construite à partir des seuils Google (Core Web Vitals — CLS, données terrain CrUX p75)',
        'google_good' => 0.1,
        'world' => ['type' => 'good_share', 'value' => 81.0],
    ],

    'lighthouse_performance' => [
        'label' => 'Performance mobile',
        'unit' => '',
        'decimals' => 0,
        'direction' => 'higher_is_better',
        'boundaries' => [33, 50, 70],
        'scale_min' => 0,
        'scale_max' => 100,
        'source' => 'échelle construite à partir des seuils Google (Lighthouse — Performance)',
        // No world figure: Web Almanac's only Lighthouse-performance
        // breakdown is 2020, before the scoring algorithm changed.
        'world' => null,
    ],

    'lighthouse_accessibility' => [
        'label' => 'Accessibilité',
        'unit' => '',
        'decimals' => 0,
        'direction' => 'higher_is_better',
        'boundaries' => [33, 50, 70],
        'scale_min' => 0,
        'scale_max' => 100,
        'source' => 'échelle construite à partir des seuils Google (Lighthouse — Accessibilité)',
        // Median-only: Web Almanac 2025 reports "world median accessibility
        // score passed 85" but no good-share breakdown.
        'world' => ['type' => 'median', 'value' => 85.0],
    ],

    'lighthouse_best_practices' => [
        'label' => 'Bonnes pratiques',
        'unit' => '',
        'decimals' => 0,
        'direction' => 'higher_is_better',
        'boundaries' => [33, 50, 70],
        'scale_min' => 0,
        'scale_max' => 100,
        'source' => 'échelle construite à partir des seuils Google (Lighthouse — Bonnes pratiques)',
        'world' => null, // same 2020-only caveat as Performance.
    ],

    'lighthouse_seo' => [
        'label' => 'Référencement (SEO)',
        'unit' => '',
        'decimals' => 0,
        'direction' => 'higher_is_better',
        'boundaries' => [50, 65, 80],
        'scale_min' => 0,
        'scale_max' => 100,
        'source' => 'échelle construite à partir des seuils Google (Lighthouse — SEO)',
        'world' => null, // same 2020-only caveat as Performance.
    ],

    'position' => [
        'label' => 'Position Google moyenne',
        'unit' => '',
        'decimals' => 1,
        'direction' => 'lower_is_better',
        'boundaries' => [10, 20, 40],
        'scale_min' => 1,
        'scale_max' => 50,
        'source' => 'pages de résultats Google',
    ],
];
