<?php

namespace Tests\Unit\Services;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Team;
use App\Services\DashboardOverviewService;
use App\Services\UptimeTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit tests for the cockpit's one-sentence global state and per-monitor rows.
 *
 * scoreForTeam()/HealthScoreService are not exercised here: siteRows() only
 * merges state onto whatever array it is given, so a hand-built site array is
 * enough to prove the merge and the ordering.
 */
class DashboardOverviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DashboardOverviewService
    {
        return new DashboardOverviewService(new UptimeTimelineService);
    }

    // ── statusSummary ────────────────────────────────────────────────────────

    public function test_all_up_and_nothing_to_review_gives_calm_sentence(): void
    {
        $states = [
            1 => ['id' => 1, 'name' => 'alpha', 'status' => 'up', 'last_checked_at' => now()->toIso8601String(), 'down_since' => null],
            2 => ['id' => 2, 'name' => 'beta', 'status' => 'up', 'last_checked_at' => now()->toIso8601String(), 'down_since' => null],
        ];

        $summary = $this->service()->statusSummary($states, ['total' => 0, 'critical' => 0]);

        $this->assertSame('ok', $summary['level']);
        $this->assertSame('All 2 monitors up · nothing needs attention', $summary['headline']);
        $this->assertSame(2, $summary['monitors_up']);
    }

    public function test_one_monitor_down_names_it_with_duration(): void
    {
        $states = [
            1 => [
                'id' => 1, 'name' => 'charlie', 'status' => 'down',
                'last_checked_at' => now()->toIso8601String(),
                'down_since' => now()->subMinutes(85)->toIso8601String(),
            ],
        ];

        $summary = $this->service()->statusSummary($states, ['total' => 1, 'critical' => 1]);

        $this->assertSame('critical', $summary['level']);
        $this->assertStringContainsString('1 monitor down: charlie (for 1 h 25)', $summary['headline']);
        $this->assertStringContainsString('1 critical issue needs attention', $summary['headline']);
    }

    public function test_multiple_down_monitors_collapse_into_plus_n_more(): void
    {
        $states = [];
        foreach (['alpha', 'beta', 'gamma', 'delta'] as $i => $name) {
            $states[$i] = ['id' => $i, 'name' => $name, 'status' => 'down', 'last_checked_at' => null, 'down_since' => null];
        }

        $summary = $this->service()->statusSummary($states, ['total' => 4, 'critical' => 4]);

        $this->assertStringContainsString('4 monitors down: alpha, beta +2 more', $summary['headline']);
    }

    public function test_warnings_without_critical_are_reported_as_warning_level(): void
    {
        $states = [
            1 => ['id' => 1, 'name' => 'alpha', 'status' => 'up', 'last_checked_at' => now()->toIso8601String(), 'down_since' => null],
        ];

        $summary = $this->service()->statusSummary($states, ['total' => 3, 'critical' => 0]);

        $this->assertSame('warning', $summary['level']);
        $this->assertStringContainsString('3 warnings to review', $summary['headline']);
    }

    public function test_pending_monitors_are_reported_separately_from_up(): void
    {
        $states = [
            1 => ['id' => 1, 'name' => 'alpha', 'status' => 'up', 'last_checked_at' => now()->toIso8601String(), 'down_since' => null],
            2 => ['id' => 2, 'name' => 'beta', 'status' => 'pending', 'last_checked_at' => null, 'down_since' => null],
        ];

        $summary = $this->service()->statusSummary($states, ['total' => 0, 'critical' => 0]);

        $this->assertSame('1 of 2 monitors up · 1 awaiting a first check · nothing needs attention', $summary['headline']);
    }

    // ── duration() ───────────────────────────────────────────────────────────

    public function test_duration_formats_minutes_hours_and_days(): void
    {
        $now = Carbon::parse('2026-01-10 12:00:00');

        $this->assertSame('12 min', DashboardOverviewService::duration(Carbon::parse('2026-01-10 11:48:00'), $now));
        $this->assertSame('1 h 25', DashboardOverviewService::duration(Carbon::parse('2026-01-10 10:35:00'), $now));
        $this->assertSame('2 d 3 h', DashboardOverviewService::duration(Carbon::parse('2026-01-08 09:00:00'), $now));
    }

    // ── monitorStates() ──────────────────────────────────────────────────────

    public function test_monitor_states_reports_last_checked_at_and_down_since(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['team_id' => $team->id, 'is_active' => true]);

        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'status' => 'down',
            'checked_at' => now()->subMinutes(5),
        ]);

        MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'started_at' => now()->subHour(),
            'resolved_at' => null,
        ]);

        $states = $this->service()->monitorStates($team);

        $this->assertSame('down', $states[$monitor->id]['status']);
        $this->assertNotNull($states[$monitor->id]['last_checked_at']);
        $this->assertNotNull($states[$monitor->id]['down_since']);
    }

    public function test_monitor_with_no_checks_is_pending(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['team_id' => $team->id, 'is_active' => true]);

        $states = $this->service()->monitorStates($team);

        $this->assertSame('pending', $states[$monitor->id]['status']);
        $this->assertNull($states[$monitor->id]['down_since']);
    }

    // ── siteRows() ───────────────────────────────────────────────────────────

    public function test_site_rows_orders_down_before_pending_before_up(): void
    {
        $states = [
            1 => ['id' => 1, 'name' => 'up-site', 'status' => 'up', 'last_checked_at' => null, 'down_since' => null],
            2 => ['id' => 2, 'name' => 'down-site', 'status' => 'down', 'last_checked_at' => null, 'down_since' => null],
            3 => ['id' => 3, 'name' => 'pending-site', 'status' => 'pending', 'last_checked_at' => null, 'down_since' => null],
        ];

        $sites = [
            ['monitor_id' => 1, 'score' => 90],
            ['monitor_id' => 2, 'score' => 90],
            ['monitor_id' => 3, 'score' => 90],
        ];

        $rows = $this->service()->siteRows($sites, $states);

        $this->assertSame([2, 3, 1], array_column($rows, 'monitor_id'));
    }
}
