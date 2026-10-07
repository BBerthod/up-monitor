<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Enums\ReportFrequency;
use App\Models\Insight;
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

class SiteReportServiceTest extends TestCase
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

    public function test_weekly_period_is_the_previous_full_monday_to_sunday_week(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com']);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame('2026-09-14 00:00:00', $report['period']['start']->toDateTimeString());
        $this->assertSame('2026-09-21 00:00:00', $report['period']['end']->toDateTimeString());
    }

    public function test_monthly_period_is_the_previous_full_calendar_month(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com']);

        $report = $this->service()->generate($site, ReportFrequency::MONTHLY);

        $this->assertSame('2026-08-01 00:00:00', $report['period']['start']->toDateTimeString());
        $this->assertSame('2026-09-01 00:00:00', $report['period']['end']->toDateTimeString());
    }

    public function test_uptime_and_response_time_with_previous_period_deltas(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com']);
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id, 'name' => 'Homepage']);

        // Current period (14→21 Sept): 3 up, 1 down => 75% uptime.
        MonitorCheck::factory()->count(3)->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-15 12:00:00',
            'response_time_ms' => 200,
        ]);
        MonitorCheck::factory()->down()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-16 12:00:00',
            'response_time_ms' => 200,
        ]);

        // Previous period (7→14 Sept): all up, slower response.
        MonitorCheck::factory()->count(4)->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-10 12:00:00',
            'response_time_ms' => 400,
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame(75.0, $report['uptime']['uptime_pct']);
        $this->assertSame(100.0, $report['uptime']['uptime_pct_delta']['previous']);
        // Uptime fell 75% ← 100%: higher is better, so this is a "bad" tone.
        $this->assertSame('bad', $report['uptime']['uptime_pct_delta']['tone']);

        $this->assertSame(200, $report['uptime']['avg_response_ms']);
        $this->assertSame(400.0, $report['uptime']['avg_response_ms_delta']['previous']);
        // Response time fell 200ms ← 400ms: LOWER is better, so a falling
        // value must be toned "good" — the polarity-by-meaning gotcha: rising
        // response time is bad even though the diff sign alone looks "up".
        $this->assertSame('good', $report['uptime']['avg_response_ms_delta']['tone']);
        $this->assertSame(-200.0, $report['uptime']['avg_response_ms_delta']['diff']);
    }

    public function test_period_boundaries_are_half_open_so_a_check_at_the_boundary_counts_once(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        // Exactly at the current-period start (== previous-period end). A
        // whereBetween() (inclusive on both ends) would double-count this
        // check into both periods; half-open [start, end) must count it only
        // in the CURRENT period.
        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-14 00:00:00',
            'response_time_ms' => 111,
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame(111, $report['uptime']['avg_response_ms']);
        $this->assertNull($report['uptime']['avg_response_ms_delta']);
    }

    public function test_a_check_at_exactly_period_end_is_not_counted_in_the_reported_week(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        // Inside the reported week.
        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-18 12:00:00',
            'response_time_ms' => 100,
        ]);

        // Exactly at period end (Monday 00:00, the start of the ONGOING week)
        // — must be excluded: end is exclusive.
        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-21 00:00:00',
            'response_time_ms' => 999,
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame(100, $report['uptime']['avg_response_ms']);
    }

    public function test_incidents_are_scoped_to_the_period_and_counted_separately_from_previous(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id, 'name' => 'Homepage']);

        // Current period incident, resolved after 30 minutes.
        MonitorIncident::factory()->resolved()->create([
            'monitor_id' => $monitor->id,
            'started_at' => '2026-09-15 10:00:00',
            'resolved_at' => '2026-09-15 10:30:00',
        ]);

        // Previous period incident — must not leak into the current count.
        MonitorIncident::factory()->resolved()->create([
            'monitor_id' => $monitor->id,
            'started_at' => '2026-09-08 10:00:00',
            'resolved_at' => '2026-09-08 11:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame(1, $report['uptime']['incident_count']);
        $this->assertSame(1.0, $report['uptime']['incident_count_delta']['previous']);
        $this->assertSame(30, $report['uptime']['total_downtime_minutes']);
        $this->assertSame(60.0, $report['uptime']['total_downtime_minutes_delta']['previous']);
        // Incident count is unchanged (1 → 1): neutral tone.
        $this->assertSame('neutral', $report['uptime']['incident_count_delta']['tone']);
        // Downtime fell (30 ← 60 min): lower is better, so "positive".
        $this->assertSame('good', $report['uptime']['total_downtime_minutes_delta']['tone']);
        $this->assertCount(1, $report['incidents']);
        $this->assertSame('Homepage', $report['incidents'][0]['monitor_name']);
        $this->assertSame(30, $report['incidents'][0]['duration_minutes']);
    }

    public function test_gsc_and_ga4_sections_report_deltas_against_the_previous_period(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com', 'domains' => ['example.com']]);

        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 100,
            'captured_at' => '2026-09-16 00:00:00',
        ]);
        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 50,
            'captured_at' => '2026-09-09 00:00:00',
        ]);
        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::GA4->value,
            'metric' => 'users_28d',
            'value' => 300,
            'captured_at' => '2026-09-16 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame(100.0, $report['gsc']['clicks']);
        $this->assertSame(50.0, $report['gsc']['clicks_delta']['previous']);
        $this->assertSame('good', $report['gsc']['clicks_delta']['tone']);
        $this->assertSame(300.0, $report['ga4']['users']);
        $this->assertNull($report['ga4']['users_delta']);
    }

    public function test_gsc_uses_the_latest_rolling_28d_snapshot_not_an_average(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com', 'domains' => ['example.com']]);

        // Two daily snapshots inside the current period — both are rolling
        // 28-day TOTALS, so averaging them (old behaviour) would be wrong.
        // Only the most recent one is "the current figure".
        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 900,
            'captured_at' => '2026-09-15 00:00:00',
        ]);
        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'value' => 1000,
            'captured_at' => '2026-09-19 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        // The average of 900 and 1000 would be 950 — must NOT be that.
        $this->assertSame(1000.0, $report['gsc']['clicks']);
    }

    public function test_gsc_position_tone_is_inverted_because_a_lower_rank_number_is_better(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com', 'domains' => ['example.com']]);

        // Position improved from 20 to 10 (a lower rank number is better).
        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'position_28d',
            'value' => 10,
            'captured_at' => '2026-09-16 00:00:00',
        ]);
        KpiSnapshot::factory()->create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => 'position_28d',
            'value' => 20,
            'captured_at' => '2026-09-09 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame(-10.0, $report['gsc']['position_delta']['diff']);
        // A falling position NUMBER is an improvement: must be "positive", not
        // "negative" — the exact polarity-by-meaning gotcha this feature must
        // get right (raw sign of the diff is negative, tone is positive).
        $this->assertSame('good', $report['gsc']['position_delta']['tone']);
    }

    public function test_lighthouse_section_uses_the_latest_score_across_the_sites_monitors(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'performance' => 40,
            'scored_at' => '2026-09-15 00:00:00',
        ]);
        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'performance' => 90,
            'accessibility' => 70,
            'best_practices' => 40,
            'seo' => 55,
            'scored_at' => '2026-09-18 00:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertSame(90, $report['lighthouse']['performance']);
        // The same lenient bands as this metric's own gauge (report_benchmarks
        // config) — no separate hard-coded "green >= 90" rule anymore, so this
        // summary strip can never drift from the gauges above it.
        $this->assertSame('green', $report['lighthouse']['performance_color']); // 90 >= 70
        $this->assertSame('green', $report['lighthouse']['accessibility_color']); // 70 >= 70
        $this->assertSame('orange', $report['lighthouse']['best_practices_color']); // 40: between 33 and 50
        $this->assertSame('orange', $report['lighthouse']['seo_color']); // 55: between 50 and 65 (seo's own scale)
    }

    public function test_insights_section_returns_top_5_open_warning_and_critical_by_impact(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        // 'title' is the English column detectors write for the Up app UI —
        // the report must show InsightReportText's French sentence instead
        // (built from type + payload), never this column. See
        // InsightReportTextTest for per-type coverage.
        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'title' => 'Low impact warning',
            'type' => InsightType::ZOMBIE_PAGE->value,
            'payload' => ['zombie_count' => 3, 'published_count' => 20],
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 10,
        ]);
        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'title' => 'High impact critical',
            'type' => InsightType::DOMAIN_EXPIRY->value,
            'payload' => ['domain' => 'example.com', 'days_remaining' => 5],
            'severity' => InsightSeverity::CRITICAL->value,
            'impact_score' => 90,
        ]);
        // Acknowledged — must not appear.
        Insight::factory()->acknowledged()->for($team)->create([
            'site_id' => $site->id,
            'title' => 'Already handled',
            'severity' => InsightSeverity::CRITICAL->value,
            'impact_score' => 99,
        ]);
        // INFO severity — not a "to watch" item.
        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'title' => 'Just info',
            'severity' => InsightSeverity::INFO->value,
            'impact_score' => 99,
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertCount(2, $report['insights']);
        $this->assertSame('Le nom de domaine example.com expire dans 5 jours.', $report['insights'][0]['title']);
        $this->assertSame("3 pages publiées sur 20 n'apparaissent pas dans les résultats Google.", $report['insights'][1]['title']);
    }

    public function test_summary_reports_uptime_ok_when_no_incidents_and_high_uptime(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorCheck::factory()->count(5)->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-15 12:00:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertStringContainsString('En bref : ', $report['summary']);
        $this->assertStringContainsString('disponible en continu', $report['summary']);
        $this->assertStringContainsString('aucune alerte ouverte', $report['summary']);
    }

    public function test_summary_mentions_incident_count_and_downtime_when_uptime_is_degraded(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorCheck::factory()->count(3)->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00']);
        MonitorCheck::factory()->down()->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-16 12:00:00']);
        MonitorIncident::factory()->resolved()->create([
            'monitor_id' => $monitor->id,
            'started_at' => '2026-09-16 12:00:00',
            'resolved_at' => '2026-09-16 12:20:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        // Singular form via trans_choice — no "(s)".
        $this->assertStringContainsString('1 incident,', $report['summary']);
        $this->assertStringContainsString("20 min d'arrêt", $report['summary']);
    }

    public function test_summary_pluralizes_incident_count_in_french(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        MonitorCheck::factory()->down()->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00']);
        MonitorIncident::factory()->resolved()->create([
            'monitor_id' => $monitor->id, 'started_at' => '2026-09-15 08:00:00', 'resolved_at' => '2026-09-15 08:05:00',
        ]);
        MonitorIncident::factory()->resolved()->create([
            'monitor_id' => $monitor->id, 'started_at' => '2026-09-16 08:00:00', 'resolved_at' => '2026-09-16 08:05:00',
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertStringContainsString('2 incidents,', $report['summary']);
        $this->assertStringNotContainsString('incident(s)', $report['summary']);
    }

    public function test_summary_names_the_biggest_good_and_bad_change_on_a_known_dataset(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com', 'domains' => ['example.com']]);
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);

        // Response time: 200ms current vs 400ms previous → -50% (good, and the
        // largest relative move in this dataset).
        MonitorCheck::factory()->count(3)->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-15 12:00:00', 'response_time_ms' => 200]);
        MonitorCheck::factory()->count(3)->create(['monitor_id' => $monitor->id, 'checked_at' => '2026-09-08 12:00:00', 'response_time_ms' => 400]);

        // GSC position: 12 current vs 10 previous → worse by 2, +20% relative
        // (bad, and the largest relative move on the "bad" side).
        KpiSnapshot::factory()->create(['site' => 'example.com', 'source' => KpiSource::GSC->value, 'metric' => 'position_28d', 'value' => 12, 'captured_at' => '2026-09-16 00:00:00']);
        KpiSnapshot::factory()->create(['site' => 'example.com', 'source' => KpiSource::GSC->value, 'metric' => 'position_28d', 'value' => 10, 'captured_at' => '2026-09-09 00:00:00']);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertStringContainsString('meilleure progression : Temps de réponse (-200 ms vs semaine précédente)', $report['summary']);
        $this->assertStringContainsString('point de vigilance : Position moyenne (+2,0 vs semaine précédente)', $report['summary']);
    }

    public function test_summary_counts_open_insights_as_alerts(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);
        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertStringContainsString('2 alertes ouvertes à surveiller', $report['summary']);
        $this->assertStringNotContainsString('alerte(s)', $report['summary']);
    }

    public function test_summary_pluralizes_a_single_open_alert(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertStringContainsString('1 alerte ouverte à surveiller', $report['summary']);
    }

    public function test_comparison_label_matches_frequency(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        $weekly = $this->service()->generate($site, ReportFrequency::WEEKLY);
        $monthly = $this->service()->generate($site, ReportFrequency::MONTHLY);

        $this->assertSame('vs semaine précédente', $weekly['comparison_label']);
        $this->assertSame('vs mois précédent', $monthly['comparison_label']);
    }

    public function test_empty_sections_are_null_or_empty_rather_than_fake_zeros(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        $report = $this->service()->generate($site, ReportFrequency::WEEKLY);

        $this->assertNull($report['health']);
        $this->assertNull($report['gsc']);
        $this->assertNull($report['ga4']);
        $this->assertNull($report['lighthouse']);
        $this->assertSame([], $report['monitors']);
        $this->assertSame([], $report['incidents']);
        $this->assertSame([], $report['insights']);
        $this->assertNull($report['uptime']['uptime_pct']);
    }
}
