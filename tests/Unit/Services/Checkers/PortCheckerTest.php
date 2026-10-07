<?php

namespace Tests\Unit\Services\Checkers;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Checkers\PortChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortCheckerTest extends TestCase
{
    use RefreshDatabase;

    private PortChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new PortChecker;
    }

    // ──────────────────────────────────────────────────
    // SSRF guard (private ranges)
    // ──────────────────────────────────────────────────

    public function test_private_ip_returns_down_with_ssrf_error(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->port(80)->for($team)->create(['url' => '10.0.0.1']);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(0, $result->responseTimeMs);
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
        $this->assertStringContainsString('private', strtolower($result->errorMessage));
    }

    public function test_localhost_returns_down_with_ssrf_error(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->port(3306)->for($team)->create(['url' => '127.0.0.1']);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
    }

    // ──────────────────────────────────────────────────
    // Closed port (integration, loopback-free)
    // ──────────────────────────────────────────────────

    /**
     * Port 65000 on example.com is virtually guaranteed to be closed.
     * This is a lightweight integration test — it issues a real TCP connection
     * attempt with a 10-second timeout. Acceptable because:
     *   1) It proves the connection-refused branch actually runs.
     *   2) example.com is an IANA-reserved public host (no production risk).
     *
     * @group network
     */
    public function test_closed_port_returns_down(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->port(65000)->for($team)->create([
            'url' => 'example.com',
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertNotNull($result->errorMessage);
        $this->assertContains($result->cause, [IncidentCause::ERROR, IncidentCause::TIMEOUT]);
    }

    // ──────────────────────────────────────────────────
    // Response time is always set
    // ──────────────────────────────────────────────────

    public function test_response_time_is_non_negative_for_private_ip(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->port(80)->for($team)->create(['url' => '192.168.0.1']);

        $result = $this->checker->check($monitor);

        $this->assertGreaterThanOrEqual(0, $result->responseTimeMs);
    }
}
