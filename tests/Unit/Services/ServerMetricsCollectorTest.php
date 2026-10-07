<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\ServerMetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit tests for ServerMetricsCollector.
 *
 * Http::fake() intercepts all outbound HTTP — no real network calls are made.
 * The collector reads the Dokploy api_token from config at call time, so we
 * override it via config() in tests that need a non-empty token.
 *
 * The constructor accepts baseUrl + apiToken directly so the tests do not need
 * to manipulate global config for the happy path.
 */
class ServerMetricsCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://dokploy.example.com';

    private const API_TOKEN = 'test-api-token';

    /** A minimal Dokploy data point that passes all required-field checks. */
    private function validPoint(array $overrides = []): array
    {
        return array_merge([
            'cpu' => 42.5,
            'memUsed' => 55.0,
            'memUsedGB' => 4.4,
            'memTotal' => 8.0,
            'diskUsed' => 30.0,
            'totalDisk' => 100.0,
            'timestamp' => now()->toIso8601String(),
        ], $overrides);
    }

    private function makeCollector(): ServerMetricsCollector
    {
        return new ServerMetricsCollector(
            baseUrl: self::BASE_URL,
            apiToken: self::API_TOKEN,
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Happy path — field mapping
    // ──────────────────────────────────────────────────────────────────────

    public function test_parses_valid_payload_and_persists_server_metric(): void
    {
        Http::fake([
            '*/api/server.getServerMetrics*' => Http::response([$this->validPoint()], 200),
        ]);

        $server = Server::factory()->create([
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'server-token',
        ]);

        $collector = $this->makeCollector();
        $metric = $collector->collect($server);

        $this->assertNotNull($metric);
        $this->assertInstanceOf(ServerMetric::class, $metric);

        // cpu_percent ← cpu (already a %)
        $this->assertEquals(42.5, (float) $metric->cpu_percent);

        // ram_percent ← memUsed
        $this->assertEquals(55.0, (float) $metric->ram_percent);

        // ram_used_mb ← round(memUsedGB × 1024) = round(4.4 × 1024) = 4506
        $this->assertEquals((int) round(4.4 * 1024), $metric->ram_used_mb);

        // ram_total_mb ← round(memTotal × 1024) = round(8.0 × 1024) = 8192
        $this->assertEquals((int) round(8.0 * 1024), $metric->ram_total_mb);

        // disk_percent ← diskUsed
        $this->assertEquals(30.0, (float) $metric->disk_percent);

        // disk_total_gb ← round(totalDisk) = 100
        $this->assertEquals(100, $metric->disk_total_gb);

        // disk_used_gb ← round(totalDisk × diskUsed / 100) = round(100 × 30 / 100) = 30
        $this->assertEquals((int) round(100.0 * 30.0 / 100), $metric->disk_used_gb);

        $this->assertEquals($server->id, $metric->server_id);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Multi-point response — last element wins
    // ──────────────────────────────────────────────────────────────────────

    public function test_takes_the_last_element_when_response_has_multiple_points(): void
    {
        $older = $this->validPoint(['cpu' => 10.0, 'timestamp' => now()->subMinutes(5)->toIso8601String()]);
        $latest = $this->validPoint(['cpu' => 77.7, 'timestamp' => now()->toIso8601String()]);

        Http::fake([
            '*/api/server.getServerMetrics*' => Http::response([$older, $latest], 200),
        ]);

        $server = Server::factory()->create([
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'server-token',
        ]);

        $metric = $this->makeCollector()->collect($server);

        $this->assertNotNull($metric);
        // The LAST element is the current snapshot — cpu must be 77.7, not 10.0.
        $this->assertEquals(77.7, (float) $metric->cpu_percent);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Skip — monitoring not configured
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_null_without_api_call_when_monitoring_not_configured(): void
    {
        Http::fake();

        $server = Server::factory()->withoutMonitoring()->create();

        $metric = $this->makeCollector()->collect($server);

        $this->assertNull($metric);
        Http::assertNothingSent();
        $this->assertDatabaseCount('server_metrics', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Skip — global API token empty
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_null_when_global_api_token_is_empty(): void
    {
        Http::fake();

        $server = Server::factory()->create([
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'server-token',
        ]);

        // Collector with empty token — must bail before any HTTP call.
        $collector = new ServerMetricsCollector(
            baseUrl: self::BASE_URL,
            apiToken: '',
        );

        // Ensure the config fallback is also empty so the service doesn't pick
        // up a real token from the environment.
        config(['services.dokploy.api_token' => '']);

        $metric = $collector->collect($server);

        $this->assertNull($metric);
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Skip — API responds with 5xx
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_null_on_api_error_response(): void
    {
        Http::fake([
            '*/api/server.getServerMetrics*' => Http::response([], 500),
        ]);

        $server = Server::factory()->create([
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'server-token',
        ]);

        $metric = $this->makeCollector()->collect($server);

        $this->assertNull($metric);
        $this->assertDatabaseCount('server_metrics', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Skip — empty payload []
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_null_on_empty_payload_array(): void
    {
        Http::fake([
            '*/api/server.getServerMetrics*' => Http::response([], 200),
        ]);

        $server = Server::factory()->create([
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'server-token',
        ]);

        $metric = $this->makeCollector()->collect($server);

        $this->assertNull($metric);
        $this->assertDatabaseCount('server_metrics', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Skip — data point missing a required field
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_null_when_required_field_cpu_is_missing(): void
    {
        // Remove 'cpu' from the point — mapPoint must reject the row entirely.
        $point = $this->validPoint();
        unset($point['cpu']);

        Http::fake([
            '*/api/server.getServerMetrics*' => Http::response([$point], 200),
        ]);

        $server = Server::factory()->create([
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'server-token',
        ]);

        $metric = $this->makeCollector()->collect($server);

        $this->assertNull($metric);
        // No partial row must have been persisted.
        $this->assertDatabaseCount('server_metrics', 0);
    }

    public function test_returns_null_when_required_field_memused_is_missing(): void
    {
        $point = $this->validPoint();
        unset($point['memUsed']);

        Http::fake([
            '*/api/server.getServerMetrics*' => Http::response([$point], 200),
        ]);

        $server = Server::factory()->create([
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'server-token',
        ]);

        $metric = $this->makeCollector()->collect($server);

        $this->assertNull($metric);
        $this->assertDatabaseCount('server_metrics', 0);
    }

    public function test_returns_null_when_required_field_diskused_is_missing(): void
    {
        $point = $this->validPoint();
        unset($point['diskUsed']);

        Http::fake([
            '*/api/server.getServerMetrics*' => Http::response([$point], 200),
        ]);

        $server = Server::factory()->create([
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => 'server-token',
        ]);

        $metric = $this->makeCollector()->collect($server);

        $this->assertNull($metric);
        $this->assertDatabaseCount('server_metrics', 0);
    }
}
