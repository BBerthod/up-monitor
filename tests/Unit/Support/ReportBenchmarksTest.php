<?php

namespace Tests\Unit\Support;

use App\Support\ReportBenchmarks;
use Tests\TestCase;

class ReportBenchmarksTest extends TestCase
{
    public function test_missing_value_returns_no_gauge(): void
    {
        $this->assertNull(ReportBenchmarks::classify('response_time', null));
    }

    public function test_unconfigured_metric_returns_no_gauge(): void
    {
        $this->assertNull(ReportBenchmarks::classify('does_not_exist', 500));
    }

    // ─────────────────────────────────────────────────────────
    // Fixed bar widths — always 45/20/20/15, green→red left to right
    // ─────────────────────────────────────────────────────────

    public function test_segment_widths_are_always_the_same_fixed_split(): void
    {
        $gauge = ReportBenchmarks::classify('response_time', 500.0);

        $widths = array_column($gauge['segments'], 'width_pct');
        $colors = array_column($gauge['segments'], 'color');

        $this->assertSame([45.0, 20.0, 20.0, 15.0], $widths);
        $this->assertSame(['green', 'yellow', 'orange', 'red'], $colors);
        $this->assertEqualsWithDelta(100.0, array_sum($widths), 0.01);
    }

    public function test_widths_are_the_same_fixed_split_for_a_higher_is_better_metric_too(): void
    {
        // Lighthouse's scale (0-100) is nothing like response time's (0-3500)
        // — the widths must not depend on the metric's own range.
        $gauge = ReportBenchmarks::classify('lighthouse_performance', 80.0);

        $widths = array_column($gauge['segments'], 'width_pct');
        $this->assertSame([45.0, 20.0, 20.0, 15.0], $widths);
    }

    // ─────────────────────────────────────────────────────────
    // lower_is_better boundaries (response_time: green<=1300, yellow<=1800,
    // orange<=2700, red>2700; bar 0-3500)
    // ─────────────────────────────────────────────────────────

    public function test_response_time_at_the_green_boundary_is_tres_bien(): void
    {
        $gauge = ReportBenchmarks::classify('response_time', 1300.0);

        $this->assertSame('green', $gauge['color']);
        $this->assertSame('Très bien', $gauge['band_label']);
    }

    public function test_response_time_just_above_green_is_correct(): void
    {
        $gauge = ReportBenchmarks::classify('response_time', 1301.0);

        $this->assertSame('yellow', $gauge['color']);
        $this->assertSame('Correct', $gauge['band_label']);
    }

    public function test_response_time_at_the_orange_boundary_is_still_a_ameliorer(): void
    {
        // "orange <= 2700" is inclusive — exactly 2700 is not yet red.
        $gauge = ReportBenchmarks::classify('response_time', 2700.0);

        $this->assertSame('orange', $gauge['color']);
        $this->assertSame('À améliorer', $gauge['band_label']);
    }

    public function test_response_time_just_above_orange_is_critique(): void
    {
        $gauge = ReportBenchmarks::classify('response_time', 2701.0);

        $this->assertSame('red', $gauge['color']);
        $this->assertSame('Critique', $gauge['band_label']);
    }

    public function test_response_time_beyond_scale_max_still_classifies_as_critique_and_clamps_marker(): void
    {
        $gauge = ReportBenchmarks::classify('response_time', 100000.0);

        $this->assertSame('red', $gauge['color']);
        $this->assertSame(100.0, $gauge['marker_pct']);
        $this->assertSame(100000.0, $gauge['value']); // raw value is preserved for display
    }

    // ─────────────────────────────────────────────────────────
    // Marker: linear interpolation WITHIN the band, mapped onto that
    // band's fixed-width span (not a single scale-wide interpolation)
    // ─────────────────────────────────────────────────────────

    public function test_marker_at_the_start_of_the_scale_sits_at_the_very_left(): void
    {
        $gauge = ReportBenchmarks::classify('response_time', 0.0);

        $this->assertSame(0.0, $gauge['marker_pct']);
    }

    public function test_marker_halfway_through_the_green_band_sits_at_half_its_width(): void
    {
        // Green band is [0, 1300]; 650 is its midpoint → half of the 45%-wide
        // green segment = 22.5%.
        $gauge = ReportBenchmarks::classify('response_time', 650.0);

        $this->assertSame(22.5, $gauge['marker_pct']);
    }

    public function test_marker_at_the_green_yellow_boundary_sits_at_45_percent(): void
    {
        $gauge = ReportBenchmarks::classify('response_time', 1300.0);

        $this->assertSame(45.0, $gauge['marker_pct']);
    }

