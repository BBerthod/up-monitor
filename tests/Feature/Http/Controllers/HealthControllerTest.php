<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────
    // Healthy — 200
    // ──────────────────────────────────────────────────

    public function test_returns_200_when_checks_are_recent(): void
    {
        MonitorCheck::factory()->create(['checked_at' => now()->subSeconds(30)]);

        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'healthy');
    }

    public function test_returns_200_with_no_monitors_yet(): void
    {
        // Fresh install: no checks at all is not a stall.
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'healthy');
    }

    public function test_response_structure_contains_checks_and_timestamp(): void
    {
        MonitorCheck::factory()->create(['checked_at' => now()->subSeconds(10)]);

        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'redis',
                    'queue',
                    'scheduler',
                ],
                'timestamp',
            ]);
    }

    // ──────────────────────────────────────────────────
    // Stall detection — 503
    // ──────────────────────────────────────────────────

    public function test_returns_503_when_last_check_exceeds_stall_threshold(): void
    {
        $team = Team::factory()->create();

        // Monitor with a 1-minute interval; threshold will be max(10min, 2×1min) = 10 min.
        Monitor::factory()->for($team)->create([
            'is_active' => true,
            'interval' => 1,
        ]);

        // Last check is 15 minutes old — well past the 10-minute threshold.
        MonitorCheck::factory()->create(['checked_at' => now()->subMinutes(15)]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy');
    }

    public function test_returns_503_when_queue_backlog_exceeds_limit(): void
    {
        MonitorCheck::factory()->create(['checked_at' => now()->subSeconds(30)]);

        // Simulate a large queue backlog.
        Redis::shouldReceive('ping')->once()->andReturn(true);
        Redis::shouldReceive('llen')->with('queues:monitors')->once()->andReturn(1_001);

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy');
    }

    public function test_returns_503_when_redis_is_unreachable(): void
    {
        MonitorCheck::factory()->create(['checked_at' => now()->subSeconds(30)]);

        Redis::shouldReceive('ping')->once()->andThrow(new \Exception('Connection refused'));
        // Queue check falls back gracefully when Redis is down.
        Redis::shouldReceive('llen')->andThrow(new \Exception('Connection refused'));

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy');
    }

    // ──────────────────────────────────────────────────
    // Scheduler check detail
    // ──────────────────────────────────────────────────

    public function test_scheduler_check_includes_age_seconds(): void
    {
        MonitorCheck::factory()->create(['checked_at' => now()->subSeconds(45)]);

        $response = $this->getJson('/api/health');

        $response->assertOk();

        $ageSeconds = $response->json('checks.scheduler.value.latest_check_age_seconds');
        $this->assertIsInt($ageSeconds);
        $this->assertGreaterThanOrEqual(44, $ageSeconds);
    }
}
