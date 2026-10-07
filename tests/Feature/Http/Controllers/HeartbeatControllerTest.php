<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Heartbeat;
use App\Models\Insight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for the heartbeat ping endpoint.
 *
 * The caller here is a crontab line, not an application: the realistic
 * integration is a trailing `curl` after the real work, which is why the token
 * is accepted in the path as well as in a Bearer header.
 */
class HeartbeatControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a heartbeat and return it with its plain token.
     *
     * @return array{0: Heartbeat, 1: string}
     */
    private function makeHeartbeat(array $attributes = []): array
    {
        $plain = Str::random(48);

        $heartbeat = Heartbeat::factory()->create(array_merge([
            'token_hash' => Heartbeat::hashToken($plain),
        ], $attributes));

        return [$heartbeat, $plain];
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Ping via the path — the crontab shape
    // ──────────────────────────────────────────────────────────────────────

    public function test_ping_with_path_token_records_the_ping(): void
    {
        [$heartbeat, $token] = $this->makeHeartbeat(['last_ping_at' => now()->subHour()]);

        $this->getJson("/api/heartbeat/{$token}")
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertTrue($heartbeat->fresh()->last_ping_at->isAfter(now()->subMinute()));
    }

    public function test_ping_accepts_post_as_well_as_get(): void
    {
        [$heartbeat, $token] = $this->makeHeartbeat();

        $this->postJson("/api/heartbeat/{$token}")->assertOk();

        $this->assertNotNull($heartbeat->fresh()->last_ping_at);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Ping via Bearer — the preferred shape
    // ──────────────────────────────────────────────────────────────────────

    public function test_ping_with_bearer_token(): void
    {
        [$heartbeat, $token] = $this->makeHeartbeat();

        $this->postJson('/api/heartbeat', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->assertNotNull($heartbeat->fresh()->last_ping_at);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Authentication
    // ──────────────────────────────────────────────────────────────────────

    public function test_unknown_token_is_rejected(): void
    {
        $this->getJson('/api/heartbeat/'.Str::random(48))->assertStatus(401);
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->postJson('/api/heartbeat')->assertStatus(401);
    }

    public function test_inactive_heartbeat_token_is_rejected(): void
    {
        [, $token] = $this->makeHeartbeat(['is_active' => false]);

        $this->getJson("/api/heartbeat/{$token}")->assertStatus(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Recovery — a ping re-arms the switch and closes the insight
    // ──────────────────────────────────────────────────────────────────────

    public function test_ping_clears_the_alert_stamp(): void
    {
        [$heartbeat, $token] = $this->makeHeartbeat(['alerted_at' => now()->subHour()]);

        $this->getJson("/api/heartbeat/{$token}")->assertOk();

        // While alerted_at is set the sweep stays quiet, so failing to clear it
        // would silence this heartbeat permanently after its first outage.
        $this->assertNull($heartbeat->fresh()->alerted_at);
    }

    public function test_ping_resolves_the_open_insight(): void
    {
        [$heartbeat, $token] = $this->makeHeartbeat(['alerted_at' => now()->subHour()]);

        $insight = Insight::create([
            'team_id' => $heartbeat->team_id,
            'site' => 'portfolio',
            'type' => InsightType::HEARTBEAT_MISSED->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'Scheduled task stopped reporting',
            'payload' => ['heartbeat_id' => $heartbeat->id],
            'impact_score' => 40,
            'detected_at' => now()->subHour(),
        ]);

        $this->getJson("/api/heartbeat/{$token}")
            ->assertOk()
            ->assertJsonPath('recovered', true);

        $this->assertNotNull($insight->fresh()->acknowledged_at);
    }

    public function test_ping_does_not_resolve_another_heartbeats_insight(): void
    {
        [$heartbeat, $token] = $this->makeHeartbeat(['alerted_at' => now()->subHour()]);
        [$other] = $this->makeHeartbeat(['team_id' => $heartbeat->team_id]);

        $otherInsight = Insight::create([
            'team_id' => $heartbeat->team_id,
            'site' => 'portfolio',
            'type' => InsightType::HEARTBEAT_MISSED->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'A different task stopped reporting',
            'payload' => ['heartbeat_id' => $other->id],
            'impact_score' => 40,
            'detected_at' => now()->subHour(),
        ]);

        $this->getJson("/api/heartbeat/{$token}")->assertOk();

        // Recovering one task must not silence another's outage.
        $this->assertNull($otherInsight->fresh()->acknowledged_at);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. The response tells the caller when it is next expected
    // ──────────────────────────────────────────────────────────────────────

    public function test_response_reports_the_next_deadline(): void
    {
        [, $token] = $this->makeHeartbeat([
            'expected_period_minutes' => 60,
            'grace_minutes' => 10,
        ]);

        $response = $this->getJson("/api/heartbeat/{$token}")->assertOk();

        $nextDue = $response->json('next_due_at');

        $this->assertNotNull($nextDue);
        // now + 60 + 10, give or take the second the request took.
        $this->assertTrue(now()->addMinutes(69)->lessThan($nextDue));
    }
}
