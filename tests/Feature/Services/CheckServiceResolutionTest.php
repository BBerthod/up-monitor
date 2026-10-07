<?php

namespace Tests\Feature\Services;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Enums\MonitorMethod;
use App\Models\FunctionalCheck;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Services\CheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests focused on the down→up incident resolution path in CheckService.
 *
 * Specifically: when a monitor transitions !wasUp → isUp, ALL non-functional open
 * incidents must be resolved — not just the most-recent one.
 *
 * These cover the "zombie incident" scenarios found in production:
 * - a TIMEOUT incident that was created while critical_threshold_ms was set,
 *   combined with a STATUS_CODE incident created when the monitor went fully DOWN.
 */
class CheckServiceResolutionTest extends TestCase
{
    use RefreshDatabase;

    private CheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CheckService::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 1 — single open incident resolved on recovery (baseline)
    // ──────────────────────────────────────────────────────────────────────

    public function test_down_to_up_resolves_single_open_incident(): void
    {
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

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subMinutes(10),
        ]);

        Http::fake(['example.com' => Http::response('OK', 200)]);

        $this->service->check($monitor);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 2 — TIMEOUT zombie + STATUS_CODE incident both resolved on recovery
    // ──────────────────────────────────────────────────────────────────────

    public function test_down_to_up_resolves_all_non_functional_open_incidents(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        // Previous check was DOWN
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::DOWN,
            'response_time_ms' => 100,
            'status_code' => 500,
            'checked_at' => now()->subMinute(),
        ]);

        // TIMEOUT incident — created earlier when the monitor was slow but UP;
        // it remained open when the monitor started failing (zombie scenario).
        $timeoutIncident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHours(80),
        ]);

        // STATUS_CODE incident — created more recently when the monitor went fully DOWN.
        $statusCodeIncident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subHours(2),
        ]);

        Http::fake(['example.com' => Http::response('OK', 200)]);

        $this->service->check($monitor);

        $timeoutIncident->refresh();
        $statusCodeIncident->refresh();

        $this->assertNotNull($timeoutIncident->resolved_at, 'TIMEOUT zombie incident must be resolved');
        $this->assertNotNull($statusCodeIncident->resolved_at, 'STATUS_CODE incident must be resolved');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 3 — FUNCTIONAL incident is NOT resolved by CheckService (its own service owns it)
    // ──────────────────────────────────────────────────────────────────────

    public function test_down_to_up_does_not_resolve_functional_incidents(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        // Previous check was DOWN
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::DOWN,
            'response_time_ms' => 100,
            'status_code' => 500,
            'checked_at' => now()->subMinute(),
        ]);

        // Functional check that owns this incident
        $functionalCheck = FunctionalCheck::factory()->create([
            'monitor_id' => $monitor->id,
        ]);

        // FUNCTIONAL incident — linked to a FunctionalCheck; must NOT be touched by CheckService.
        $functionalIncident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'functional_check_id' => $functionalCheck->id,
            'cause' => IncidentCause::FUNCTIONAL,
            'started_at' => now()->subHours(5),
        ]);

        // STATUS_CODE incident — should be resolved normally.
        $statusCodeIncident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subHours(2),
        ]);

        Http::fake(['example.com' => Http::response('OK', 200)]);

        $this->service->check($monitor);

        $functionalIncident->refresh();
        $statusCodeIncident->refresh();

        $this->assertNull($functionalIncident->resolved_at, 'Functional incident must not be resolved by CheckService');
        $this->assertNotNull($statusCodeIncident->resolved_at, 'STATUS_CODE incident must be resolved');
    }
}
