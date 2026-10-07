<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PruneWarmRuns;
use App\Models\WarmRun;
use App\Models\WarmSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneWarmRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_runs_older_than_retention(): void
    {
        $site = WarmSite::factory()->create();

        // Old run (200 days ago)
        $old = WarmRun::factory()->for($site)->create([
            'created_at' => now()->subDays(200),
        ]);

        // Recent run (10 days ago)
        $recent = WarmRun::factory()->for($site)->create([
            'created_at' => now()->subDays(10),
        ]);

        (new PruneWarmRuns)->handle();

        $this->assertDatabaseMissing('warm_runs', ['id' => $old->id]);
        $this->assertDatabaseHas('warm_runs', ['id' => $recent->id]);
    }

    public function test_respects_configured_retention_days(): void
    {
        config(['warming.retention_days' => 30]);

        $site = WarmSite::factory()->create();

        $run = WarmRun::factory()->for($site)->create([
            'created_at' => now()->subDays(45),
        ]);

        (new PruneWarmRuns)->handle();

        $this->assertDatabaseMissing('warm_runs', ['id' => $run->id]);
    }
}
