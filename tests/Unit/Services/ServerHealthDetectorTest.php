<?php

namespace Tests\Unit\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Services\ServerHealthDetector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ServerHealthDetector.
 *
 * All thresholds are the config defaults (monitoring.server_health):
 *   disk:  warning=85, critical=92
 *   ram:   warning=88, critical=95
 *   cpu:   warning=90, critical=97, sustained_points=3
 *
 * The detector uses withoutGlobalScopes() for the anti-doublon query, so
 * no auth context is needed.  RefreshDatabase gives us a clean slate.
 */
class ServerHealthDetectorTest extends TestCase
{
    use RefreshDatabase;

    private ServerHealthDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(ServerHealthDetector::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a ServerMetric for the given server with the provided column values.
     * All other columns are filled with safe defaults that won't trigger alerts.
     */
    private function makeMetric(Server $server, array $attributes = []): ServerMetric
    {
        return ServerMetric::factory()->for($server)->create(array_merge([
            'cpu_percent' => 10.0,
            'ram_percent' => 10.0,
            'disk_percent' => 10.0,
        ], $attributes));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Disk — critical (>= 92)
    // ──────────────────────────────────────────────────────────────────────

    public function test_disk_critical_creates_critical_insight_with_correct_payload(): void
    {
        $server = Server::factory()->create();

        // Attach two Sites so we can verify the sites array in the payload.
        $siteA = Site::factory()->create([
            'team_id' => $server->team_id,
            'server_id' => $server->id,
            'primary_domain' => 'alpha.example.com',
        ]);
        $siteB = Site::factory()->create([
            'team_id' => $server->team_id,
            'server_id' => $server->id,
            'primary_domain' => 'beta.example.com',
        ]);

        $metric = $this->makeMetric($server, ['disk_percent' => 93.0]);

        $created = $this->detector->evaluate($server, $metric);

        $this->assertEquals(1, $created);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL->value, $insight->severity->value);
        $this->assertEquals('disk', $insight->payload['metric']);
        $this->assertEquals($server->id, $insight->payload['server_id']);

        // The sites list must contain the primary_domain of every site on this server.
        $this->assertContains('alpha.example.com', $insight->payload['sites']);
        $this->assertContains('beta.example.com', $insight->payload['sites']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Disk — warning (>= 85, < 92)
    // ──────────────────────────────────────────────────────────────────────

    public function test_disk_warning_creates_warning_insight(): void
    {
        $server = Server::factory()->create();
        $metric = $this->makeMetric($server, ['disk_percent' => 87.0]);

        $created = $this->detector->evaluate($server, $metric);

        $this->assertEquals(1, $created);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING->value, $insight->severity->value);
        $this->assertEquals('disk', $insight->payload['metric']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Disk — below warning
    // ──────────────────────────────────────────────────────────────────────

    public function test_disk_below_warning_creates_no_insight(): void
    {
        $server = Server::factory()->create();
        $metric = $this->makeMetric($server, ['disk_percent' => 50.0]);

        $created = $this->detector->evaluate($server, $metric);

        $this->assertEquals(0, $created);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // RAM — warning and critical
    // ──────────────────────────────────────────────────────────────────────

    public function test_ram_warning_creates_warning_insight(): void
    {
        $server = Server::factory()->create();
        $metric = $this->makeMetric($server, ['ram_percent' => 90.0]);

        $created = $this->detector->evaluate($server, $metric);

        $this->assertEquals(1, $created);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING->value, $insight->severity->value);
        $this->assertEquals('ram', $insight->payload['metric']);
    }

    public function test_ram_critical_creates_critical_insight(): void
    {
        $server = Server::factory()->create();
        $metric = $this->makeMetric($server, ['ram_percent' => 96.0]);

        $created = $this->detector->evaluate($server, $metric);

        $this->assertEquals(1, $created);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL->value, $insight->severity->value);
        $this->assertEquals('ram', $insight->payload['metric']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // CPU — sustained (all 3 consecutive points above warning)
    // ──────────────────────────────────────────────────────────────────────

    public function test_cpu_creates_warning_insight_when_all_three_consecutive_points_breach_warning(): void
    {
        $server = Server::factory()->create();

        // Create 3 metrics with captured_at in descending order so the query
        // orderByDesc('captured_at')->limit(3) picks up all three.
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 91.0,
            'captured_at' => now()->subMinutes(10),
        ]);
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 92.0,
            'captured_at' => now()->subMinutes(5),
        ]);
        // The "current" metric (most recent) — passed as the $metric argument.
        $current = ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 93.0,
            'captured_at' => now(),
        ]);

        $created = $this->detector->evaluate($server, $current);

