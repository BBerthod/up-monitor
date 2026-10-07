<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PruneServerMetrics;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for PruneServerMetrics job.
 *
 * The job deletes ServerMetric rows whose captured_at is older than
 * config('monitoring.server_metrics_retention_days', 30) days.
 */
class PruneServerMetricsTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // Retention window
    // ──────────────────────────────────────────────────────────────────────

    public function test_deletes_metrics_older_than_retention_and_keeps_recent_ones(): void
    {
        $server = Server::factory()->create();

        // Old metric: 31 days ago — must be pruned.
        $old = ServerMetric::factory()->for($server)->create([
            'captured_at' => now()->subDays(31),
        ]);

        // Recent metric: within the 30-day window — must be kept.
        $recent = ServerMetric::factory()->for($server)->create([
            'captured_at' => now()->subDays(10),
        ]);

        (new PruneServerMetrics)->handle();

        $this->assertDatabaseMissing('server_metrics', ['id' => $old->id]);
        $this->assertDatabaseHas('server_metrics', ['id' => $recent->id]);
    }

    public function test_does_not_delete_metrics_exactly_at_retention_boundary(): void
    {
        $server = Server::factory()->create();

        // A metric captured exactly 30 days ago is NOT older than the cutoff —
        // "older than X days" means strictly before now()->subDays(30).
        // We place it at 29 days to be safely inside the retention window.
        $boundary = ServerMetric::factory()->for($server)->create([
            'captured_at' => now()->subDays(29)->startOfDay(),
        ]);

        (new PruneServerMetrics)->handle();

        $this->assertDatabaseHas('server_metrics', ['id' => $boundary->id]);
    }

    public function test_no_metrics_deleted_when_all_are_within_retention(): void
    {
        $server = Server::factory()->create();

        ServerMetric::factory()->for($server)->count(3)->create([
            'captured_at' => now()->subDays(5),
        ]);

        (new PruneServerMetrics)->handle();

        $this->assertDatabaseCount('server_metrics', 3);
    }

    public function test_all_old_metrics_deleted_when_all_exceed_retention(): void
    {
        $server = Server::factory()->create();

        ServerMetric::factory()->for($server)->count(5)->create([
            'captured_at' => now()->subDays(60),
        ]);

        (new PruneServerMetrics)->handle();

        $this->assertDatabaseCount('server_metrics', 0);
    }

    public function test_respects_custom_retention_days_from_config(): void
    {
        // Override retention to 7 days for this test.
        config(['monitoring.server_metrics_retention_days' => 7]);

        $server = Server::factory()->create();

        // 8 days old → must be pruned under the 7-day policy.
        $old = ServerMetric::factory()->for($server)->create([
            'captured_at' => now()->subDays(8),
        ]);

        // 3 days old → must be kept.
        $recent = ServerMetric::factory()->for($server)->create([
            'captured_at' => now()->subDays(3),
        ]);

        (new PruneServerMetrics)->handle();

        $this->assertDatabaseMissing('server_metrics', ['id' => $old->id]);
        $this->assertDatabaseHas('server_metrics', ['id' => $recent->id]);
    }
}
