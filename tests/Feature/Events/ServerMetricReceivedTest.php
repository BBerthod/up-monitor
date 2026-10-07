<?php

namespace Tests\Feature\Events;

use App\Events\ServerMetricReceived;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Feature tests for ServerMetricReceived event + POST /api/servers/metrics dispatch.
 *
 * Covers:
 *   1. A valid POST to the ingestion endpoint dispatches ServerMetricReceived.
 *   2. broadcastOn() returns a PrivateChannel named 'team.{teamId}'.
 *   3. broadcastAs() returns 'server.metric.received'.
 *   4. The dedup path (second POST within 60 s) does NOT dispatch the event again.
 *
 * Auth: Authorization: Bearer <plain-token> (stateless push endpoint, no CSRF).
 */
class ServerMetricReceivedTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build an active server with an ingest token.
     * Returns [$server, $plainToken].
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
     * A minimal valid payload (three required fields, all optional fields absent).
     */
    private function validPayload(array $extra = []): array
    {
        return array_merge([
            'cpu_percent' => 20.0,
            'ram_percent' => 30.0,
            'disk_percent' => 40.0,
        ], $extra);
    }

    /**
     * POST to the metrics endpoint with an optional Bearer token.
     */
    private function postMetrics(array $payload, ?string $bearerToken = null): \Illuminate\Testing\TestResponse
    {
        $headers = $bearerToken !== null
            ? ['Authorization' => 'Bearer '.$bearerToken]
            : [];

        return $this->postJson(route('api.servers.metrics'), $payload, $headers);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Valid POST dispatches ServerMetricReceived
    // ──────────────────────────────────────────────────────────────────────

    public function test_valid_post_dispatches_server_metric_received_event(): void
    {
        Event::fake([ServerMetricReceived::class]);

        [$server, $plain] = $this->makeActiveServerWithToken();

        $response = $this->postMetrics($this->validPayload(), $plain);

        $response->assertStatus(201);

        Event::assertDispatched(ServerMetricReceived::class);
    }

    public function test_dispatched_event_carries_the_correct_server(): void
    {
        Event::fake([ServerMetricReceived::class]);

        [$server, $plain] = $this->makeActiveServerWithToken();

        $this->postMetrics($this->validPayload(), $plain);

        Event::assertDispatched(ServerMetricReceived::class, function (ServerMetricReceived $event) use ($server) {
            return $event->server->id === $server->id;
        });
    }

    public function test_dispatched_event_carries_the_persisted_metric(): void
    {
        Event::fake([ServerMetricReceived::class]);

        [$server, $plain] = $this->makeActiveServerWithToken();

        $this->postMetrics($this->validPayload([
            'cpu_percent' => 55.5,
        ]), $plain);

        Event::assertDispatched(ServerMetricReceived::class, function (ServerMetricReceived $event) {
            return $event->metric instanceof ServerMetric
                && $event->metric->exists;
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. broadcastOn() — unit-level assertion on the event instance
    // ──────────────────────────────────────────────────────────────────────

    public function test_broadcast_on_returns_private_channel_scoped_to_team(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->withoutMonitoring()->create(['team_id' => $team->id]);
        $metric = ServerMetric::factory()->for($server)->create();

        $event = new ServerMetricReceived($server, $metric);
        $channel = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        // PrivateChannel prefixes the name with 'private-' internally; the constructor
        // accepts the logical name without the prefix.
        $this->assertEquals('private-team.'.$team->id, $channel->name);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. broadcastAs() — unit-level assertion on the event instance
    // ──────────────────────────────────────────────────────────────────────

    public function test_broadcast_as_returns_correct_event_name(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->withoutMonitoring()->create(['team_id' => $team->id]);
        $metric = ServerMetric::factory()->for($server)->create();

        $event = new ServerMetricReceived($server, $metric);

        $this->assertEquals('server.metric.received', $event->broadcastAs());
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Dedup path — second POST within 60 s must NOT re-dispatch the event
    // ──────────────────────────────────────────────────────────────────────

    public function test_deduplicated_post_does_not_dispatch_event_again(): void
    {
        Event::fake([ServerMetricReceived::class]);

        [$server, $plain] = $this->makeActiveServerWithToken();

        // First POST — new metric, event must be dispatched once.
        $first = $this->postMetrics($this->validPayload(), $plain);
        $first->assertStatus(201);

        Event::assertDispatchedTimes(ServerMetricReceived::class, 1);

        // Second POST within the 60-second dedup window — must be deduplicated (200),
        // no new ServerMetricReceived dispatched.
        $second = $this->postMetrics($this->validPayload(), $plain);
        $second->assertStatus(200);
        $second->assertJson(['deduplicated' => true]);

        // Still exactly one dispatch, not two.
        Event::assertDispatchedTimes(ServerMetricReceived::class, 1);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. No event dispatched on 401 (unauthenticated)
    // ──────────────────────────────────────────────────────────────────────

    public function test_no_event_dispatched_when_token_is_missing(): void
    {
        Event::fake([ServerMetricReceived::class]);

        $response = $this->postMetrics($this->validPayload());

        $response->assertStatus(401);

        Event::assertNotDispatched(ServerMetricReceived::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. broadcastWith() shape
    // ──────────────────────────────────────────────────────────────────────

    public function test_broadcast_with_returns_server_and_metric_keys(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->withoutMonitoring()->create([
            'team_id' => $team->id,
            'name' => 'web-01',
        ]);
        $metric = ServerMetric::factory()->for($server)->create([
            'cpu_percent' => 42.5,
            'ram_percent' => 55.0,
            'disk_percent' => 30.0,
            'captured_at' => now(),
        ]);

        $event = new ServerMetricReceived($server, $metric);
        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('server', $payload);
        $this->assertArrayHasKey('metric', $payload);

        $this->assertEquals($server->id, $payload['server']['id']);
        $this->assertEquals('web-01', $payload['server']['name']);

        $this->assertEquals(42.5, $payload['metric']['cpu']);
        $this->assertEquals(55.0, $payload['metric']['ram']);
        $this->assertEquals(30.0, $payload['metric']['disk']);
        $this->assertArrayHasKey('captured_at', $payload['metric']);
    }
}
