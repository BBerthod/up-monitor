<?php

namespace Tests\Feature\Jobs;

use App\Enums\WarmRunStatus;
use App\Jobs\DispatchWarmRuns;
use App\Jobs\RunWarmSite;
use App\Models\WarmRun;
use App\Models\WarmSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWarmRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_jobs_for_due_sites(): void
    {
        Queue::fake();

        // Due site (active, last warmed 3 hours ago, frequency 60 min)
        $due = WarmSite::factory()->dueForWarming()->create();

        // Not-due site (active, last warmed 10 minutes ago, frequency 60 min)
        WarmSite::factory()->create([
            'is_active' => true,
            'last_warmed_at' => now()->subMinutes(10),
            'frequency_minutes' => 60,
        ]);

        // Inactive site
        WarmSite::factory()->inactive()->create();

        (new DispatchWarmRuns)->handle();

        Queue::assertPushed(RunWarmSite::class, 1);
        Queue::assertPushed(RunWarmSite::class, fn (RunWarmSite $job) => $job->warmSite->id === $due->id);
    }

    public function test_dispatches_for_never_warmed_sites(): void
    {
        Queue::fake();

        // Never warmed (null last_warmed_at) should always be due
        $neverWarmed = WarmSite::factory()->create([
            'is_active' => true,
            'last_warmed_at' => null,
            'frequency_minutes' => 60,
        ]);

        (new DispatchWarmRuns)->handle();

        Queue::assertPushed(RunWarmSite::class, 1);
        Queue::assertPushed(RunWarmSite::class, fn (RunWarmSite $job) => $job->warmSite->id === $neverWarmed->id);
    }

    // -------------------------------------------------------------------------
    // Orphaned-run sweeper tests
    // -------------------------------------------------------------------------

    public function test_orphaned_run_older_than_15_minutes_is_marked_failed(): void
    {
        Queue::fake();

        $site = WarmSite::factory()->dueForWarming()->create();

        // Simulate a run left stuck in "running" 20 minutes ago (worker killed by deploy)
        $orphan = WarmRun::factory()->for($site, 'warmSite')->running()->create([
            'started_at' => now()->subMinutes(20),
        ]);

        (new DispatchWarmRuns)->handle();

        $orphan->refresh();
        $this->assertSame(WarmRunStatus::FAILED, $orphan->status);
        $this->assertStringContainsString('Orphaned run', $orphan->error_message);
        $this->assertNotNull($orphan->completed_at);
    }

    public function test_recent_running_run_under_15_minutes_is_not_touched(): void
    {
        Queue::fake();

        $site = WarmSite::factory()->dueForWarming()->create();

        // A run that started 2 minutes ago is still considered live
        $recent = WarmRun::factory()->for($site, 'warmSite')->running()->create([
            'started_at' => now()->subMinutes(2),
        ]);

        (new DispatchWarmRuns)->handle();

        $recent->refresh();
        $this->assertSame(WarmRunStatus::RUNNING, $recent->status);
        $this->assertNull($recent->completed_at);
    }

    public function test_completed_run_is_not_touched_by_sweeper(): void
    {
        Queue::fake();

        $site = WarmSite::factory()->dueForWarming()->create();

        // A completed run that finished 30 minutes ago must not be altered
        $completed = WarmRun::factory()->for($site, 'warmSite')->create([
            'status' => WarmRunStatus::COMPLETED,
            'started_at' => now()->subMinutes(35),
            'completed_at' => now()->subMinutes(30),
        ]);

        $originalCompletedAt = $completed->completed_at->toIso8601String();

        (new DispatchWarmRuns)->handle();

        $completed->refresh();
        $this->assertSame(WarmRunStatus::COMPLETED, $completed->status);
        $this->assertSame($originalCompletedAt, $completed->completed_at->toIso8601String());
    }
}
