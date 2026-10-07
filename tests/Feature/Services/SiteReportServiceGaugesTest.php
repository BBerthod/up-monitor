<?php

namespace Tests\Feature\Services;

use App\Enums\KpiSource;
use App\Enums\ReportFrequency;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorLighthouseScore;
use App\Models\Site;
use App\Models\Team;
use App\Services\SiteReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteReportServiceGaugesTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-09-21 is a Monday — the "now" every test anchors on. */
    private const REFERENCE = '2026-09-21 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::REFERENCE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): SiteReportService
    {
        return app(SiteReportService::class);
    }

    private function gaugeByKey(array $report, string $metricKey): ?array
    {
        return collect($report['gauges'])->firstWhere('metric_key', $metricKey);
    }

    public function test_no_gauges_at_all_when_the_site_has_no_data(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame([], $report['gauges']);
    }

    public function test_gauges_appear_in_the_specified_order(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com', 'domains' => ['example.com']]);
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorCheck::factory()->count(5)->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00', 'response_time_ms' => 300]);
        MonitorLighthouseScore::factory()->create(['monitor_id' => $monitor->id, 'performance' => 80, 'accessibility' => 80, 'best_practices' => 80, 'seo' => 80, 'scored_at' => '2026-09-18 00:00:00']);
        KpiSnapshot::factory()->create(['site' => 'example.com', 'source' => KpiSource::GSC->value, 'metric' => 'position_28d', 'value' => 5, 'captured_at' => '2026-09-16 00:00:00']);
        KpiSnapshot::factory()->create(['site' => 'example.com', 'source' => KpiSource::CRUX->value, 'metric' => 'lcp_p75', 'value' => 2000, 'captured_at' => '2026-09-16 00:00:00']);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame([
            'health',
            'uptime',
            'incidents', // present even with 0 downtime — see the dedicated test below
            'response_time',
            'lcp',
            'lighthouse_performance',
            'lighthouse_seo',
            'position',
            'lighthouse_accessibility',
            'lighthouse_best_practices',
        ], array_column($report['gauges'], 'metric_key'));
    }

    public function test_health_gauge_is_built_from_the_composite_score(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorCheck::factory()->count(5)->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00']);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $gauge = $this->gaugeByKey($report, 'health');
        $this->assertNotNull($gauge);
        $this->assertSame((float) $report['health']['score'], $gauge['value']);
    }

    public function test_no_health_gauge_when_the_site_has_no_active_monitors(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertNull($this->gaugeByKey($report, 'health'));
    }

    public function test_response_time_gauge_is_built_from_the_period_average(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-15 12:00:00',
            'response_time_ms' => 500,
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $gauge = $this->gaugeByKey($report, 'response_time');
        $this->assertNotNull($gauge);
        $this->assertSame(500.0, $gauge['value']);
        $this->assertSame('green', $gauge['color']); // lenient scale: 500 <= 1300
    }

    public function test_incidents_gauge_shows_a_composite_count_and_downtime_label(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorIncident::factory()->resolved()->create([
            'monitor_id' => $monitor->id,
            'started_at' => '2026-09-15 10:00:00',
            'resolved_at' => '2026-09-15 10:12:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $gauge = $this->gaugeByKey($report, 'incidents');
        $this->assertNotNull($gauge);
        $this->assertSame("1 incident · 12 min d'arrêt", $gauge['display_value']);
        $this->assertSame('green', $gauge['color']); // 12 min <= weekly green ceiling (30)
    }

    public function test_incidents_gauge_is_absent_when_there_is_no_downtime(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorCheck::factory()->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00']);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        // The value classifies (0 min is a valid "green" reading), so the
        // gauge IS present with the marker at the far left — verifying this
        // distinguishes "no gauge" (no data at all) from "gauge showing zero".
        $gauge = $this->gaugeByKey($report, 'incidents');
        $this->assertNotNull($gauge);
        $this->assertSame(0.0, $gauge['marker_pct']);
        $this->assertSame("0 incident · 0 min d'arrêt", $gauge['display_value']);
    }

    public function test_incidents_gauge_uses_the_wider_monthly_scale(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        // 90 minutes of downtime: "orange" weekly (>60), but "green" monthly (<=120).
        MonitorIncident::factory()->resolved()->create([
            'monitor_id' => $monitor->id,
            'started_at' => '2026-08-05 10:00:00',
            'resolved_at' => '2026-08-05 11:30:00',
        ]);

        $weeklyReport = $this->service()->generate($site, ReportFrequency::WEEKLY, Carbon::parse('2026-08-10 10:00:00'));
        $monthlyReport = $this->service()->generate($site, ReportFrequency::MONTHLY, Carbon::parse('2026-09-01 10:00:00'));

        $this->assertSame('orange', $this->gaugeByKey($weeklyReport, 'incidents')['color']);
        $this->assertSame('green', $this->gaugeByKey($monthlyReport, 'incidents')['color']);
    }

    public function test_crux_gauges_use_the_primary_domain_key_and_convert_lcp_to_seconds(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com']);
        Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        KpiSnapshot::factory()->create([
            'site' => 'example.com', // CruxCollector's key: raw primary_domain, not siteKey().
            'source' => KpiSource::CRUX->value,
            'metric' => 'lcp_p75',
            'value' => 5000, // ms — above the lenient 4.0s yellow ceiling.
            'captured_at' => '2026-09-16 00:00:00',
        ]);
        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::CRUX->value,
            'metric' => 'inp_p75',
            'value' => 150,
            'captured_at' => '2026-09-16 00:00:00',
        ]);
        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::CRUX->value,
            'metric' => 'cls_p75',
            'value' => 0.05,
            'captured_at' => '2026-09-16 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $lcp = $this->gaugeByKey($report, 'lcp');
        $inp = $this->gaugeByKey($report, 'inp');
        $cls = $this->gaugeByKey($report, 'cls');

        $this->assertNotNull($lcp);
        $this->assertSame(5.0, $lcp['value']); // 5000ms → 5.0s
        $this->assertSame('orange', $lcp['color']); // between 4.0 and 6.0

        $this->assertNotNull($inp);
        $this->assertSame('green', $inp['color']);

        $this->assertNotNull($cls);
        $this->assertSame('green', $cls['color']);
    }

    public function test_crux_gauge_is_absent_when_the_site_key_does_not_match_crux_collectors_convention(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com']);
        Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        // Wrong key — the www-stripped siteKey() convention, not CruxCollector's raw primary_domain.
        KpiSnapshot::factory()->create([
            'site' => 'www.example.com',
            'source' => KpiSource::CRUX->value,
            'metric' => 'lcp_p75',
            'value' => 3200,
            'captured_at' => '2026-09-16 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertNull($this->gaugeByKey($report, 'lcp'));
    }

    public function test_lighthouse_gauges_are_built_from_the_latest_score(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'performance' => 95,
            'accessibility' => 60,
            'best_practices' => 40,
            'seo' => 20,
            'scored_at' => '2026-09-18 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame('green', $this->gaugeByKey($report, 'lighthouse_performance')['color']);
        $this->assertSame('yellow', $this->gaugeByKey($report, 'lighthouse_accessibility')['color']); // 60: between 50 and 70
        $this->assertSame('orange', $this->gaugeByKey($report, 'lighthouse_best_practices')['color']); // 40: between 33 and 50
        $this->assertSame('red', $this->gaugeByKey($report, 'lighthouse_seo')['color']); // 20 < seo's own 50 floor
    }

    public function test_uptime_gauge_and_position_gauge_use_the_report_period_values(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com', 'domains' => ['example.com']]);
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorCheck::factory()->count(10)->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00']);

        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'position_28d',
            'value' => 15,
            'captured_at' => '2026-09-16 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame('green', $this->gaugeByKey($report, 'uptime')['color']); // 100% uptime
        $this->assertSame('yellow', $this->gaugeByKey($report, 'position')['color']); // position 15, between 10 and 20
    }

    // ─────────────────────────────────────────────────────────
    // World comparison (HTTP Archive Web Almanac 2025) — NOT a comparison
    // against other Radiank sites, which was removed entirely.
    // ─────────────────────────────────────────────────────────

    public function test_good_share_sentence_says_the_site_is_part_of_the_share_when_within_googles_good_threshold(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        // 700ms clears Google's own "good" TTFB threshold (800ms).
        MonitorCheck::factory()->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00', 'response_time_ms' => 700]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $gauge = $this->gaugeByKey($report, 'response_time');
        $this->assertSame('good_share', $gauge['world']['type']);
        $this->assertTrue($gauge['world']['within_good']);
        $this->assertSame(
            'Vous faites partie des 44 % de sites dans le monde qui atteignent ce niveau.',
            $gauge['world']['sentence'],
        );
    }

    public function test_good_share_sentence_says_the_world_share_when_outside_googles_good_threshold(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        // 1200ms is lenient-green (<=1300) but does NOT clear Google's
        // stricter 800ms "good" TTFB threshold.
        MonitorCheck::factory()->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00', 'response_time_ms' => 1200]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $gauge = $this->gaugeByKey($report, 'response_time');
        $this->assertSame('green', $gauge['color']); // lenient scale still reads green
        $this->assertFalse($gauge['world']['within_good']);
        $this->assertSame(
            '44 % des sites dans le monde atteignent le seuil « bon » de Google.',
            $gauge['world']['sentence'],
        );
    }

    public function test_lcp_inp_cls_good_share_figures_match_the_sourced_web_almanac_numbers(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com']);
        Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        KpiSnapshot::factory()->create(['site' => 'example.com', 'source' => KpiSource::CRUX->value, 'metric' => 'lcp_p75', 'value' => 2000, 'captured_at' => '2026-09-16 00:00:00']);
        KpiSnapshot::factory()->create(['site' => 'example.com', 'source' => KpiSource::CRUX->value, 'metric' => 'inp_p75', 'value' => 150, 'captured_at' => '2026-09-16 00:00:00']);
        KpiSnapshot::factory()->create(['site' => 'example.com', 'source' => KpiSource::CRUX->value, 'metric' => 'cls_p75', 'value' => 0.05, 'captured_at' => '2026-09-16 00:00:00']);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame(62.0, $this->gaugeByKey($report, 'lcp')['world']['share']);
        $this->assertSame(77.0, $this->gaugeByKey($report, 'inp')['world']['share']);
        $this->assertSame(81.0, $this->gaugeByKey($report, 'cls')['world']['share']);
    }

    public function test_lighthouse_accessibility_gauge_shows_a_world_median_tick_with_no_sentence(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'accessibility' => 90,
            'scored_at' => '2026-09-18 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $gauge = $this->gaugeByKey($report, 'lighthouse_accessibility');
        $this->assertSame('median', $gauge['world']['type']);
        $this->assertArrayHasKey('marker_pct', $gauge['world']);
        $this->assertArrayNotHasKey('sentence', $gauge['world']);
    }

    public function test_metrics_without_sourced_world_data_render_no_world_comparison_at_all(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorCheck::factory()->count(5)->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00']);
        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'performance' => 80,
            'best_practices' => 80,
            'seo' => 80,
            'scored_at' => '2026-09-18 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        // Lighthouse Performance/SEO/Best Practices: only 2020 data exists —
        // explicitly not used. Health/uptime/incidents: never had a source.
        $this->assertNull($this->gaugeByKey($report, 'lighthouse_performance')['world']);
        $this->assertNull($this->gaugeByKey($report, 'lighthouse_seo')['world']);
        $this->assertNull($this->gaugeByKey($report, 'lighthouse_best_practices')['world']);
        $this->assertNull($this->gaugeByKey($report, 'health')['world']);
        $this->assertNull($this->gaugeByKey($report, 'uptime')['world']);
        $this->assertNull($this->gaugeByKey($report, 'incidents')['world']);
    }
}
