<?php

namespace Tests\Feature\Services;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Team;
use App\Services\UptimeTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UptimeTimelineServiceTest extends TestCase
{
    use RefreshDatabase;

    private UptimeTimelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->service = app(UptimeTimelineService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function check(Monitor $monitor, CheckStatus $status, Carbon $at): void
    {
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => $status,
            'response_time_ms' => 100,
            'status_code' => $status === CheckStatus::UP ? 200 : 500,
            'checked_at' => $at,
        ]);
    }

    public function test_it_returns_one_entry_per_day_with_null_uptime_when_no_data(): void
    {
        $monitor = Monitor::factory()->for(Team::factory())->create();
        $this->check($monitor, CheckStatus::UP, now()->subHour());

        $timeline = $this->service->timeline([$monitor->id], 90)[$monitor->id];

        $this->assertCount(90, $timeline['days']);
        $this->assertSame('2026-06-26', $timeline['days'][0]['date']);
        $this->assertSame('2026-09-23', $timeline['days'][89]['date']);
        $this->assertNull($timeline['days'][0]['uptime']);
        $this->assertSame('no_data', $timeline['days'][0]['status']);
        $this->assertSame(100.0, $timeline['days'][89]['uptime']);
        $this->assertSame('up', $timeline['days'][89]['status']);
        $this->assertSame(1, $timeline['measured_days']);
    }

    public function test_it_classifies_days_and_aggregates_windows_from_check_counts(): void
    {
        $monitor = Monitor::factory()->for(Team::factory())->create();

        // Yesterday: 1 up / 3 checks → 33.3 % → down.
        $this->check($monitor, CheckStatus::UP, now()->subDay());
        $this->check($monitor, CheckStatus::DOWN, now()->subDay()->addMinute());
        $this->check($monitor, CheckStatus::DOWN, now()->subDay()->addMinutes(2));
        // 40 days ago: 3 up / 4 → 75 % → partial, inside 90 d but outside 30 d.
        foreach ([CheckStatus::UP, CheckStatus::UP, CheckStatus::UP, CheckStatus::DOWN] as $i => $status) {
            $this->check($monitor, $status, now()->subDays(40)->addMinutes($i));
        }

        $timeline = $this->service->timeline(collect([$monitor->id]))[$monitor->id];

        $this->assertSame('down', $timeline['days'][88]['status']);
        $this->assertSame(33.3, $timeline['days'][88]['uptime']);
        $this->assertSame('partial', $timeline['days'][89 - 40]['status']);
        $this->assertSame(33.33, $timeline['uptime_30d']);
        $this->assertSame(57.14, $timeline['uptime_90d']); // 4 up / 7 checks
        $this->assertSame(2, $timeline['measured_days']);
    }

    public function test_incidents_are_attached_to_every_day_they_cover_and_mark_the_day(): void
    {
        $monitor = Monitor::factory()->for(Team::factory())->create();
        foreach ([2, 1, 0] as $daysAgo) {
            $this->check($monitor, CheckStatus::UP, now()->subDays($daysAgo)->startOfDay()->addHour());
        }
        MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subDays(2)->setTime(23, 30),
            'resolved_at' => now()->subDay()->setTime(0, 15),
            'notes' => 'internal note',
        ]);

        $days = $this->service->timeline([$monitor->id])[$monitor->id]['days'];

        $this->assertSame('partial', $days[87]['status']);
        $this->assertSame('partial', $days[88]['status']);
        $this->assertSame('up', $days[89]['status']);
        $this->assertCount(1, $days[87]['incidents']);
        $incident = $days[88]['incidents'][0];
        $this->assertSame('Response timeout', $incident['cause_label']);
        $this->assertSame(45 * 60, $incident['duration_seconds']);
        $this->assertFalse($incident['ongoing']);
        $this->assertArrayNotHasKey('notes', $incident);
    }

    public function test_it_returns_an_empty_array_for_no_monitors(): void
    {
        $this->assertSame([], $this->service->timeline([]));
    }
}
