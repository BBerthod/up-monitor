<?php

namespace Tests\Feature\Jobs;

use App\Jobs\DispatchServerMetrics;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Services\ServerHealthDetector;
use App\Services\ServerMetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Feature tests for DispatchServerMetrics job.
 *
 * The job iterates Server::withoutGlobalScopes()->where('is_active', true)->cursor()
 * and calls collector->collect() then detector->evaluate() for each server.
 *
 * We use Http::fake() for collector happy-path tests so we can wire the real
 * ServerMetricsCollector (with a constructor token) and verify DB rows are created.
 * For isolation/skip tests we mock the collector to keep things simple.
 */
class DispatchServerMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://dokploy.example.com';

    private const API_TOKEN = 'test-api-token';

    /** A minimal Dokploy data point that satisfies all required fields. */
    private function validApiPoint(array $overrides = []): array
    {
        return array_merge([
            'cpu' => 42.0,
            'memUsed' => 55.0,
            'memUsedGB' => 4.0,
            'memTotal' => 8.0,
            'diskUsed' => 30.0,
            'totalDisk' => 100.0,
            'timestamp' => now()->toIso8601String(),
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Happy path — active servers with monitoring
    // ──────────────────────────────────────────────────────────────────────

    public function test_creates_server_metric_for_each_active_configured_server(): void
    {
        Http::fake([
            '*/api/server.getServerMetrics*' => Http::response([$this->validApiPoint()], 200),
        ]);

        $serverA = Server::factory()->create([
            'is_active' => true,
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'token-a',
        ]);
        $serverB = Server::factory()->create([
            'is_active' => true,
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'token-b',
        ]);

        $collector = new ServerMetricsCollector(
            baseUrl: self::BASE_URL,
            apiToken: self::API_TOKEN,
        );

        (new DispatchServerMetrics)->handle($collector, app(ServerHealthDetector::class));

        // One ServerMetric row per active configured server.
        $this->assertDatabaseCount('server_metrics', 2);
        $this->assertDatabaseHas('server_metrics', ['server_id' => $serverA->id]);
        $this->assertDatabaseHas('server_metrics', ['server_id' => $serverB->id]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Inactive server is skipped
    // ──────────────────────────────────────────────────────────────────────

    public function test_inactive_server_is_not_processed(): void
    {
        Http::fake([
            '*/api/server.getServerMetrics*' => Http::response([$this->validApiPoint()], 200),
        ]);

        // Inactive server — must be ignored by the job.
        Server::factory()->create([
            'is_active' => false,
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'token',
        ]);

        $collector = new ServerMetricsCollector(
            baseUrl: self::BASE_URL,
            apiToken: self::API_TOKEN,
        );

        (new DispatchServerMetrics)->handle($collector, app(ServerHealthDetector::class));

        $this->assertDatabaseCount('server_metrics', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Per-server isolation — one exception does not abort the batch
    // ──────────────────────────────────────────────────────────────────────

    public function test_exception_on_one_server_does_not_prevent_others_from_being_processed(): void
    {
        $team = Team::factory()->create();

        $failingServer = Server::factory()->for($team)->create([
            'is_active' => true,
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'token-fail',
        ]);
        $okServer = Server::factory()->for($team)->create([
            'is_active' => true,
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'token-ok',
        ]);

        // Mock the collector to throw on the first call and return a metric on the second.
        $collectorMock = Mockery::mock(ServerMetricsCollector::class);
        $callCount = 0;
        $collectorMock->shouldReceive('collect')
            ->twice()
            ->andReturnUsing(function (Server $server) use ($okServer, &$callCount): ?ServerMetric {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('Simulated network failure');
                }

                // Return a real ServerMetric row for the second server.
                return ServerMetric::factory()->for($okServer)->create();
            });

        $detectorMock = Mockery::mock(ServerHealthDetector::class);
        $detectorMock->shouldReceive('evaluate')->once()->andReturn(0);

        // Must not throw — isolation means the batch continues despite the error.
        (new DispatchServerMetrics)->handle($collectorMock, $detectorMock);

        // Only the ok server produced a row.
        $this->assertDatabaseHas('server_metrics', ['server_id' => $okServer->id]);
        $this->assertDatabaseCount('server_metrics', 1);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Server without monitoring is skipped gracefully
    // ──────────────────────────────────────────────────────────────────────

    public function test_server_without_monitoring_produces_no_metric_row(): void
    {
        Http::fake();

        Server::factory()->withoutMonitoring()->create(['is_active' => true]);

        $collector = new ServerMetricsCollector(
            baseUrl: self::BASE_URL,
            apiToken: self::API_TOKEN,
        );

        // Should not throw and should not create any ServerMetric row.
        (new DispatchServerMetrics)->handle($collector, app(ServerHealthDetector::class));

        $this->assertDatabaseCount('server_metrics', 0);
        Http::assertNothingSent();
    }
}
