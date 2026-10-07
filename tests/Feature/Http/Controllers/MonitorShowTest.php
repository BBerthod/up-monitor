<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\IncidentCause;
use App\Enums\IncidentSeverity;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonitorShowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $team = Team::factory()->create();
        $this->user = User::factory()->create(['team_id' => $team->id]);
        $this->monitor = Monitor::factory()->for($team)->create();
    }

    public function test_paginated_incidents_carry_severity_and_notes(): void
    {
        MonitorIncident::factory()->resolved()->create([
            'monitor_id' => $this->monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'severity' => IncidentSeverity::MAJOR,
            'notes' => 'Upstream CDN outage.',
        ]);

        $this->actingAs($this->user)
            ->get(route('monitors.show', $this->monitor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Monitors/Show')
                ->where('incidents.data.0.severity', 'major')
                ->where('incidents.data.0.notes', 'Upstream CDN outage.')
            );
    }

    public function test_incident_pagination_keys_are_flat(): void
    {
        MonitorIncident::factory()->count(16)->resolved()->create(['monitor_id' => $this->monitor->id]);

        $this->actingAs($this->user)
            ->get(route('monitors.show', $this->monitor))
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidents.data', 15)
                ->where('incidents.current_page', 1)
                ->where('incidents.last_page', 2)
                ->where('incidents.total', 16)
                ->missing('incidents.meta')
            );
    }

    public function test_heatmap_covers_the_check_retention_window_only(): void
    {
        config(['monitoring.checks_retention_days' => 90]);
        MonitorCheck::factory()->create(['monitor_id' => $this->monitor->id, 'checked_at' => now()->subDays(10)]);
        MonitorCheck::factory()->create(['monitor_id' => $this->monitor->id, 'checked_at' => now()->subDays(200)]);

        $this->actingAs($this->user)
            ->get(route('monitors.show', $this->monitor))
            ->assertInertia(fn (Assert $page) => $page
                ->where('heatmapDays', 90)
                ->has('heatmapData', 1)
            );
    }

    public function test_last_checked_at_is_the_most_recent_check(): void
    {
        MonitorCheck::factory()->create(['monitor_id' => $this->monitor->id, 'checked_at' => now()->subHours(2)]);
        $latest = MonitorCheck::factory()->create(['monitor_id' => $this->monitor->id, 'checked_at' => now()->subMinutes(3)]);

        $this->actingAs($this->user)
            ->get(route('monitors.show', $this->monitor))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lastCheckedAt', $latest->checked_at->toIso8601String())
            );
    }

    public function test_timeline_prop_carries_the_90_day_daily_breakdown(): void
    {
        MonitorCheck::factory()->create(['monitor_id' => $this->monitor->id, 'status' => 'up', 'checked_at' => now()->subDay()]);

        $this->actingAs($this->user)
            ->get(route('monitors.show', $this->monitor))
            ->assertInertia(fn (Assert $page) => $page
                ->has('timeline.days', 90)
                ->has('timeline.uptime_90d')
                ->has('timeline.uptime_30d')
            );
    }

    public function test_active_incident_carries_a_human_readable_cause_label(): void
    {
        MonitorIncident::factory()->create([
            'monitor_id' => $this->monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'resolved_at' => null,
        ]);

        $this->actingAs($this->user)
            ->get(route('monitors.show', $this->monitor))
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidentStats.active_incident.cause_label', 'Response timeout')
            );
    }
}
