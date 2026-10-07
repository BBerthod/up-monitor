<?php

namespace Tests\Feature\Console;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the servers:heartbeat-check Artisan command.
 *
 * The command iterates active servers that have monitoring configured.
 * If the latest metric is older than max(10, --stale-minutes) minutes AND no
 * open SERVER_HEALTH/heartbeat Insight already exists, it creates one (CRITICAL).
 * Servers with no metric at all are skipped.
 *
 * withoutGlobalScopes() is used throughout because the command runs without an
 * authenticated session (ScopedByTeam would otherwise block all reads).
 */
class CheckServerHeartbeatCommandTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build an active server with an ingest token so hasMonitoringConfigured() returns true.
     * The factory default sets metrics_url+token (Dokploy pull path), but using the
     * ingest token (push path) is equally valid — either satisfies hasMonitoringConfigured().
     */
    private function makeActiveServer(Team $team): array
    {
        $plain = Server::generateIngestToken();
        $server = Server::factory()->withoutMonitoring()->for($team)->create([
            'is_active' => true,
            'ingest_token_hash' => Server::hashIngestToken($plain),
        ]);

        return [$server, $plain];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 1 — silent server (last metric > 15 min ago) → CRITICAL Insight
    // ──────────────────────────────────────────────────────────────────────

    public function test_creates_critical_heartbeat_insight_for_silent_server(): void
    {
        $team = Team::factory()->create();
        [$server] = $this->makeActiveServer($team);

        // Attach two sites so we can verify sites[] in the payload.
        Site::factory()->create([
            'team_id' => $team->id,
            'server_id' => $server->id,
            'primary_domain' => 'alpha.example.com',
        ]);
        Site::factory()->create([
            'team_id' => $team->id,
            'server_id' => $server->id,
            'primary_domain' => 'beta.example.com',
        ]);

        // Metric that is 20 minutes old — beyond the 15-minute threshold.
        ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 10.0,
            'ram_percent' => 10.0,
            'disk_percent' => 10.0,
            'captured_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(20),
        ]);

        $this->artisan('servers:heartbeat-check', ['--stale-minutes' => 15])
            ->assertExitCode(0);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->first();

        $this->assertNotNull($insight, 'A heartbeat Insight should have been created.');
        $this->assertEquals(InsightSeverity::CRITICAL->value, $insight->severity->value);
        $this->assertEquals('heartbeat', $insight->payload['metric']);
        $this->assertEquals($server->id, $insight->payload['server_id']);

        // The sites list must include both domains attached to this server.
        $this->assertContains('alpha.example.com', $insight->payload['sites']);
        $this->assertContains('beta.example.com', $insight->payload['sites']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 2 — server reported recently (< 15 min ago) → no alert
    // ──────────────────────────────────────────────────────────────────────

    public function test_no_alert_when_server_reported_recently(): void
    {
        $team = Team::factory()->create();
        [$server] = $this->makeActiveServer($team);

        // Metric that is only 5 minutes old — within the 15-minute threshold.
        ServerMetric::factory()->for($server)->create([
            'captured_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
        ]);

        $this->artisan('servers:heartbeat-check', ['--stale-minutes' => 15])
            ->assertExitCode(0);

        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 3 — server has no metric at all → skipped, no alert
    // ──────────────────────────────────────────────────────────────────────

    public function test_server_with_no_metrics_is_skipped(): void
    {
        $team = Team::factory()->create();
        $this->makeActiveServer($team);
        // No ServerMetric rows created for this server.

        $this->artisan('servers:heartbeat-check', ['--stale-minutes' => 15])
            ->assertExitCode(0);

        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 4 — anti-doublon: running the command twice must produce 1 Insight
    // ──────────────────────────────────────────────────────────────────────

    public function test_second_run_does_not_create_duplicate_heartbeat_insight(): void
    {
        $team = Team::factory()->create();
        [$server] = $this->makeActiveServer($team);

        ServerMetric::factory()->for($server)->create([
            'captured_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(20),
        ]);

        // First run creates the Insight.
        $this->artisan('servers:heartbeat-check', ['--stale-minutes' => 15])
            ->assertExitCode(0);

        $this->assertDatabaseCount('insights', 1);

        // Second run must not create a duplicate (the Insight is still unacknowledged).
        $this->artisan('servers:heartbeat-check', ['--stale-minutes' => 15])
            ->assertExitCode(0);

        $this->assertDatabaseCount('insights', 1);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 5 — inactive server → ignored, no alert
    // ──────────────────────────────────────────────────────────────────────

    public function test_inactive_server_is_ignored(): void
    {
        $team = Team::factory()->create();
        $plain = Server::generateIngestToken();
        $inactiveServer = Server::factory()->withoutMonitoring()->for($team)->create([
            'is_active' => false,
            'ingest_token_hash' => Server::hashIngestToken($plain),
        ]);

        // Metric is very old — would trigger an alert if the server were active.
        ServerMetric::factory()->for($inactiveServer)->create([
            'captured_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);

        $this->artisan('servers:heartbeat-check', ['--stale-minutes' => 15])
            ->assertExitCode(0);

        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 6 — --stale-minutes lower than 10 is floored to 10
    // ──────────────────────────────────────────────────────────────────────

    public function test_stale_minutes_option_below_10_is_clamped_to_10(): void
    {
        $team = Team::factory()->create();
        [$server] = $this->makeActiveServer($team);

        // 8 minutes old — should NOT trigger if threshold were 5 but SHOULD NOT
        // trigger at the clamped minimum of 10 (8 < 10).
        ServerMetric::factory()->for($server)->create([
            'captured_at' => now()->subMinutes(8),
            'created_at' => now()->subMinutes(8),
        ]);

        $this->artisan('servers:heartbeat-check', ['--stale-minutes' => 5])
            ->assertExitCode(0);

        // 8 minutes < 10 (clamped) → no alert should be created.
        $this->assertDatabaseCount('insights', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 7 — server without monitoring configured is skipped
    // ──────────────────────────────────────────────────────────────────────

    public function test_server_without_monitoring_is_skipped(): void
    {
        $team = Team::factory()->create();
        // withoutMonitoring() + no ingest_token_hash → hasMonitoringConfigured()=false.
        $server = Server::factory()->withoutMonitoring()->for($team)->create([
            'is_active' => true,
        ]);

        ServerMetric::factory()->for($server)->create([
            'captured_at' => now()->subHours(1),
            'created_at' => now()->subHours(1),
        ]);

        $this->artisan('servers:heartbeat-check', ['--stale-minutes' => 15])
            ->assertExitCode(0);

        $this->assertDatabaseCount('insights', 0);
    }
}