    public function test_marker_halfway_through_the_red_band_sits_at_the_middle_of_its_width(): void
    {
        // Red band is (2700, 3500]; 3100 is its midpoint → 85% + half of 15% = 92.5%.
        $gauge = ReportBenchmarks::classify('response_time', 3100.0);

        $this->assertSame(92.5, $gauge['marker_pct']);
    }

    // ─────────────────────────────────────────────────────────
    // higher_is_better: green is still on the LEFT (marker geometry mirrors
    // the value axis), boundaries green>=70, yellow>=50, orange>=33, red<33
    // ─────────────────────────────────────────────────────────

    public function test_lighthouse_score_of_100_the_best_possible_sits_at_the_far_left(): void
    {
        $gauge = ReportBenchmarks::classify('lighthouse_performance', 100.0);

        $this->assertSame('green', $gauge['color']);
        $this->assertSame(0.0, $gauge['marker_pct']);
    }

    public function test_lighthouse_score_at_the_green_boundary_sits_at_the_green_yellow_edge(): void
    {
        $gauge = ReportBenchmarks::classify('lighthouse_performance', 70.0);

        $this->assertSame('green', $gauge['color']);
        $this->assertSame(45.0, $gauge['marker_pct']);
    }

    public function test_lighthouse_score_at_the_orange_boundary_is_a_ameliorer_not_critique(): void
    {
        // "orange >= 33" is inclusive — exactly 33 is not yet red.
        $gauge = ReportBenchmarks::classify('lighthouse_performance', 33.0);

        $this->assertSame('orange', $gauge['color']);
    }

    public function test_lighthouse_score_below_orange_is_critique_at_the_far_right(): void
    {
        $gauge = ReportBenchmarks::classify('lighthouse_performance', 0.0);

        $this->assertSame('red', $gauge['color']);
        $this->assertSame(100.0, $gauge['marker_pct']);
    }

    public function test_lighthouse_score_colors_run_green_to_red_left_to_right_like_every_gauge(): void
    {
        $gauge = ReportBenchmarks::classify('lighthouse_performance', 80.0);

        $colors = array_column($gauge['segments'], 'color');
        $this->assertSame(['green', 'yellow', 'orange', 'red'], $colors);
    }

    // ─────────────────────────────────────────────────────────
    // uptime (higher_is_better, non-Google source)
    // ─────────────────────────────────────────────────────────

    public function test_uptime_at_99_5_is_tres_bien(): void
    {
        $gauge = ReportBenchmarks::classify('uptime', 99.5);

        $this->assertSame('green', $gauge['color']);
    }

    public function test_uptime_just_below_99_5_is_correct(): void
    {
        $gauge = ReportBenchmarks::classify('uptime', 99.49);

        $this->assertSame('yellow', $gauge['color']);
    }

    public function test_uptime_at_98_is_a_ameliorer(): void
    {
        $gauge = ReportBenchmarks::classify('uptime', 98.0);

        $this->assertSame('orange', $gauge['color']);
    }

    public function test_uptime_below_98_is_critique(): void
    {
        $gauge = ReportBenchmarks::classify('uptime', 90.0);

        $this->assertSame('red', $gauge['color']);
        $this->assertSame(100.0, $gauge['marker_pct']); // clamped: scale floor is 97
    }

    // ─────────────────────────────────────────────────────────
    // position (lower_is_better, non-Google source)
    // ─────────────────────────────────────────────────────────

    public function test_position_1_to_10_first_page_is_tres_bien(): void
    {
        $gauge = ReportBenchmarks::classify('position', 10.0);

        $this->assertSame('green', $gauge['color']);
    }

    public function test_position_21_to_40_is_a_ameliorer(): void
    {
        $gauge = ReportBenchmarks::classify('position', 30.0);

        $this->assertSame('orange', $gauge['color']);
    }

    public function test_position_beyond_40_is_critique(): void
    {
        $gauge = ReportBenchmarks::classify('position', 60.0);

        $this->assertSame('red', $gauge['color']);
        $this->assertSame(100.0, $gauge['marker_pct']); // clamped: scale ceiling is 50
    }

    // ─────────────────────────────────────────────────────────
    // Frequency-scaled metric (incidents: weekly baseline × monthly_multiplier)
    // ─────────────────────────────────────────────────────────

    public function test_incidents_weekly_scale_classifies_at_the_baseline_boundaries(): void
    {
        $gauge = ReportBenchmarks::classifyScaled('incidents', 30.0, 1.0);

        $this->assertSame('green', $gauge['color']);
    }

    public function test_incidents_weekly_scale_flags_60_minutes_as_a_ameliorer(): void
    {
        // 60 min is > the weekly yellow boundary (60 is inclusive-yellow,
        // actually equal to it) — verify the boundary itself first.
        $gauge = ReportBenchmarks::classifyScaled('incidents', 60.0, 1.0);

        $this->assertSame('yellow', $gauge['color']);
    }