        $this->assertEquals(1, $created);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING->value, $insight->severity->value);
        $this->assertEquals('cpu', $insight->payload['metric']);
    }

    public function test_cpu_creates_no_insight_when_one_of_three_points_is_below_threshold(): void
    {
        $server = Server::factory()->create();

        // Two high points …
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 91.0,
            'captured_at' => now()->subMinutes(10),
        ]);
        // … but one dip below the warning threshold — spike, not sustained load.
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 50.0,
            'captured_at' => now()->subMinutes(5),
        ]);
        $current = ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 92.0,
            'captured_at' => now(),
        ]);

        $created = $this->detector->evaluate($server, $current);

        $this->assertEquals(0, $created);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // CPU — not enough history (bootstrap guard)
    // ──────────────────────────────────────────────────────────────────────

    public function test_cpu_creates_no_insight_when_fewer_than_three_historical_points_exist(): void
    {
        $server = Server::factory()->create();

        // Only 2 metrics exist — below the cpu_sustained_points=3 threshold.
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 95.0,
            'captured_at' => now()->subMinutes(5),
        ]);
        $current = ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 95.0,
            'captured_at' => now(),
        ]);

        $created = $this->detector->evaluate($server, $current);

        $this->assertEquals(0, $created);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Anti-doublon
    // ──────────────────────────────────────────────────────────────────────

    public function test_anti_doublon_does_not_create_second_insight_for_same_server_and_metric(): void
    {
        $server = Server::factory()->create();

        $metric1 = $this->makeMetric($server, ['disk_percent' => 93.0]);
        $metric2 = $this->makeMetric($server, ['disk_percent' => 94.0]);

        // First evaluation creates the Insight.
        $this->detector->evaluate($server, $metric1);
        // Second evaluation must not create a duplicate while the first is unacknowledged.
        $this->detector->evaluate($server, $metric2);

        $this->assertDatabaseCount('insights', 1);
    }

    public function test_detected_at_is_preserved_across_runs_while_severity_and_payload_are_recomputed(): void
    {
        $server = Server::factory()->create();

        Carbon::setTestNow('2026-08-01 08:00:00');

        $this->detector->evaluate($server, $this->makeMetric($server, ['disk_percent' => 87.0]));

        $firstInsight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->where('payload->server_id', $server->id)
            ->where('payload->metric', 'disk')
            ->whereNull('acknowledged_at')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::WARNING, $firstInsight->severity);
        $this->assertSame(87, $firstInsight->payload['value']);
        $firstDetectedAt = $firstInsight->detected_at;

        Carbon::setTestNow('2026-08-22 08:00:00');

        $this->detector->evaluate($server, $this->makeMetric($server, ['disk_percent' => 95.0]));

        $refreshed = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->where('payload->server_id', $server->id)
            ->where('payload->metric', 'disk')
            ->whereNull('acknowledged_at')
            ->get();

        $this->assertCount(1, $refreshed);

        $refreshedInsight = $refreshed->first();

        $this->assertTrue($refreshedInsight->detected_at->equalTo($firstDetectedAt));
        $this->assertEquals(InsightSeverity::CRITICAL, $refreshedInsight->severity);
        $this->assertSame(95, $refreshedInsight->payload['value']);
        $this->assertEquals('10.00', $refreshedInsight->impact_score);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Return value
    // ──────────────────────────────────────────────────────────────────────

    public function test_evaluate_returns_count_of_created_insights(): void
    {
        $server = Server::factory()->create();

        // Breach both disk AND ram thresholds in one metric.
        $metric = $this->makeMetric($server, [
            'disk_percent' => 93.0,
            'ram_percent' => 96.0,
        ]);

        $created = $this->detector->evaluate($server, $metric);

        // Two separate Insights must have been created (disk + ram).
        $this->assertEquals(2, $created);
        $this->assertDatabaseCount('insights', 2);
    }

    // ──────────────────────────────────────────────────────────────────────
    // No alert when all metrics are below thresholds
    // ──────────────────────────────────────────────────────────────────────

    public function test_no_insights_created_when_all_metrics_are_healthy(): void
    {
        $server = Server::factory()->create();
        $metric = $this->makeMetric($server, [
            'cpu_percent' => 50.0,
            'ram_percent' => 50.0,
            'disk_percent' => 50.0,
        ]);

        $created = $this->detector->evaluate($server, $metric);

        $this->assertEquals(0, $created);
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // server_id FK
    // ──────────────────────────────────────────────────────────────────────

    public function test_disk_insight_has_server_id_column_set(): void
    {
        $server = Server::factory()->create();
        $metric = $this->makeMetric($server, ['disk_percent' => 93.0]);

        $this->detector->evaluate($server, $metric);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals($server->id, $insight->server_id);
    }

    public function test_ram_insight_has_server_id_column_set(): void
    {
        $server = Server::factory()->create();
        $metric = $this->makeMetric($server, ['ram_percent' => 96.0]);

        $this->detector->evaluate($server, $metric);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals($server->id, $insight->server_id);
    }
}
