<?php

namespace Tests\Unit\Services\Checkers;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Checkers\PingChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class PingCheckerTest extends TestCase
{
    use RefreshDatabase;

    private PingChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new PingChecker;
    }

    // ──────────────────────────────────────────────────
    // Output parsing (white-box, via reflection)
    // ──────────────────────────────────────────────────

    public function test_parse_average_ping_time_from_linux_output(): void
    {
        $output = "64 bytes from 8.8.8.8: icmp_seq=1 ttl=117 time=5.2 ms\n"
                ."--- 8.8.8.8 ping statistics ---\n"
                ."3 packets transmitted, 3 received, 0% packet loss, time 2002ms\n"
                .'rtt min/avg/max/mdev = 4.832/5.678/6.120/0.521 ms';

        $result = $this->invokeParseAveragePingTime($output);

        $this->assertEquals(6, $result); // round(5.678) = 6
    }

    public function test_parse_average_ping_time_from_macos_output(): void
    {
        $output = "PING 8.8.8.8 (8.8.8.8): 56 data bytes\n"
                ."64 bytes from 8.8.8.8: icmp_seq=0 ttl=117 time=12.5 ms\n"
                ."--- 8.8.8.8 ping statistics ---\n"
                ."3 packets transmitted, 3 packets received, 0.0% packet loss\n"
                .'round-trip min/avg/max/stddev = 10.001/12.334/15.567/1.234 ms';

        $result = $this->invokeParseAveragePingTime($output);

        $this->assertEquals(12, $result); // round(12.334) = 12
    }

    public function test_parse_average_ping_time_returns_null_for_unparseable_output(): void
    {
        $result = $this->invokeParseAveragePingTime('no stats line here');

        $this->assertNull($result);
    }

    public function test_parse_average_ping_time_handles_empty_output(): void
    {
        $result = $this->invokeParseAveragePingTime('');

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────────────
    // SSRF guard
    // ──────────────────────────────────────────────────

    public function test_private_ip_returns_down_with_ssrf_error(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->ping()->for($team)->create(['url' => '192.168.1.1']);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
        $this->assertStringContainsString('private', strtolower($result->errorMessage));
    }

    public function test_localhost_returns_down_with_ssrf_error(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->ping()->for($team)->create(['url' => '127.0.0.1']);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
    }

    public function test_link_local_address_returns_down_with_ssrf_error(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->ping()->for($team)->create(['url' => '169.254.1.1']);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
    }

    // ──────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────

    private function invokeParseAveragePingTime(string $output): ?int
    {
        $method = new ReflectionMethod(PingChecker::class, 'parseAveragePingTime');
        $method->setAccessible(true);

        return $method->invoke($this->checker, $output);
    }
}
