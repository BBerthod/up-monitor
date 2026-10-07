<?php

namespace Tests\Feature\Jobs;

use App\Enums\KpiSource;
use App\Jobs\PruneKpiSnapshots;
use App\Models\KpiSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for PruneKpiSnapshots.
 *
 * kpi_snapshots was the only metrics table in the schema without a pruning
 * job — every sibling (monitor_checks, page_metrics, lighthouse scores, server
 * metrics, warm runs, ingest events, notification logs) had one. It grew by
 * roughly (sites × sources × metrics) rows a day, indefinitely.
 */
class PruneKpiSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(int $daysAgo, string $metric = 'clicks_28d'): KpiSnapshot
    {
        return KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::GSC->value,
            'metric' => $metric,
            'value' => 42,
            'period_days' => 28,
            'captured_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_deletes_snapshots_beyond_the_retention_window(): void
    {
        $old = $this->snapshot(daysAgo: 500);
        $recent = $this->snapshot(daysAgo: 10);

        (new PruneKpiSnapshots)->handle();

        $this->assertDatabaseMissing('kpi_snapshots', ['id' => $old->id]);
        $this->assertDatabaseHas('kpi_snapshots', ['id' => $recent->id]);
    }

    public function test_retains_a_full_year_for_year_on_year_comparison(): void
    {
        // The reason this window is the widest in the config: several sites here
        // are seasonal, and a comparison made in January must still be able to
        // see the previous January.
        $lastYear = $this->snapshot(daysAgo: 366);

        (new PruneKpiSnapshots)->handle();

        $this->assertDatabaseHas('kpi_snapshots', ['id' => $lastYear->id]);
    }

    public function test_retention_window_is_configurable(): void
    {
        config(['monitoring.kpi_snapshots.retention_days' => 30]);

        $beyond = $this->snapshot(daysAgo: 60);
        $within = $this->snapshot(daysAgo: 5);

        (new PruneKpiSnapshots)->handle();

        $this->assertDatabaseMissing('kpi_snapshots', ['id' => $beyond->id]);
        $this->assertDatabaseHas('kpi_snapshots', ['id' => $within->id]);
    }

    public function test_health_scores_are_pruned_on_the_same_window(): void
    {
        // health_score rows live in this table too and must not be exempt —
        // they are the densest series in it.
        $old = KpiSnapshot::create([
            'site' => 'example.com',
            'source' => KpiSource::CUSTOM->value,
            'metric' => 'health_score',
            'value' => 88,
            'period_days' => 1,
            'captured_at' => now()->subDays(500),
        ]);

        (new PruneKpiSnapshots)->handle();

        $this->assertDatabaseMissing('kpi_snapshots', ['id' => $old->id]);
    }

    public function test_deletes_a_backlog_larger_than_one_chunk(): void
    {
        // The chunked loop must drain fully rather than stopping after the
        // first 1000 rows; a first run against years of backlog depends on it.
        $rows = [];

        for ($i = 0; $i < 1200; $i++) {
            $rows[] = [
                'site' => 'example.com',
                'source' => KpiSource::GSC->value,
                'metric' => 'clicks_28d',
                'value' => 1,
                'period_days' => 28,
                'captured_at' => now()->subDays(500),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            KpiSnapshot::insert($chunk);
        }

        (new PruneKpiSnapshots)->handle();

        $this->assertSame(0, KpiSnapshot::count());
    }

    public function test_empty_table_is_a_no_op(): void
    {
        (new PruneKpiSnapshots)->handle();

        $this->assertSame(0, KpiSnapshot::count());
    }
}
