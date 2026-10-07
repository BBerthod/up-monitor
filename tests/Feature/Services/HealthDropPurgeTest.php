<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Services\HealthDropDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies HealthDropDetector purges stale non-acknowledged HEALTH_DROP insights
 * when a grade stabilises or improves, while preserving acknowledged ones (Fix 2).
 * The purge is scoped by SITE, so it also clears rows left by sibling monitors
 * before the dedup fix was deployed.
 *
 * Companion file (dedup behaviour): HealthDropDeduplicationTest.
 */
class HealthDropPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(): Team
    {
        return Team::factory()->create();
    }

    private function makeSite(Team $team, string $domain = 'example.com'): Site
    {
        return Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => $domain,
            'is_active' => true,
        ]);
    }

    private function makeMonitor(Team $team, Site $site, string $url): Monitor
    {
        return Monitor::factory()->create([
            'team_id' => $team->id,
            'site_id' => $site->id,
            'url' => $url,
        ]);
    }

    private function seedHealthSnapshots(
        string $site,
        float $prevScore,
        string $prevGrade,
        float $curScore,
        string $curGrade,
    ): void {
        KpiSnapshot::factory()->create([
            'site' => $site,
            'source' => KpiSource::CUSTOM->value,
            'metric' => 'health_score',
            'value' => $prevScore,
            'period_days' => 1,
            'captured_at' => now()->subDay(),
            'meta' => ['grade' => $prevGrade],
        ]);
        KpiSnapshot::factory()->create([
            'site' => $site,
            'source' => KpiSource::CUSTOM->value,
            'metric' => 'health_score',
            'value' => $curScore,
            'period_days' => 1,
            'captured_at' => now(),
            'meta' => ['grade' => $curGrade],
        ]);
    }

    private function countOpenHealthDrops(string $site): int
    {
        return Insight::withoutGlobalScopes()
            ->where('site', $site)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->count();
    }

    /**
     * A stable-grade run (B→B) must purge the pre-existing non-acknowledged
     * insight (now stale) but leave the acknowledged one untouched.
     */
    public function test_stable_grade_purges_unacknowledged_but_preserves_acknowledged_insight(): void
    {
        $team = $this->makeTeam();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site, 'https://example.com');

        $unacked = Insight::factory()->create([
            'team_id' => $team->id,
            'site' => 'example.com',
            'monitor_id' => $monitor->id,
            'type' => InsightType::HEALTH_DROP,
            'severity' => InsightSeverity::WARNING,
            'payload' => ['previous_grade' => 'B', 'current_grade' => 'C'],
            'impact_score' => 10.0,
            'detected_at' => now()->subDay(),
            'acknowledged_at' => null,
        ]);

        $acked = Insight::factory()->create([
            'team_id' => $team->id,
            'site' => 'example.com',
            'monitor_id' => $monitor->id,
            'type' => InsightType::HEALTH_DROP,
            'severity' => InsightSeverity::WARNING,
            'payload' => ['previous_grade' => 'B', 'current_grade' => 'C'],
            'impact_score' => 10.0,
            'detected_at' => now()->subDays(3),
            'acknowledged_at' => now()->subDays(2),
        ]);

        // Stable: B→B (no drop).
        $this->seedHealthSnapshots('example.com', 85, 'B', 84, 'B');

        app(HealthDropDetector::class)->detectForMonitor($monitor);

        $this->assertDatabaseMissing('insights', ['id' => $unacked->id]);
        $this->assertDatabaseHas('insights', ['id' => $acked->id]);
        $this->assertSame(0, $this->countOpenHealthDrops('example.com'));
    }

    /**
     * A grade improvement (D→B) must purge ALL non-acknowledged HEALTH_DROP rows
     * for the site (including duplicates from sibling monitors) while leaving
     * acknowledged ones intact.
     */
    public function test_grade_improvement_purges_all_unacknowledged_insights_for_site(): void
    {
        $team = $this->makeTeam();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site, 'https://example.com');

        $base = [
            'team_id' => $team->id,
            'site' => 'example.com',
            'monitor_id' => $monitor->id,
            'type' => InsightType::HEALTH_DROP,
            'severity' => InsightSeverity::CRITICAL,
            'payload' => ['previous_grade' => 'B', 'current_grade' => 'D'],
            'impact_score' => 20.0,
            'detected_at' => now()->subDay(),
        ];

        $unacked1 = Insight::factory()->create(array_merge($base, ['acknowledged_at' => null]));
        $unacked2 = Insight::factory()->create(array_merge($base, ['acknowledged_at' => null]));

        $acked = Insight::factory()->create(array_merge($base, [
            'detected_at' => now()->subDays(29),
            'acknowledged_at' => now()->subDays(28),
        ]));

        // Improvement: D→B.
        $this->seedHealthSnapshots('example.com', 64, 'D', 85, 'B');

        app(HealthDropDetector::class)->detectForMonitor($monitor);

        $this->assertDatabaseMissing('insights', ['id' => $unacked1->id]);
        $this->assertDatabaseMissing('insights', ['id' => $unacked2->id]);
        $this->assertDatabaseHas('insights', ['id' => $acked->id]);
        $this->assertSame(0, $this->countOpenHealthDrops('example.com'));
    }
}
