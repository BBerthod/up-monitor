<?php

namespace Tests\Feature\Services;

use App\Enums\FunctionalCheckStatus;
use App\Enums\FunctionalCheckType;
use App\Enums\IncidentCause;
use App\Events\IncidentCreated;
use App\Events\IncidentResolved;
use App\Models\FunctionalCheck;
use App\Models\FunctionalCheckResult;
use App\Models\MonitorIncident;
use App\Services\FunctionalCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FunctionalCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private FunctionalCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FunctionalCheckService::class);
    }

    public function test_run_creates_passed_result(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_present', 'value' => 'Hello']],
        ]);

        Http::fake(['example.com' => Http::response('<p>Hello World</p>', 200)]);

        $result = $this->service->run($check);

        $this->assertSame(FunctionalCheckStatus::PASSED, $result->status);
        $this->assertDatabaseHas('functional_check_results', [
            'functional_check_id' => $check->id,
            'status' => 'passed',
        ]);
    }

    public function test_run_creates_failed_result(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_present', 'value' => 'Hello']],
        ]);

        Http::fake(['example.com' => Http::response('<p>Nothing</p>', 200)]);

        $result = $this->service->run($check);

        $this->assertSame(FunctionalCheckStatus::FAILED, $result->status);
    }

    public function test_run_updates_last_checked_at(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [],
        ]);

        Http::fake(['example.com' => Http::response('ok', 200)]);

        $this->service->run($check);

        $this->assertNotNull($check->fresh()->last_checked_at);
    }

    public function test_run_updates_last_status(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_absent', 'value' => 'Fatal error']],
        ]);

        Http::fake(['example.com' => Http::response('Fatal error: oops', 500)]);

        $this->service->run($check);

        $this->assertSame(FunctionalCheckStatus::FAILED, $check->fresh()->last_status);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Incident auto-resolution — a passing run must resolve open incidents
    // ──────────────────────────────────────────────────────────────────────

    private function makePassingCheckWithOpenIncidents(int $incidents = 1): array
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_present', 'value' => 'Hello']],
        ]);

        $open = MonitorIncident::factory()->count($incidents)->create([
            'monitor_id' => $check->monitor_id,
            'functional_check_id' => $check->id,
            'cause' => IncidentCause::FUNCTIONAL,
            'started_at' => now()->subDays(30),
        ]);

        Http::fake(['example.com' => Http::response('<p>Hello World</p>', 200)]);

        return [$check, $open];
    }

    public function test_passed_run_resolves_open_incident(): void
    {
        [$check, $incidents] = $this->makePassingCheckWithOpenIncidents();

        $this->service->run($check);

        $this->assertNotNull(
            $incidents->first()->fresh()->resolved_at,
            'A passing functional run must resolve the open incident'
        );
    }

    public function test_passed_run_resolves_all_open_incidents(): void
    {
        [$check, $incidents] = $this->makePassingCheckWithOpenIncidents(3);

        $this->service->run($check);

        foreach ($incidents as $incident) {
            $this->assertNotNull(
                $incident->fresh()->resolved_at,
                "Incident {$incident->id} must be resolved — not only the most recent one"
            );
        }
    }

    public function test_passed_run_fires_incident_resolved_event(): void
    {
        [$check] = $this->makePassingCheckWithOpenIncidents();

        Event::fake([IncidentResolved::class]);

        $this->service->run($check);

        Event::assertDispatched(IncidentResolved::class);
    }

    public function test_passed_run_without_open_incident_fires_no_event(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_present', 'value' => 'Hello']],
        ]);

        Http::fake(['example.com' => Http::response('<p>Hello World</p>', 200)]);

        Event::fake([IncidentResolved::class]);

        $this->service->run($check);

        Event::assertNotDispatched(IncidentResolved::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Incident creation — 3 consecutive failures must fire IncidentCreated so
    // CreateUptimeInsight can project it into the Vikunja inbox (previously this
    // event was never fired for functional checks, leaving the failure visible
    // only as an email).
    // ──────────────────────────────────────────────────────────────────────

    public function test_third_consecutive_failure_creates_incident_and_fires_event(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_present', 'value' => 'Hello']],
        ]);

        // Seed two prior failures so the third run crosses the 3-failure threshold.
        // FunctionalCheckResult has no factory — created directly like the service does.
        for ($i = 0; $i < 2; $i++) {
            FunctionalCheckResult::create([
                'functional_check_id' => $check->id,
                'status' => FunctionalCheckStatus::FAILED,
                'checked_at' => now()->subMinutes(2),
            ]);
        }

        Http::fake(['example.com' => Http::response('<p>Nothing</p>', 200)]);

        Event::fake([IncidentCreated::class]);

        $this->service->run($check);

        Event::assertDispatched(IncidentCreated::class, function (IncidentCreated $event) use ($check) {
            return $event->incident->functional_check_id === $check->id;
        });

        $this->assertDatabaseHas('monitor_incidents', [
            'functional_check_id' => $check->id,
            'cause' => IncidentCause::FUNCTIONAL->value,
        ]);
    }
}
