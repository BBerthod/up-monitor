<?php

namespace App\Support;

/**
 * Classifies a metric value against config('report_benchmarks') into one of
 * four bands (red/orange/yellow/green) and computes the geometry an
 * email-safe, dompdf-safe table-based gauge needs.
 *
 * See config/report_benchmarks.php for what the boundaries mean and why the
 * scales are deliberately lenient — this class only does the arithmetic.
 *
 * FIXED BAR WIDTHS (owner decision)
 * ──────────────────────────────────
 * Every bar uses the same four widths (self::FIXED_WIDTHS), always ordered
 * green→red left to right regardless of the metric's direction — "good" is
 * always on the left. The marker's position is a linear interpolation of the
 * value WITHIN its own band, mapped onto that band's fixed-width span (never
 * a single interpolation across the whole scale — that would make the wide
 * green segment visually meaningless).
 *
 * WORLD COMPARISON — TWO FORMS ONLY (owner decision, supersedes an earlier
 * percentile-knot interpolation design that no sourced data ever used)
 * ─────────────────────────────────────────────────────────────────────────
 * `config('report_benchmarks.{key}.world')`, when present, is one of:
 *   - ['type' => 'good_share', 'value' => float]: the % of world sites that
 *     meet GOOGLE's own "good" threshold (config `google_good`, NOT this
 *     gauge's lenient green boundary). No marker — SiteReportService turns
 *     this into a sentence, worded differently depending on whether THIS
 *     site itself clears `google_good`.
 *   - ['type' => 'median', 'value' => float]: the world's median reading —
 *     rendered as a "Moyenne mondiale" tick, positioned with the exact same
 *     band+interpolation logic as the site's own marker (see
 *     markerPositionFor()). No sentence.
 * A metric with no `world` config renders no world comparison at all — this
 * class never fabricates one.
 *
 * Never fabricates a gauge, either: classify() returns null for an
 * unconfigured metric key or a null value.
 */
final class ReportBenchmarks
{
    /** green, yellow, orange, red — always in this left-to-right order. */
    private const FIXED_WIDTHS = [45.0, 20.0, 20.0, 15.0];

    private const BAND_COLORS = ['green', 'yellow', 'orange', 'red'];

    private const BAND_LABELS = ['Très bien', 'Correct', 'À améliorer', 'Critique'];

    /**
     * @return array{
     *   metric_key: string,
     *   label: string,
     *   value: float,
     *   unit: string,
     *   decimals: int,
     *   source: string,
     *   color: string,
     *   band_label: string,
     *   marker_pct: float,
     *   segments: list<array{color: string, width_pct: float}>,
     *   world: array{type: 'median', marker_pct: float}
     *        | array{type: 'good_share', share: float, within_good: bool}
     *        | null,
     * }|null
     */
    public static function classify(string $metricKey, ?float $value): ?array
    {
        return self::classifyScaled($metricKey, $value, 1.0);
    }