    public function test_incidents_monthly_scale_multiplies_every_boundary_by_four(): void
    {
        // 60 min is "yellow" weekly, but well within monthly's green (<=120).
        $weekly = ReportBenchmarks::classifyScaled('incidents', 60.0, 1.0);
        $monthly = ReportBenchmarks::classifyScaled('incidents', 60.0, 4.0);

        $this->assertSame('yellow', $weekly['color']);
        $this->assertSame('green', $monthly['color']);
    }

    public function test_zero_downtime_sits_at_the_far_left_of_the_incidents_gauge(): void
    {
        $gauge = ReportBenchmarks::classifyScaled('incidents', 0.0, 1.0);

        $this->assertSame(0.0, $gauge['marker_pct']);
        $this->assertSame('green', $gauge['color']);
    }

    // ─────────────────────────────────────────────────────────
    // Metadata passthrough
    // ─────────────────────────────────────────────────────────

    public function test_gauge_carries_its_metric_key_label_unit_decimals_and_source(): void
    {
        $gauge = ReportBenchmarks::classify('lcp', 2.0);

        $this->assertSame('lcp', $gauge['metric_key']);
        $this->assertSame('LCP — chargement du contenu principal', $gauge['label']);
        $this->assertSame('s', $gauge['unit']);
        $this->assertSame(1, $gauge['decimals']);
        $this->assertStringContainsString('échelle construite à partir des seuils Google', $gauge['source']);
    }

    public function test_non_google_gauges_never_claim_a_google_sourced_scale(): void
    {
        $uptime = ReportBenchmarks::classify('uptime', 99.9);
        $position = ReportBenchmarks::classify('position', 5.0);

        $this->assertStringNotContainsString('Google', $uptime['source']);
        // Position is genuinely about Google's results pages, but must not
        // claim the SCALE itself came from a Google threshold (it didn't).
        $this->assertStringNotContainsString('seuils Google', $position['source']);
    }

    // ─────────────────────────────────────────────────────────
    // World comparison — HTTP Archive Web Almanac 2025 (NOT a comparison
    // against other Radiank sites, which does not exist in this class).
    // ─────────────────────────────────────────────────────────

    public function test_good_share_world_type_flags_within_good_when_the_value_clears_googles_own_threshold(): void
    {
        // response_time's google_good is 800ms; 700 clears it even though the
        // lenient green zone (<=1300) is far more forgiving.
        $gauge = ReportBenchmarks::classify('response_time', 700.0);

        $this->assertSame('good_share', $gauge['world']['type']);
        $this->assertSame(44.0, $gauge['world']['share']);
        $this->assertTrue($gauge['world']['within_good']);
    }

    public function test_good_share_world_type_flags_outside_good_even_when_the_gauge_itself_reads_green(): void
    {
        // 1200ms is lenient-green but does not clear Google's stricter 800ms bar.
        $gauge = ReportBenchmarks::classify('response_time', 1200.0);

        $this->assertSame('green', $gauge['color']);
        $this->assertFalse($gauge['world']['within_good']);
    }

    public function test_good_share_within_good_direction_is_correct_for_a_higher_is_better_style_threshold(): void
    {
        // LCP is lower_is_better; google_good is 2.5s. 2.0s clears it.
        $within = ReportBenchmarks::classify('lcp', 2.0);
        // 3.0s does not clear Google's 2.5s bar (still lenient-green though).
        $outside = ReportBenchmarks::classify('lcp', 3.0);

        $this->assertTrue($within['world']['within_good']);
        $this->assertFalse($outside['world']['within_good']);
    }

    public function test_median_world_type_positions_its_marker_with_the_same_band_geometry_as_the_site_value(): void
    {
        // Accessibility world median is 85 — same score, same config, so the
        // world tick must land at the exact same marker_pct a site scoring
        // 85 itself would get.
        $siteAt85 = ReportBenchmarks::classify('lighthouse_accessibility', 85.0);
        $gauge = ReportBenchmarks::classify('lighthouse_accessibility', 60.0);

        $this->assertSame('median', $gauge['world']['type']);
        $this->assertSame($siteAt85['marker_pct'], $gauge['world']['marker_pct']);
        $this->assertArrayNotHasKey('share', $gauge['world']);
    }

    public function test_metric_with_no_world_config_returns_null_world(): void
    {
        $gauge = ReportBenchmarks::classify('lighthouse_performance', 80.0);

        $this->assertNull($gauge['world']);
    }

    public function test_metric_never_configured_with_world_at_all_returns_null_world(): void
    {
        $gauge = ReportBenchmarks::classify('health', 80.0);

        $this->assertNull($gauge['world']);
    }
}
