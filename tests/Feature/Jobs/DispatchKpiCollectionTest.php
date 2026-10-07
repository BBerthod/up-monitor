<?php

namespace Tests\Feature\Jobs;

use App\Enums\MonitorType;
use App\Jobs\DispatchKpiCollection;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Services\HealthScoreService;
use App\Services\KpiCollector;
use App\Services\KpiRegressionDetector;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DispatchKpiCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress notifications.
        $notificationMock = Mockery::mock(NotificationService::class);
        $notificationMock->shouldReceive('notifyBusinessKpi')->andReturn(null);
        $this->app->instance(NotificationService::class, $notificationMock);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fallback path: monitors without a Site (site_id IS NULL)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_collects_ttfb_for_orphan_http_monitors(): void
    {
        // Monitor has no site_id → it takes the fallback path (TTFB only).
        $team = Team::factory()->create();

        Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://example.com',
            'is_active' => true,
            'site_id' => null,
        ]);

        $collectorMock = Mockery::mock(KpiCollector::class);
        $collectorMock->shouldReceive('siteNameFromUrl')
            ->with('https://example.com')
            ->andReturn('example.com');
        // collectForSite must NOT be called — no Site record exists.
        $collectorMock->shouldReceive('collectForSite')->never();
        $collectorMock->shouldReceive('collectForMonitor')
            ->once()
            ->andReturn([
                KpiSnapshot::factory()->create([
                    'site' => 'example.com',
                    'source' => 'ttfb',
                    'metric' => 'ttfb_p95_ms',
                    'value' => 350.0,
                    'captured_at' => now(),
                ]),
            ]);

        $this->app->instance(KpiCollector::class, $collectorMock);

        (new DispatchKpiCollection)->handle(
            $collectorMock,
            app(KpiRegressionDetector::class),
            app(HealthScoreService::class),
        );

        $this->assertDatabaseHas('kpi_snapshots', [
            'site' => 'example.com',
            'metric' => 'ttfb_p95_ms',
        ]);
    }

    public function test_skips_inactive_monitors(): void
    {
        $team = Team::factory()->create();

        Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://inactive.example.com',
            'is_active' => false,
            'site_id' => null,
        ]);

        $collectorMock = Mockery::mock(KpiCollector::class);
        // The fallback query filters active() — inactive monitors must not be
        // processed.
        $collectorMock->shouldReceive('collectForSite')->never();
        $collectorMock->shouldReceive('collectForMonitor')->never();
        $collectorMock->shouldReceive('siteNameFromUrl')->andReturn('inactive.example.com');

        $this->app->instance(KpiCollector::class, $collectorMock);

        (new DispatchKpiCollection)->handle(
            $collectorMock,
            app(KpiRegressionDetector::class),
            app(HealthScoreService::class),
        );

        $this->assertDatabaseCount('kpi_snapshots', 0);
    }

    public function test_skips_non_http_monitors(): void
    {
        $team = Team::factory()->create();

        Monitor::factory()->ping()->for($team)->create([
            'is_active' => true,
            'site_id' => null,
        ]);

        $collectorMock = Mockery::mock(KpiCollector::class);
        // The fallback query filters type=http — ping monitors must not be
        // processed.
        $collectorMock->shouldReceive('collectForSite')->never();
        $collectorMock->shouldReceive('collectForMonitor')->never();
        $collectorMock->shouldReceive('siteNameFromUrl')->andReturn('192.168.1.1');

        $this->app->instance(KpiCollector::class, $collectorMock);

        (new DispatchKpiCollection)->handle(
            $collectorMock,
            app(KpiRegressionDetector::class),
            app(HealthScoreService::class),
        );

        $this->assertDatabaseCount('kpi_snapshots', 0);
    }

    public function test_continues_on_per_monitor_failure(): void
    {
        $team = Team::factory()->create();

        Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://failing.example.com',
            'is_active' => true,
            'site_id' => null,
        ]);

        Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://ok.example.com',
            'is_active' => true,
            'site_id' => null,
        ]);

        $callCount = 0;
        $collectorMock = Mockery::mock(KpiCollector::class);
        $collectorMock->shouldReceive('siteNameFromUrl')->andReturnUsing(
            fn (string $url) => parse_url($url, PHP_URL_HOST),
        );
        $collectorMock->shouldReceive('collectForSite')->never();
        $collectorMock->shouldReceive('collectForMonitor')->twice()->andReturnUsing(
            function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('Network error');
                }

                return [
                    KpiSnapshot::factory()->create([
                        'site' => 'ok.example.com',
                        'source' => 'ttfb',
                        'metric' => 'ttfb_p95_ms',
                        'value' => 200.0,
                        'captured_at' => now(),
                    ]),
                ];
            }
        );

        $this->app->instance(KpiCollector::class, $collectorMock);

        // Should not throw — failure on one monitor must not abort the batch.
        (new DispatchKpiCollection)->handle(
            $collectorMock,
            app(KpiRegressionDetector::class),
            app(HealthScoreService::class),
        );

        // The second monitor's snapshot was created.
        $this->assertDatabaseHas('kpi_snapshots', ['site' => 'ok.example.com']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Primary path: Sites
    // ──────────────────────────────────────────────────────────────────────────

    public function test_iterates_active_sites_for_primary_path(): void
    {
        // Two active Sites — the job must call collectForSite for each.
        $team = Team::factory()->create();
        $siteA = Site::factory()->for($team)->withSeo()->create([
            'primary_domain' => 'alpha.example.com',
            'is_active' => true,
        ]);
        $siteB = Site::factory()->for($team)->withSeo()->create([
            'primary_domain' => 'beta.example.com',
            'is_active' => true,
        ]);

        $collectorMock = Mockery::mock(KpiCollector::class);
        // siteNameFromUrl is called to derive the detector key for each site.
        $collectorMock->shouldReceive('siteNameFromUrl')
            ->andReturnUsing(fn (string $url) => ltrim(parse_url($url, PHP_URL_HOST) ?? $url, 'www.'));

        $collectorMock->shouldReceive('collectForSite')
            ->twice()
            ->andReturn([]);

        // No orphan monitors → fallback path collectForMonitor never called.
        $collectorMock->shouldReceive('collectForMonitor')->never();

        $this->app->instance(KpiCollector::class, $collectorMock);

        (new DispatchKpiCollection)->handle(
            $collectorMock,
            app(KpiRegressionDetector::class),
            app(HealthScoreService::class),
        );
    }

    public function test_skips_inactive_sites(): void
    {
        $team = Team::factory()->create();
        Site::factory()->for($team)->create([
            'primary_domain' => 'inactive.example.com',
            'is_active' => false,
        ]);

        $collectorMock = Mockery::mock(KpiCollector::class);
        $collectorMock->shouldReceive('collectForSite')->never();
        $collectorMock->shouldReceive('collectForMonitor')->never();
        $collectorMock->shouldReceive('siteNameFromUrl')->andReturn('inactive.example.com');

        $this->app->instance(KpiCollector::class, $collectorMock);

        (new DispatchKpiCollection)->handle(
            $collectorMock,
            app(KpiRegressionDetector::class),
            app(HealthScoreService::class),
        );

        $this->assertDatabaseCount('kpi_snapshots', 0);
    }

    public function test_continues_on_per_site_failure(): void
    {
        $team = Team::factory()->create();
        Site::factory()->for($team)->create([
            'primary_domain' => 'failing.example.com',
            'is_active' => true,
        ]);
        Site::factory()->for($team)->create([
            'primary_domain' => 'ok.example.com',
            'is_active' => true,
        ]);

        $callCount = 0;
        $collectorMock = Mockery::mock(KpiCollector::class);
        $collectorMock->shouldReceive('siteNameFromUrl')->andReturnUsing(
            fn (string $url) => ltrim(parse_url($url, PHP_URL_HOST) ?? $url, 'www.'),
        );
        $collectorMock->shouldReceive('collectForSite')->twice()->andReturnUsing(
            function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('API error');
                }

                return [];
            }
        );
        $collectorMock->shouldReceive('collectForMonitor')->never();

        $this->app->instance(KpiCollector::class, $collectorMock);

        // Must not throw — one site failing must not abort the rest.
        (new DispatchKpiCollection)->handle(
            $collectorMock,
            app(KpiRegressionDetector::class),
            app(HealthScoreService::class),
        );
    }

    public function test_monitors_with_site_id_not_processed_by_fallback(): void
    {
        // A monitor linked to a Site must NOT be picked up by the fallback path
        // (it is already covered by collectForSite in the primary path).
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'primary_domain' => 'linked.example.com',
            'is_active' => true,
        ]);
        Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://linked.example.com',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        $collectorMock = Mockery::mock(KpiCollector::class);
        $collectorMock->shouldReceive('siteNameFromUrl')->andReturnUsing(
            fn (string $url) => ltrim(parse_url($url, PHP_URL_HOST) ?? $url, 'www.'),
        );
        // Primary path: one Site → collectForSite called once.
        $collectorMock->shouldReceive('collectForSite')->once()->andReturn([]);
        // Fallback path must NOT process the linked monitor.
        $collectorMock->shouldReceive('collectForMonitor')->never();

        $this->app->instance(KpiCollector::class, $collectorMock);

        (new DispatchKpiCollection)->handle(
            $collectorMock,
            app(KpiRegressionDetector::class),
            app(HealthScoreService::class),
        );
    }
}