    /**
     * Same as classify(), but every boundary/scale bound is multiplied by
     * $scale first — used by the `incidents` gauge to turn its weekly
     * baseline into a monthly one (scale = config('...incidents.monthly_multiplier')).
     * $scale = 1.0 (the default via classify()) leaves the config untouched.
     */
    public static function classifyScaled(string $metricKey, ?float $value, float $scale): ?array
    {
        if ($value === null) {
            return null;
        }

        $config = config("report_benchmarks.{$metricKey}");

        if (! is_array($config)) {
            return null;
        }

        $direction = $config['direction'];
        $higherIsBetter = $direction === 'higher_is_better';
        $scaleMin = (float) $config['scale_min'] * $scale;
        $scaleMax = (float) $config['scale_max'] * $scale;
        $boundaries = array_map(static fn ($b) => (float) $b * $scale, $config['boundaries']);

        [$bandIndex, $markerPct] = self::markerPositionFor($value, $higherIsBetter, $boundaries, $scaleMin, $scaleMax);

        $segments = [];
        foreach (self::BAND_COLORS as $i => $color) {
            $segments[] = ['color' => $color, 'width_pct' => self::FIXED_WIDTHS[$i]];
        }

        return [
            'metric_key' => $metricKey,
            'label' => $config['label'],
            'value' => $value,
            'unit' => $config['unit'],
            'decimals' => $config['decimals'],
            'source' => $config['source'],
            'color' => self::BAND_COLORS[$bandIndex],
            'band_label' => self::BAND_LABELS[$bandIndex],
            'marker_pct' => $markerPct,
            'segments' => $segments,
            'world' => self::worldComparison($config, $value, $higherIsBetter, $boundaries, $scaleMin, $scaleMax),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function worldComparison(
        array $config,
        float $value,
        bool $higherIsBetter,
        array $boundaries,
        float $scaleMin,
        float $scaleMax,
    ): ?array {
        $world = $config['world'] ?? null;

        if (! is_array($world) || $world['value'] === null) {
            return null;
        }

        if ($world['type'] === 'median') {
            [, $markerPct] = self::markerPositionFor((float) $world['value'], $higherIsBetter, $boundaries, $scaleMin, $scaleMax);

            return ['type' => 'median', 'marker_pct' => $markerPct];
        }

        // 'good_share': compared against GOOGLE's own good threshold, which
        // is a stricter line than this gauge's lenient green zone.
        $googleGood = (float) $config['google_good'];
        $withinGood = $higherIsBetter ? $value >= $googleGood : $value <= $googleGood;

        return ['type' => 'good_share', 'share' => (float) $world['value'], 'within_good' => $withinGood];
    }

    /**
     * Shared geometry: which of the 4 fixed-width bands $value falls into,
     * and its marker position (%) within that band. Used both for the
     * site's own value and for a world median value, so the two markers are
     * always computed the exact same way.
     *
     * @param  list<float>  $boundaries  3 interior cut points, ascending.
     * @return array{0: int, 1: float} [bandIndex 0-3, marker_pct 0-100]
     */
    private static function markerPositionFor(
        float $value,
        bool $higherIsBetter,
        array $boundaries,
        float $scaleMin,
        float $scaleMax,
    ): array {
        [$b1, $b2, $b3] = $boundaries;
        $clampedValue = max($scaleMin, min($scaleMax, $value));

        if ($higherIsBetter) {
            // Band 0 (green) = the HIGH end of the axis; every band's
            // "better" edge is therefore its high (`hi`) bound.
            $bandIndex = match (true) {
                $clampedValue >= $b3 => 0,
                $clampedValue >= $b2 => 1,
                $clampedValue >= $b1 => 2,
                default => 3,
            };
            $bandRanges = [[$b3, $scaleMax], [$b2, $b3], [$b1, $b2], [$scaleMin, $b1]];
        } else {
            // Band 0 (green) = the LOW end of the axis; every band's
            // "better" edge is therefore its low (`lo`) bound.
            $bandIndex = match (true) {
                $clampedValue <= $b1 => 0,
                $clampedValue <= $b2 => 1,
                $clampedValue <= $b3 => 2,
                default => 3,
            };
            $bandRanges = [[$scaleMin, $b1], [$b1, $b2], [$b2, $b3], [$b3, $scaleMax]];
        }

        [$lo, $hi] = $bandRanges[$bandIndex];
        $span = $hi - $lo;
        $fractionFromBetterEdge = $span > 0.0
            ? ($higherIsBetter ? ($hi - $clampedValue) : ($clampedValue - $lo)) / $span
            : 0.0;
        $fractionFromBetterEdge = max(0.0, min(1.0, $fractionFromBetterEdge));

        $segmentStart = array_sum(array_slice(self::FIXED_WIDTHS, 0, $bandIndex));
        $segmentWidth = self::FIXED_WIDTHS[$bandIndex];
        $markerPct = $segmentStart + $fractionFromBetterEdge * $segmentWidth;

        return [$bandIndex, round(max(0.0, min(100.0, $markerPct)), 2)];
    }
}
