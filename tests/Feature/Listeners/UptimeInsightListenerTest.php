<?php

namespace Tests\Feature\Listeners;

use App\Enums\IncidentCause;
use App\Enums\IncidentSeverity;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\MonitorType;
use App\Events\IncidentCreated;
use App\Events\IncidentResolved;
use App\Listeners\CreateUptimeInsight;
use App\Listeners\ResolveUptimeInsight;
use App\Models\FunctionalCheck;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for CreateUptimeInsight and ResolveUptimeInsight listeners.
 *
 * Listeners are exercised directly (new Listener()->handle(new Event())) to
 * avoid the queue and keep tests synchronous.  Broadcasting is faked via
 * Event::fake() so Reverb channel calls do not throw in the test environment.
 */
class UptimeInsightListenerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();   // Suppress broadcasting side-effects (InsightChanged, etc.)
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeMonitor(?Team $team = null): Monitor
    {
        $team ??= Team::factory()->create();

        return Monitor::factory()->create([
            'team_id' => $team->id,
            'type' => MonitorType::HTTP->value,
            'url' => 'https://example.com',
        ]);
    }

    private function makeIncident(Monitor $monitor, array $attrs = []): MonitorIncident
    {
        return MonitorIncident::factory()->create(array_merge([
            'monitor_id' => $monitor->id,
            'started_at' => now(),
            'cause' => IncidentCause::TIMEOUT,
            'severity' => IncidentSeverity::CRITICAL,
        ], $attrs));
    }

    private function dispatch(MonitorIncident $incident): void
    {
        (new CreateUptimeInsight)->handle(new IncidentCreated($incident));
    }

    private function resolve(MonitorIncident $incident): void
    {
        (new ResolveUptimeInsight)->handle(new IncidentResolved($incident));
    }

    // ── CRITICAL incident → CRITICAL insight ─────────────────────────────

    public function test_creates_critical_insight_for_critical_incident(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitor($team);
        $incident = $this->makeIncident($monitor, ['severity' => IncidentSeverity::CRITICAL]);

        $this->dispatch($incident);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::UPTIME_INCIDENT->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertEquals(InsightType::UPTIME_INCIDENT, $insight->type);
        $this->assertEquals($team->id, $insight->team_id);
        $this->assertStringContainsString('example.com', $insight->title);
        $this->assertStringContainsString('timeout', strtolower($insight->title));
        $this->assertEquals($incident->id, $insight->payload['incident_id']);
    }

    // ── MINOR incident → WARNING insight (severity mapping) ──────────────

    public function test_creates_warning_insight_for_minor_incident(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitor($team);
        $incident = $this->makeIncident($monitor, ['severity' => IncidentSeverity::MINOR]);

        $this->dispatch($incident);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::UPTIME_INCIDENT->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    // ── functional_check_id present → insight still created, flagged ─────

    public function test_creates_insight_for_functional_check_incident(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitor($team);

        $functionalCheck = FunctionalCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'name' => 'Sitemap freshness',
        ]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'started_at' => now(),
            'cause' => IncidentCause::FUNCTIONAL,
            'severity' => IncidentSeverity::CRITICAL,
            'functional_check_id' => $functionalCheck->id,
        ]);

        $this->dispatch($incident);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::UPTIME_INCIDENT->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertStringContainsString('Sitemap freshness', $insight->title);
        $this->assertTrue($insight->payload['already_notified_directly']);
        $this->assertSame($functionalCheck->id, $insight->payload['functional_check_id']);
    }

    // ── Idempotence — second dispatch for same monitor → 1 insight ───────

    public function test_two_dispatches_for_same_monitor_produce_one_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitor($team);

        $incident1 = $this->makeIncident($monitor);
        $incident2 = $this->makeIncident($monitor);

        $this->dispatch($incident1);
        $this->dispatch($incident2);

        $count = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::UPTIME_INCIDENT->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(1, $count, 'Expected exactly 1 unacknowledged UPTIME_INCIDENT insight after 2 dispatches.');
    }

    // ── ResolveUptimeInsight — acknowledges the open insight ─────────────

    public function test_resolve_listener_acknowledges_uptime_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitor($team);
        $incident = $this->makeIncident($monitor);

        // Create the insight first.
        $this->dispatch($incident);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::UPTIME_INCIDENT->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertNull($insight->acknowledged_at, 'Insight should not be acknowledged yet.');

        // Resolve the incident.
        $incident->resolved_at = now();
        $incident->save();

        $this->resolve($incident);

        $insight->refresh();
        $this->assertNotNull($insight->acknowledged_at, 'Insight should be acknowledged after resolution.');
    }

    // ── ResolveUptimeInsight — functional_check_id → still acknowledges ──

    public function test_resolve_listener_acknowledges_functional_check_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitor($team);

        $functionalCheck = FunctionalCheck::factory()->create(['monitor_id' => $monitor->id]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'started_at' => now(),
            'cause' => IncidentCause::FUNCTIONAL,
            'severity' => IncidentSeverity::CRITICAL,
            'functional_check_id' => $functionalCheck->id,
        ]);

        // Create the insight first, exactly as FunctionalCheckService would trigger it.
        $this->dispatch($incident);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::UPTIME_INCIDENT->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertNull($insight->acknowledged_at);

        $incident->resolved_at = now();
        $incident->save();

        $this->resolve($incident);

        $insight->refresh();
        $this->assertNotNull($insight->acknowledged_at, 'Functional-check insight must also be auto-acknowledged on resolution.');
    }
}
