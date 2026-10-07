<?php

namespace Tests\Unit\Models;

use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Team;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_scope_filters_unresolved_incidents(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        MonitorIncident::factory()->for($monitor)->create(['resolved_at' => now()]);
        MonitorIncident::factory()->for($monitor)->create(['resolved_at' => null]);

        $incidents = MonitorIncident::active()->get();

        $this->assertCount(1, $incidents);
        $this->assertNull($incidents->first()->resolved_at);
    }

    public function test_resolved_scope_filters_resolved_incidents(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        MonitorIncident::factory()->for($monitor)->create(['resolved_at' => now()]);
        MonitorIncident::factory()->for($monitor)->create(['resolved_at' => null]);

        $incidents = MonitorIncident::resolved()->get();

        $this->assertCount(1, $incidents);
        $this->assertNotNull($incidents->first()->resolved_at);
    }

    public function test_resolve_sets_resolved_at(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $incident = MonitorIncident::factory()->for($monitor)->create(['resolved_at' => null]);

        $this->assertNull($incident->resolved_at);

        $incident->resolve();

        $this->assertNotNull($incident->fresh()->resolved_at);
    }

    public function test_duration_returns_interval(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $incident = MonitorIncident::factory()->for($monitor)->create([
            'started_at' => now()->subMinutes(30),
            'resolved_at' => now(),
        ]);

        $duration = $incident->duration();

        $this->assertInstanceOf(CarbonInterval::class, $duration);
        $this->assertEquals(30, (int) $duration->totalMinutes);
    }
}
