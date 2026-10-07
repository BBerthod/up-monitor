<?php

namespace Tests\Feature\Services;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Enums\MonitorMethod;
use App\Events\IncidentCreated;
use App\Events\IncidentResolved;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Services\CheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies that CheckService dispatches the correct broadcast events
 * on state transitions (up→down, down→up).
 */
class CheckServiceBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private CheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CheckService::class);
    }

    public function test_incident_created_event_dispatched_when_monitor_goes_down(): void
    {
        Event::fake([IncidentCreated::class, IncidentResolved::class]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        Http::fake(['example.com' => Http::response('Error', 500)]);

        $this->service->check($monitor);

        Event::assertDispatched(IncidentCreated::class, function (IncidentCreated $event) use ($monitor) {
            return $event->incident->monitor_id === $monitor->id;
        });

        Event::assertNotDispatched(IncidentResolved::class);
    }

    public function test_incident_resolved_event_dispatched_when_monitor_recovers(): void
    {
        Event::fake([IncidentCreated::class, IncidentResolved::class]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        // Seed a DOWN check and an active incident.
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::DOWN,
            'response_time_ms' => 100,
            'status_code' => 500,
            'checked_at' => now()->subMinute(),
        ]);

        MonitorIncident::create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subMinute(),
        ]);

        Http::fake(['example.com' => Http::response('OK', 200)]);

        $this->service->check($monitor);

        Event::assertDispatched(IncidentResolved::class, function (IncidentResolved $event) use ($monitor) {
            return $event->incident->monitor_id === $monitor->id;
        });

        Event::assertNotDispatched(IncidentCreated::class);
    }

    public function test_no_incident_events_dispatched_when_monitor_stays_up(): void
    {
        Event::fake([IncidentCreated::class, IncidentResolved::class]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        // Previous check: UP.
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::UP,
            'response_time_ms' => 100,
            'status_code' => 200,
            'checked_at' => now()->subMinute(),
        ]);

        Http::fake(['example.com' => Http::response('OK', 200)]);

        $this->service->check($monitor);

        Event::assertNotDispatched(IncidentCreated::class);
        Event::assertNotDispatched(IncidentResolved::class);
    }

    public function test_incident_created_event_carries_correct_monitor_id(): void
    {
        Event::fake([IncidentCreated::class]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        Http::fake(['example.com' => Http::response('Error', 500)]);

        $this->service->check($monitor);

        Event::assertDispatched(IncidentCreated::class, function (IncidentCreated $event) use ($monitor) {
            return $event->incident->monitor_id === $monitor->id
                && $event->incident->cause === IncidentCause::STATUS_CODE;
        });
    }

    public function test_incident_resolved_event_carries_resolved_at_timestamp(): void
    {
        Event::fake([IncidentCreated::class, IncidentResolved::class]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::DOWN,
            'response_time_ms' => 100,
            'status_code' => 500,
            'checked_at' => now()->subMinute(),
        ]);

        MonitorIncident::create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subMinute(),
        ]);

        Http::fake(['example.com' => Http::response('OK', 200)]);

        $this->service->check($monitor);

        Event::assertDispatched(IncidentResolved::class, function (IncidentResolved $event) {
            return $event->incident->resolved_at !== null;
        });
    }
}
