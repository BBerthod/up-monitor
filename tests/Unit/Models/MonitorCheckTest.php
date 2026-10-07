<?php

namespace Tests\Unit\Models;

use App\Enums\CheckStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_warning_returns_true_when_above_threshold(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->withThresholds(500, 1000)->create();
        $check = MonitorCheck::factory()->for($monitor)->create(['response_time_ms' => 600]);

        $this->assertTrue($check->isWarning());
    }

    public function test_is_warning_returns_false_when_below_threshold(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->withThresholds(500, 1000)->create();
        $check = MonitorCheck::factory()->for($monitor)->create(['response_time_ms' => 400]);

        $this->assertFalse($check->isWarning());
    }

    public function test_is_warning_returns_false_when_no_threshold(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'warning_threshold_ms' => null,
        ]);
        $check = MonitorCheck::factory()->for($monitor)->create(['response_time_ms' => 5000]);

        $this->assertFalse($check->isWarning());
    }

    public function test_is_critical_returns_true_when_above_threshold(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->withThresholds(500, 1000)->create();
        $check = MonitorCheck::factory()->for($monitor)->create(['response_time_ms' => 1200]);

        $this->assertTrue($check->isCritical());
    }

    public function test_is_critical_returns_false_when_below_threshold(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->withThresholds(500, 1000)->create();
        $check = MonitorCheck::factory()->for($monitor)->create(['response_time_ms' => 800]);

        $this->assertFalse($check->isCritical());
    }

    public function test_up_scope_filters_up_checks(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        MonitorCheck::factory()->for($monitor)->create(['status' => CheckStatus::UP]);
        MonitorCheck::factory()->for($monitor)->create(['status' => CheckStatus::DOWN]);

        $checks = MonitorCheck::up()->get();

        $this->assertCount(1, $checks);
        $this->assertEquals(CheckStatus::UP, $checks->first()->status);
    }

    public function test_down_scope_filters_down_checks(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        MonitorCheck::factory()->for($monitor)->create(['status' => CheckStatus::UP]);
        MonitorCheck::factory()->for($monitor)->create(['status' => CheckStatus::DOWN]);

        $checks = MonitorCheck::down()->get();

        $this->assertCount(1, $checks);
        $this->assertEquals(CheckStatus::DOWN, $checks->first()->status);
    }
}
