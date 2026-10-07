<?php

namespace Tests\Feature\Jobs;

use App\Enums\MonitorMethod;
use App\Events\IncidentCreated;
use App\Events\MonitorChecked;
use App\Jobs\RunCheck;
use App\Models\Monitor;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_check_dispatches_monitor_checked_event(): void
    {
        Event::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        Http::fake(['example.com' => Http::response('OK', 200)]);

        (new RunCheck($monitor))->handle(app(\App\Services\CheckService::class));

        Event::assertDispatched(MonitorChecked::class, function (MonitorChecked $event) use ($monitor) {
            return $event->monitor->id === $monitor->id;
        });
    }

    public function test_run_check_creates_database_record(): void
    {
        Event::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        Http::fake(['example.com' => Http::response('OK', 200)]);

        (new RunCheck($monitor))->handle(app(\App\Services\CheckService::class));

        $this->assertDatabaseHas('monitor_checks', ['monitor_id' => $monitor->id]);
    }

    public function test_run_check_dispatches_incident_created_event_when_monitor_goes_down(): void
    {
        Event::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        Http::fake(['example.com' => Http::response('Server Error', 500)]);

        (new RunCheck($monitor))->handle(app(\App\Services\CheckService::class));

        Event::assertDispatched(IncidentCreated::class, function (IncidentCreated $event) use ($monitor) {
            return $event->incident->monitor_id === $monitor->id;
        });
    }

    public function test_run_check_updates_monitor_last_checked_at(): void
    {
        Event::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'last_checked_at' => null,
        ]);

        Http::fake(['example.com' => Http::response('OK', 200)]);

        (new RunCheck($monitor))->handle(app(\App\Services\CheckService::class));

        $monitor->refresh();
        $this->assertNotNull($monitor->last_checked_at);
    }
}
