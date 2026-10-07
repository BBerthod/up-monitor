<?php

namespace Tests\Feature\Services;

use App\Enums\KpiRegressionSeverity;
use App\Enums\KpiSource;
use App\Models\BusinessKpiIncident;
use App\Models\KpiSnapshot;
use App\Services\KpiRegressionDetector;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class KpiRegressionDetectorTest extends TestCase
{
    use RefreshDatabase;

    private KpiRegressionDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock NotificationService so no jobs are dispatched during tests.
        $notificationMock = Mockery::mock(NotificationService::class);
        $notificationMock->shouldReceive('notifyBusinessKpi')->andReturn(null);
        $this->app->instance(NotificationService::class, $notificationMock);

        $this->detector = app(KpiRegressionDetector::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper: create N snapshots for the past N days with the given value
    // ──────────────────────────────────────────────────────────────────────────

    private function seedSnapshots(
        string $site,
        KpiSource $source,
        string $metric,
        float $value,
        int $days = 10,
    ): void {
        for ($i = $days; $i >= 1; $i--) {
            KpiSnapshot::create([
                'site' => $site,
                'source' => $source->value,
                'metric' => $metric,
                'value' => $value,
                'period_days' => 28,
                'captured_at' => now()->subDays($i),
            ]);
        }
    }

    private function makeSnapshot(string $site, KpiSource $source, string $metric, float $value): KpiSnapshot
    {
        return KpiSnapshot::create([
            'site' => $site,
            'source' => $source->value,
            'metric' => $metric,
            'value' => $value,
            'period_days' => 28,
            'captured_at' => now(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Severity: MAJOR on -25% over 7 days
    // ──────────────────────────────────────────────────────────────────────────

    public function test_creates_major_incident_when_delta_exceeds_major_threshold_7d(): void
    {
        $site = 'example.de';
        $source = KpiSource::GSC;
        $metric = 'impressions_28d';
        $baseline = 10_000.0;

        // Seed 8 days of history at baseline value.
        $this->seedSnapshots($site, $source, $metric, $baseline, days: 8);

        // Current value: -26% (below the -25% major threshold).
        $current = $baseline * 0.74;
        $snapshot = $this->makeSnapshot($site, $source, $metric, $current);

        $this->detector->analyse($site, [$snapshot]);

        $incident = BusinessKpiIncident::where('site', $site)
            ->where('source', $source->value)
            ->where('metric', $metric)
            ->first();

        $this->assertNotNull($incident);
        $this->assertEquals(KpiRegressionSeverity::MAJOR->value, $incident->severity->value);
        $this->assertNull($incident->resolved_at);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Severity: MINOR on -10% over 7 days (but not major)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_creates_minor_incident_when_delta_is_minor_regression(): void
    {
        $site = 'example.com';
        $source = KpiSource::GSC;
        $metric = 'clicks_28d';
        $baseline = 1_000.0;

        $this->seedSnapshots($site, $source, $metric, $baseline, days: 8);

        // Current: -15% (below -10% minor, above -25% major).
        $current = $baseline * 0.85;
        $snapshot = $this->makeSnapshot($site, $source, $metric, $current);

        $this->detector->analyse($site, [$snapshot]);

        $incident = BusinessKpiIncident::where('site', $site)->where('metric', $metric)->first();
        $this->assertNotNull($incident);
        $this->assertEquals(KpiRegressionSeverity::MINOR->value, $incident->severity->value);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // No incident when no regression
    // ──────────────────────────────────────────────────────────────────────────

    public function test_does_not_create_incident_when_no_regression(): void
    {
        $site = 'healthy.com';
        $source = KpiSource::GSC;
        $metric = 'impressions_28d';
        $baseline = 5_000.0;

        $this->seedSnapshots($site, $source, $metric, $baseline, days: 8);

        // Current: -5% (below -10% minor threshold → no incident).
        $current = $baseline * 0.96;
        $snapshot = $this->makeSnapshot($site, $source, $metric, $current);

        $this->detector->analyse($site, [$snapshot]);

        $this->assertDatabaseCount('business_kpi_incidents', 0);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // No duplicate incident within same open window
    // ──────────────────────────────────────────────────────────────────────────

    public function test_does_not_create_duplicate_incident_when_one_already_open(): void
    {
        $site = 'example.de';
        $source = KpiSource::GSC;
        $metric = 'impressions_28d';
        $baseline = 10_000.0;

        $this->seedSnapshots($site, $source, $metric, $baseline, days: 8);

        // Create an existing open incident.
        BusinessKpiIncident::create([
            'site' => $site,
            'source' => $source->value,
            'metric' => $metric,
            'severity' => KpiRegressionSeverity::MAJOR->value,
            'baseline_value' => $baseline,
            'current_value' => $baseline * 0.74,
            'delta_pct' => -26.0,
            'detected_at' => now()->subHours(2),
        ]);

        // Trigger analysis again with continued regression.
        $snapshot = $this->makeSnapshot($site, $source, $metric, $baseline * 0.72);
        $this->detector->analyse($site, [$snapshot]);

        // Should still be only 1 incident.
        $this->assertDatabaseCount('business_kpi_incidents', 1);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Auto-resolve when metric recovers above threshold
    // ──────────────────────────────────────────────────────────────────────────

    public function test_resolves_incident_when_metric_recovers_above_threshold(): void
    {
        $site = 'recovering.com';
        $source = KpiSource::GSC;
        $metric = 'impressions_28d';
        $baseline = 8_000.0;

        $this->seedSnapshots($site, $source, $metric, $baseline, days: 8);

        // Open incident.
        $incident = BusinessKpiIncident::create([
            'site' => $site,
            'source' => $source->value,
            'metric' => $metric,
            'severity' => KpiRegressionSeverity::MAJOR->value,
            'baseline_value' => $baseline,
            'current_value' => $baseline * 0.70,
            'delta_pct' => -30.0,
            'detected_at' => now()->subDays(2),
        ]);

        // Recovery: value is now only -3% below baseline (within healthy range).
        $snapshot = $this->makeSnapshot($site, $source, $metric, $baseline * 0.97);
        $this->detector->analyse($site, [$snapshot]);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // No baseline → no incident
    // ──────────────────────────────────────────────────────────────────────────

    public function test_no_incident_when_no_baseline_history(): void
    {
        $site = 'new-site.com';
        $source = KpiSource::TTFB;
        $metric = 'ttfb_p95_ms';

        // No previous snapshots — first ever snapshot.
        $snapshot = $this->makeSnapshot($site, $source, $metric, 5000.0);

        $this->detector->analyse($site, [$snapshot]);

        $this->assertDatabaseCount('business_kpi_incidents', 0);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TTFB (higher-is-worse): regression when value increases significantly
    // ──────────────────────────────────────────────────────────────────────────

    public function test_creates_incident_for_ttfb_increase(): void
    {
        $site = 'slow-site.com';
        $source = KpiSource::TTFB;
        $metric = 'ttfb_p95_ms';
        $baseline = 500.0; // 500ms baseline

        $this->seedSnapshots($site, $source, $metric, $baseline, days: 8);

        // TTFB jumped to 700ms → +40% (threshold is +25% = major).
        $snapshot = $this->makeSnapshot($site, $source, $metric, 700.0);

        $this->detector->analyse($site, [$snapshot]);

        $incident = BusinessKpiIncident::where('site', $site)->where('metric', $metric)->first();
        $this->assertNotNull($incident);
        $this->assertEquals(KpiRegressionSeverity::MAJOR->value, $incident->severity->value);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 30-day major regression (delta < -40% over 30d even if -10% over 7d)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_creates_major_incident_from_30d_baseline(): void
    {
        $site = 'declining.com';
        $source = KpiSource::GSC;
        $metric = 'impressions_28d';

        // 30 days of history at a high value.
        for ($i = 35; $i >= 8; $i--) {
            KpiSnapshot::create([
                'site' => $site,
                'source' => $source->value,
                'metric' => $metric,
                'value' => 10_000.0,
                'period_days' => 28,
                'captured_at' => now()->subDays($i),
            ]);
        }

        // Last 7 days: slight decline (only -5% over 7d → no 7d major).
        for ($i = 7; $i >= 1; $i--) {
            KpiSnapshot::create([
                'site' => $site,
                'source' => $source->value,
                'metric' => $metric,
                'value' => 9_500.0,
                'period_days' => 28,
                'captured_at' => now()->subDays($i),
            ]);
        }

        // Current: 5,800 (vs 30d baseline ~9,900 → -41% over 30d, but ~-39% over 7d).
        $snapshot = $this->makeSnapshot($site, $source, $metric, 5_800.0);

        $this->detector->analyse($site, [$snapshot]);

        $incident = BusinessKpiIncident::where('site', $site)->where('metric', $metric)->first();
        $this->assertNotNull($incident, 'Expected a major incident from 30d regression');
        $this->assertEquals(KpiRegressionSeverity::MAJOR->value, $incident->severity->value);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Multiple concurrent metrics for same site
    // ──────────────────────────────────────────────────────────────────────────

    public function test_analyses_multiple_metrics_independently(): void
    {
        $site = 'multi-metric.com';
        $source = KpiSource::GSC;
        $baseline = 1_000.0;

        // Seed history for two metrics.
        foreach (['impressions_28d', 'clicks_28d'] as $metric) {
            $this->seedSnapshots($site, $source, $metric, $baseline, days: 8);
        }

        $snapshots = [
            // impressions: -30% → major incident
            $this->makeSnapshot($site, $source, 'impressions_28d', $baseline * 0.70),
            // clicks: -3% → no incident
            $this->makeSnapshot($site, $source, 'clicks_28d', $baseline * 0.97),
        ];

        $this->detector->analyse($site, $snapshots);

        $this->assertDatabaseCount('business_kpi_incidents', 1);
        $this->assertDatabaseHas('business_kpi_incidents', ['metric' => 'impressions_28d']);
    }
}
