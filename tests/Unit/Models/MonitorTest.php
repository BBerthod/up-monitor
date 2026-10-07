<?php

namespace Tests\Unit\Models;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_has_checks_relationship(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        MonitorCheck::factory()->for($monitor)->create();

        $this->assertCount(1, $monitor->checks);
        $this->assertInstanceOf(MonitorCheck::class, $monitor->checks->first());
    }

    public function test_monitor_has_incidents_relationship(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        MonitorIncident::factory()->for($monitor)->create();

        $this->assertCount(1, $monitor->incidents);
        $this->assertInstanceOf(MonitorIncident::class, $monitor->incidents->first());
    }

    public function test_monitor_has_notification_channels_relationship(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsToMany::class,
            $monitor->notificationChannels()
        );
    }

    public function test_active_scope_filters_active_monitors(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create(['is_active' => true]);
        Monitor::factory()->for($team)->create(['is_active' => false]);

        $monitors = Monitor::withoutGlobalScopes()->active()->get();

        $this->assertCount(1, $monitors);
        $this->assertTrue($monitors->first()->is_active);
    }

    public function test_inactive_scope_filters_inactive_monitors(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create(['is_active' => true]);
        Monitor::factory()->for($team)->create(['is_active' => false]);

        $monitors = Monitor::withoutGlobalScopes()->inactive()->get();

        $this->assertCount(1, $monitors);
        $this->assertFalse($monitors->first()->is_active);
    }

    // ──────────────────────────────────────────────────────────────────────
    // normalized_url (audit 2026-09-01, PSI quota — see UrlNormalizer)
    // ──────────────────────────────────────────────────────────────────────

    public function test_normalized_url_is_set_on_create(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://Example.com/']);

        $this->assertSame('https://example.com', $monitor->normalized_url);
    }

    public function test_normalized_url_is_refreshed_when_url_changes(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        $monitor->update(['url' => 'https://Other-Example.com/']);

        $this->assertSame('https://other-example.com', $monitor->fresh()->normalized_url);
    }

    public function test_unrelated_update_does_not_change_normalized_url(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        $monitor->update(['name' => 'Renamed']);

        $this->assertSame('https://example.com', $monitor->fresh()->normalized_url);
    }
}
