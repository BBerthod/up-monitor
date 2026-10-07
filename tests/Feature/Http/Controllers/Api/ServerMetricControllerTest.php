<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for POST /api/servers/metrics  (ServerMetricController::store).
 *
 * Auth model:
 *   Authorization: Bearer <plain-token>
 *   The plain token is SHA-256-hashed and matched against ingest_token_hash.
 *   Auth is evaluated BEFORE validation — unauthenticated callers always get 401.
 *
 * No web session / no CSRF token required: this is a stateless push endpoint.
 */
class ServerMetricControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build an active server that has an ingest token.
     * Returns [$server, $plainToken] so tests can pass the Bearer header.
     */
    private function makeActiveServerWithToken(): array
    {
        $plain = Server::generateIngestToken();
        $server = Server::factory()->withoutMonitoring()->create([
            'is_active' => true,
            'ingest_token_hash' => Server::hashIngestToken($plain),
        ]);

        return [$server, $plain];
    }

    /**
     * A minimal valid payload that satisfies all required fields.
     */
    private function validPayload(): array
    {
        return [
            'cpu_percent' => 42.5,
            'ram_percent' => 55.0,
            'disk_percent' => 30.0,
        ];
    }

    /**
     * Convenience: post to the metrics endpoint with the given bearer token.
     */
    private function postMetrics(array $payload, ?string $bearerToken = null): \Illuminate\Testing\TestResponse
    {
        $headers = $bearerToken !== null
            ? ['Authorization' => 'Bearer '.$bearerToken]
            : [];

        return $this->postJson(route('api.servers.metrics'), $payload, $headers);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Case 1 — No Authorization header → 401 (before validation)
    // ─────────────────────────────────────────────────────────────────────

    public function test_request_without_authorization_header_returns_401(): void
    {
        $response = $this->postJson(route('api.servers.metrics'), $this->validPayload());

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Case 2 — Token that matches no server → 401
    // ─────────────────────────────────────────────────────────────────────

    public function test_request_with_invalid_token_returns_401(): void
    {
        // Create a real server so the DB is not empty, but use a different token.
        $this->makeActiveServerWithToken();

        $response = $this->postMetrics($this->validPayload(), 'not-a-valid-token-at-all');

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Case 3 — Token matches server but is_active = false → 401
    // ─────────────────────────────────────────────────────────────────────

    public function test_request_for_inactive_server_returns_401(): void
    {
        $plain = Server::generateIngestToken();
        Server::factory()->withoutMonitoring()->create([
            'is_active' => false,
            'ingest_token_hash' => Server::hashIngestToken($plain),
        ]);

        $response = $this->postMetrics($this->validPayload(), $plain);

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Case 4 — Valid token + valid body → 201, row in DB
    // ─────────────────────────────────────────────────────────────────────

    public function test_valid_token_and_valid_body_returns_201_and_persists_metric(): void
    {
        [$server, $plain] = $this->makeActiveServerWithToken();

        $payload = [
            'cpu_percent' => 42.5,
            'ram_percent' => 55.0,
            'disk_percent' => 30.0,
            'ram_used_mb' => 2048,
            'ram_total_mb' => 4096,
            'disk_used_gb' => 50,
            'disk_total_gb' => 200,
        ];

        $response = $this->postMetrics($payload, $plain);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id']);
        $this->assertNotNull($response->json('id'));

        // Confirm the row is stored. decimal:2 cast → compare as string.
        $this->assertDatabaseHas('server_metrics', [
            'server_id' => $server->id,
            'cpu_percent' => '42.50',
            'ram_percent' => '55.00',
            'disk_percent' => '30.00',
            'ram_used_mb' => 2048,
            'ram_total_mb' => 4096,
            'disk_used_gb' => 50,
            'disk_total_gb' => 200,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Case 5 — Valid token + disk_percent > 100 → 422, no metric row
    // ─────────────────────────────────────────────────────────────────────

    public function test_disk_percent_above_100_returns_422_and_creates_no_metric(): void
    {
        [, $plain] = $this->makeActiveServerWithToken();

        $payload = array_merge($this->validPayload(), ['disk_percent' => 101]);

        $response = $this->postMetrics($payload, $plain);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['disk_percent']);
        $this->assertDatabaseCount('server_metrics', 0);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Case 6 — Valid token + required field missing → 422
    // ─────────────────────────────────────────────────────────────────────

    public function test_missing_required_field_returns_422(): void
    {
        [, $plain] = $this->makeActiveServerWithToken();

        $payload = $this->validPayload();
        unset($payload['cpu_percent']);

        $response = $this->postMetrics($payload, $plain);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cpu_percent']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Case 7 — disk_percent in critical territory → 201 + Insight created
    // ─────────────────────────────────────────────────────────────────────

    /**
     * disk_critical threshold defaults to 92 (config/monitoring.php).
     * A value of 95 is above critical → ServerHealthDetector must create
     * a SERVER_HEALTH / critical Insight immediately (disk alerts don't
     * require sustained points, unlike CPU).
     *
     * The Insight payload->sites must list the primary_domain of every
     * Site belonging to the server.
     */
    public function test_disk_above_critical_threshold_creates_server_health_insight(): void
    {
        [$server, $plain] = $this->makeActiveServerWithToken();

        // Ensure the config threshold is deterministic in this test.
        config()->set('monitoring.server_health.disk_critical', 92);
        config()->set('monitoring.server_health.disk_warning', 85);

        // Attach two sites to the server.
        Site::factory()->create([
            'team_id' => $server->team_id,
            'server_id' => $server->id,
            'primary_domain' => 'alpha.example.com',
        ]);
        Site::factory()->create([
            'team_id' => $server->team_id,
            'server_id' => $server->id,
            'primary_domain' => 'beta.example.com',
        ]);

        $payload = [
            'cpu_percent' => 10.0,
            'ram_percent' => 20.0,
            'disk_percent' => 95.0,  // above disk_critical (92)
        ];

        $response = $this->postMetrics($payload, $plain);

        $response->assertStatus(201);

        // One SERVER_HEALTH Insight for the disk dimension must exist.
        $this->assertDatabaseHas('insights', [
            'team_id' => $server->team_id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::CRITICAL->value,
        ]);

        // The Insight's payload must include both sites.
        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->where('team_id', $server->team_id)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals('disk', $insight->payload['metric']);
        $this->assertEquals($server->id, $insight->payload['server_id']);
        $this->assertContains('alpha.example.com', $insight->payload['sites']);
        $this->assertContains('beta.example.com', $insight->payload['sites']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Case 8 — Optional fields absent → 201 (nullable columns)
    // ─────────────────────────────────────────────────────────────────────

    public function test_optional_fields_absent_still_returns_201(): void
    {
        [$server, $plain] = $this->makeActiveServerWithToken();

        // Send only the three required fields; omit all nullable ones.
        $payload = [
            'cpu_percent' => 10.0,
            'ram_percent' => 20.0,
            'disk_percent' => 30.0,
        ];

        $response = $this->postMetrics($payload, $plain);

        $response->assertStatus(201);

        $this->assertDatabaseHas('server_metrics', [
            'server_id' => $server->id,
            'cpu_percent' => '10.00',
            'ram_used_mb' => null,
            'ram_total_mb' => null,
            'disk_used_gb' => null,
            'disk_total_gb' => null,
        ]);
    }
}
