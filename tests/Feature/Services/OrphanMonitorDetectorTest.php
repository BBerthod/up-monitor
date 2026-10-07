<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\OrphanMonitorDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for OrphanMonitorDetector.
 *
 * Covers: creation when orphans exist, count update when the number changes,
 * auto-resolution when all monitors are assigned.
 */
class OrphanMonitorDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function createTeamWithUser(): Team
    {
        $team = Team::factory()->create();
        User::factory()->create(['team_id' => $team->id]);

        return $team;
    }

    private function detector(): OrphanMonitorDetector
    {
        return app(OrphanMonitorDetector::class);
    }

    // -------------------------------------------------------------------------
    // Creation
    // -------------------------------------------------------------------------

    public function test_creates_insight_when_orphan_monitors_exist(): void
    {
        $team = $this->createTeamWithUser();

        Monitor::factory()->for($team)->create([
            'url' => 'https://a.example.com',
            'is_active' => true,
            'site_id' => null,
        ]);

        $this->detector()->evaluateTeam($team);

        $this->assertDatabaseHas('insights', [
            'team_id' => $team->id,
            'type' => InsightType::HEALTH_DROP->value,
            'severity' => InsightSeverity::INFO->value,
        ]);

        $insight = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->first();

        $this->assertNull($insight->acknowledged_at);
        $this->assertSame(1, $insight->payload['monitor_count']);
    }

    // -------------------------------------------------------------------------
    // Idempotent — no duplicate creation
    // -------------------------------------------------------------------------

    public function test_does_not_create_duplicate_insight(): void
    {
        $team = $this->createTeamWithUser();

        Monitor::factory()->for($team)->create([
            'url' => 'https://a.example.com',
            'is_active' => true,
            'site_id' => null,
        ]);

        $this->detector()->evaluateTeam($team);
        $this->detector()->evaluateTeam($team);

        $count = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(1, $count);
    }

    // -------------------------------------------------------------------------
    // Count update when number of orphans changes
    // -------------------------------------------------------------------------

    public function test_updates_count_when_orphan_count_changes(): void
    {
        $team = $this->createTeamWithUser();

        Monitor::factory()->for($team)->create([
            'url' => 'https://a.example.com',
            'is_active' => true,
            'site_id' => null,
        ]);

        $this->detector()->evaluateTeam($team);

        // Add a second orphan
        Monitor::factory()->for($team)->create([
            'url' => 'https://b.example.com',
            'is_active' => true,
            'site_id' => null,
        ]);

        $this->detector()->evaluateTeam($team);

        $insight = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->first();

        $this->assertSame(2, $insight->payload['monitor_count']);
    }

    // -------------------------------------------------------------------------
    // Auto-resolution when all monitors are assigned
    // -------------------------------------------------------------------------

    public function test_auto_resolves_insight_when_all_monitors_assigned(): void
    {
        $team = $this->createTeamWithUser();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'a.example.com']);

        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://a.example.com',
            'is_active' => true,
            'site_id' => null,
        ]);

        $this->detector()->evaluateTeam($team);

        $this->assertDatabaseHas('insights', [
            'team_id' => $team->id,
            'type' => InsightType::HEALTH_DROP->value,
        ]);

        // Assign the monitor to a site — no more orphans
        $monitor->update(['site_id' => $site->id]);

        $this->detector()->evaluateTeam($team);

        $insight = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->first();

        $this->assertNotNull($insight->acknowledged_at, 'Insight must be auto-resolved');
    }

    // -------------------------------------------------------------------------
    // No insight created when no orphans
    // -------------------------------------------------------------------------

    public function test_no_insight_created_when_all_monitors_have_sites(): void
    {
        $team = $this->createTeamWithUser();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'a.example.com']);

        Monitor::factory()->for($team)->create([
            'url' => 'https://a.example.com',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        $result = $this->detector()->evaluateTeam($team);

        $this->assertSame(0, $result);
        $this->assertDatabaseMissing('insights', [
            'team_id' => $team->id,
            'type' => InsightType::HEALTH_DROP->value,
        ]);
    }

    // -------------------------------------------------------------------------
    // Inactive monitors are not counted as orphans
    // -------------------------------------------------------------------------

    public function test_inactive_monitors_are_not_counted_as_orphans(): void
    {
        $team = $this->createTeamWithUser();

        Monitor::factory()->for($team)->create([
            'url' => 'https://a.example.com',
            'is_active' => false,
            'site_id' => null,
        ]);

        $result = $this->detector()->evaluateTeam($team);

        $this->assertSame(0, $result);
    }
}
