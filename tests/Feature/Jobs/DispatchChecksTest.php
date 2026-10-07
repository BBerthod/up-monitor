<?php

namespace Tests\Feature\Jobs;

use App\Jobs\DispatchChecks;
use App\Jobs\RunCheck;
use App\Models\Monitor;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_run_check_for_each_overdue_monitor(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        // 3 active monitors with no last_checked_at (always due).
        Monitor::factory()->count(3)->for($team)->create([
            'is_active' => true,
            'last_checked_at' => null,
        ]);

        (new DispatchChecks)->handle();

        Queue::assertPushed(RunCheck::class, 3);
    }

    public function test_does_not_dispatch_for_inactive_monitors(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        Monitor::factory()->inactive()->for($team)->create(['last_checked_at' => null]);

        (new DispatchChecks)->handle();

        Queue::assertNothingPushed();
    }

    public function test_does_not_dispatch_for_recently_checked_monitors(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        // last_checked_at = now → not due yet (interval=1 min, checked just now).
        Monitor::factory()->for($team)->create([
            'is_active' => true,
            'interval' => 1,
            'last_checked_at' => now(),
        ]);

        (new DispatchChecks)->handle();

        Queue::assertNothingPushed();
    }

    public function test_dispatches_only_overdue_monitors_in_mixed_set(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        // 2 overdue monitors.
        Monitor::factory()->count(2)->for($team)->create([
            'is_active' => true,
            'last_checked_at' => null,
        ]);

        // 1 recently checked.
        Monitor::factory()->for($team)->create([
            'is_active' => true,
            'interval' => 1,
            'last_checked_at' => now(),
        ]);

        // 1 inactive.
        Monitor::factory()->inactive()->for($team)->create(['last_checked_at' => null]);

        (new DispatchChecks)->handle();

        Queue::assertPushed(RunCheck::class, 2);
    }

    public function test_dispatches_correct_monitor_to_run_check(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'is_active' => true,
            'last_checked_at' => null,
        ]);

        (new DispatchChecks)->handle();

        Queue::assertPushed(RunCheck::class, function (RunCheck $job) use ($monitor) {
            return $job->monitor->id === $monitor->id;
        });
    }
}
