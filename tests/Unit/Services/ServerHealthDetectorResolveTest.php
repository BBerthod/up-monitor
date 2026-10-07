<?php

namespace Tests\Unit\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\ServerHealthDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ServerHealthDetector's auto-resolution behaviour.
 *
 * Resolution (resolveAlerts) is private but is exercised through evaluate():
 *   - When a metric falls BELOW the warning threshold for a dimension, any open
 *     SERVER_HEALTH Insight for that server+dimension is acknowledged (acknowledged_at = now()).
 *   - A heartbeat alert is always resolved when any metric arrives.
 *   - Once resolved, a future breach creates a fresh Insight (anti-doublon unblocked).
 *
 * Thresholds used (config defaults):
 *   disk:  warning=85, critical=92
 *   ram:   warning=88, critical=95
 *
 * RefreshDatabase provides a clean slate; no auth context required because
 * the detector uses withoutGlobalScopes() throughout.
 */
class ServerHealthDetectorResolveTest extends TestCase
{
    use RefreshDatabase;

    private ServerHealthDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(ServerHealthDetector::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a ServerMetric for the given server with safe defaults for all
     * dimensions that are not under test.
     */
    private function makeMetric(Server $server, array $attributes = []): ServerMetric
    {
        return ServerMetric::factory()->for($server)->create(array_merge([
            'cpu_percent' => 10.0,
            'ram_percent' => 10.0,
            'disk_percent' => 10.0,
        ], $attributes));
    }

    /**
     * Plant a pre-existing open SERVER_HEALTH Insight for the given server
     * and metric dimension (mimics a previously-created alert).
     */
    private function createOpenInsight(Server $server, string $metricName, string $severity = 'warning'): Insight
    {
        return Insight::create([
            'team_id' => $server->team_id,
            'site' => $server->name,
            'monitor_id' => null,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => $severity,
            'title' => "Server {$server->name}: {$metricName} alert",
            'payload' => [
                'metric' => $metricName,
                'server_id' => $server->id,
                'server_name' => $server->name,
                'sites' => [],
            ],
            'impact_score' => 10.0,
            'detected_at' => now()->subMinutes(30),
            'acknowledged_at' => null,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 1 — disk critical → alert; then disk healthy → alert resolved
    // ──────────────────────────────────────────────────────────────────────

    public function test_disk_alert_is_resolved_when_subsequent_metric_is_below_warning(): void
    {
        $server = Server::factory()->create();

        // First metric: disk critical → creates Insight.
        $criticalMetric = $this->makeMetric($server, ['disk_percent' => 93.0]);
        $this->detector->evaluate($server, $criticalMetric);

        $this->assertDatabaseCount('insights', 1);
        $insight = Insight::withoutGlobalScopes()->first();
        $this->assertNull($insight->acknowledged_at);

        // Second metric: disk healthy → Insight must be resolved.
        $healthyMetric = $this->makeMetric($server, ['disk_percent' => 50.0]);
        $this->detector->evaluate($server, $healthyMetric);

        $insight->refresh();
        $this->assertNotNull($insight->acknowledged_at, 'The disk Insight should have been resolved.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 2 — after resolution, a new breach creates a NEW Insight
    // ──────────────────────────────────────────────────────────────────────

    public function test_new_disk_breach_after_resolution_creates_a_fresh_insight(): void
    {
        $server = Server::factory()->create();

        // Breach → Insight created.
        $breach1 = $this->makeMetric($server, ['disk_percent' => 93.0]);
        $this->detector->evaluate($server, $breach1);

        $this->assertDatabaseCount('insights', 1);

        // Recovery → Insight resolved.
        $recovery = $this->makeMetric($server, ['disk_percent' => 50.0]);
        $this->detector->evaluate($server, $recovery);

        $first = Insight::withoutGlobalScopes()->first();
        $this->assertNotNull($first->acknowledged_at, 'First alert should be resolved after recovery.');

        // Second breach → anti-doublon is unblocked because the previous alert
        // is acknowledged. A brand-new Insight must be created.
        $breach2 = $this->makeMetric($server, ['disk_percent' => 94.0]);
        $this->detector->evaluate($server, $breach2);

        $total = Insight::withoutGlobalScopes()->count();
        $this->assertEquals(2, $total, 'A second Insight should be created for the new breach.');

        // The second Insight is unacknowledged.
        $second = Insight::withoutGlobalScopes()->whereNull('acknowledged_at')->first();
        $this->assertNotNull($second);
        $this->assertEquals('disk', $second->payload['metric']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 3 — heartbeat alert resolved when any metric arrives
    // ──────────────────────────────────────────────────────────────────────

    public function test_heartbeat_alert_is_resolved_when_a_metric_is_received(): void
    {
        $server = Server::factory()->create();

        // Plant a pre-existing heartbeat alert (as if the dead-man switch had fired).
        $heartbeatInsight = $this->createOpenInsight($server, 'heartbeat', InsightSeverity::CRITICAL->value);
        $this->assertNull($heartbeatInsight->acknowledged_at);

        // Any incoming metric should clear the heartbeat alert.
        $metric = $this->makeMetric($server, [
            'cpu_percent' => 10.0,
            'ram_percent' => 10.0,
            'disk_percent' => 10.0,
        ]);
        $this->detector->evaluate($server, $metric);

        $heartbeatInsight->refresh();
        $this->assertNotNull(
            $heartbeatInsight->acknowledged_at,
            'The heartbeat alert should be resolved when a metric arrives.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 4 — healthy metric with no open alert → no-op (no Insight created)
    // ──────────────────────────────────────────────────────────────────────

    public function test_healthy_metric_with_no_open_alert_is_a_no_op(): void
    {
        $server = Server::factory()->create();

        // No pre-existing Insights. Healthy metric should create nothing.
        $metric = $this->makeMetric($server, [
            'cpu_percent' => 20.0,
            'ram_percent' => 30.0,
            'disk_percent' => 40.0,
        ]);

        $created = $this->detector->evaluate($server, $metric);

        $this->assertEquals(0, $created);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 5 — RAM alert is resolved independently of disk
    // ──────────────────────────────────────────────────────────────────────

    public function test_ram_alert_resolved_while_disk_alert_remains_open(): void
    {
        $server = Server::factory()->create();

        // Create both a disk and a RAM alert manually (both open).
        $diskInsight = $this->createOpenInsight($server, 'disk', InsightSeverity::WARNING->value);
        $ramInsight = $this->createOpenInsight($server, 'ram', InsightSeverity::WARNING->value);

        // Metric where disk is still critical but RAM is healthy.
        $metric = $this->makeMetric($server, [
            'disk_percent' => 93.0,   // still critical
            'ram_percent' => 30.0,    // below warning → should resolve RAM alert
        ]);

        $this->detector->evaluate($server, $metric);

        $diskInsight->refresh();
        $ramInsight->refresh();

        // Disk alert must remain open.
        $this->assertNull($diskInsight->acknowledged_at, 'Disk alert should remain open.');

        // RAM alert must be resolved.
        $this->assertNotNull($ramInsight->acknowledged_at, 'RAM alert should be resolved.');
    }
}
