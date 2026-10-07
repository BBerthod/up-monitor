<?php

namespace Tests\Feature\Console;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for scheduler:health-check.
 *
 * This command had no tests at all despite issuing queue:restart, and it judged
 * every queue from a single signal — MonitorCheck::max('checked_at') — which
 * only moves when the `monitors` queue runs. A blocked warming, lighthouse or
 * notifications queue left it green while work silently stopped.
 */
class CheckSchedulerHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every queue empty unless a test says otherwise, so backlog checks do
        // not depend on a real broker.
        Queue::fake();
        Cache::flush();
    }

    private function recordCheck(int $minutesAgo): void
    {
        $monitor = Monitor::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]);

        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Healthy scheduler
    // ──────────────────────────────────────────────────────────────────────

    public function test_recent_checks_report_healthy(): void
    {
        $this->recordCheck(minutesAgo: 1);

        $this->artisan('scheduler:health-check')
            ->expectsOutputToContain('healthy')
            ->assertExitCode(0);
    }

    public function test_fresh_install_without_checks_is_not_a_failure(): void
    {
        $this->artisan('scheduler:health-check')
            ->expectsOutputToContain('fresh install')
            ->assertExitCode(0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Stalled scheduler
    // ──────────────────────────────────────────────────────────────────────

    public function test_stale_checks_report_a_stall(): void
    {
        $this->recordCheck(minutesAgo: 45);

        $this->artisan('scheduler:health-check', ['--stall-minutes' => 10])
            ->expectsOutputToContain('STALL')
            ->assertExitCode(1);
    }

    public function test_stall_threshold_is_never_below_two_minutes(): void
    {
        $this->recordCheck(minutesAgo: 1);

        // Asking for a 0-minute threshold would make every run a stall right
        // after a deploy; the floor protects against that.
        $this->artisan('scheduler:health-check', ['--stall-minutes' => 0])
            ->assertExitCode(0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Auto-recovery is rate-limited
    // ──────────────────────────────────────────────────────────────────────

    public function test_recovery_is_skipped_within_the_cooldown_window(): void
    {
        $this->recordCheck(minutesAgo: 45);

        // First run issues the restart.
        $this->artisan('scheduler:health-check')->assertExitCode(1);

        // Second run still reports the stall but must not restart again —
        // restarting every five minutes would turn a false positive into an
        // outage.
        $this->artisan('scheduler:health-check')
            ->expectsOutputToContain('Auto-recovery skipped')
            ->assertExitCode(1);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Failed jobs are surfaced, but are not "recoverable"
    // ──────────────────────────────────────────────────────────────────────

    public function test_recent_failed_jobs_are_reported(): void
    {
        $this->recordCheck(minutesAgo: 1);

        \DB::table('failed_jobs')->insert([
            'uuid' => (string) \Str::uuid(),
            'connection' => 'redis',
            'queue' => 'monitors',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now()->subMinutes(10),
        ]);

        $this->artisan('scheduler:health-check')
            ->expectsOutputToContain('failed permanently')
            ->assertExitCode(1);
    }

    public function test_failed_jobs_alone_do_not_trigger_a_restart(): void
    {
        $this->recordCheck(minutesAgo: 1);

        \DB::table('failed_jobs')->insert([
            'uuid' => (string) \Str::uuid(),
            'connection' => 'redis',
            'queue' => 'monitors',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now()->subMinutes(10),
        ]);

        // Restarting would only fail the same jobs again, faster.
        $this->artisan('scheduler:health-check')
            ->doesntExpectOutputToContain('queue:restart')
            ->assertExitCode(1);
    }

    public function test_old_failed_jobs_are_ignored(): void
    {
        $this->recordCheck(minutesAgo: 1);

        \DB::table('failed_jobs')->insert([
            'uuid' => (string) \Str::uuid(),
            'connection' => 'redis',
            'queue' => 'monitors',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now()->subDays(3),
        ]);

        $this->artisan('scheduler:health-check')->assertExitCode(0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Backlog state is recorded for the next run to compare against
    // ──────────────────────────────────────────────────────────────────────

    public function test_queue_depths_are_recorded_between_runs(): void
    {
        $this->recordCheck(minutesAgo: 1);

        $this->artisan('scheduler:health-check')->assertExitCode(0);

        $observed = Cache::get('scheduler:health:last-observation');

        // Every watched queue must be represented, otherwise the next run has
        // no baseline and a genuine stall reads as "first sighting".
        $this->assertIsArray($observed);
        $this->assertArrayHasKey('monitors', $observed);
        $this->assertArrayHasKey('warming', $observed);
        $this->assertArrayHasKey('lighthouse', $observed);
        $this->assertArrayHasKey('notifications', $observed);
    }
}
