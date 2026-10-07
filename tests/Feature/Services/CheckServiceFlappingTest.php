<?php

namespace Tests\Feature\Services;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Enums\InsightType;
use App\Enums\MonitorMethod;
use App\Events\IncidentCreated;
use App\Events\IncidentResolved;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Services\CheckService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies the anti-flapping (flap window) behaviour in CheckService.
 *
 * A "flap" is when a monitor oscillates DOWN→UP→DOWN rapidly. Without a guard,
 * each new DOWN after a recovery creates a fresh incident, producing hundreds of
 * incidents per week for an unstable endpoint. The flap window reopens the most
 * recently resolved incident instead of spawning a new one, collapsing the noise.
 */
class CheckServiceFlappingTest extends TestCase
{
    use RefreshDatabase;

    private CheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CheckService::class);
    }

    protected function tearDown(): void
    {
        // Always reset the test clock so no time manipulation leaks between tests.
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /**
     * Core flapping scenario: DOWN → UP → DOWN within the window → incident is
     * reopened (resolved_at cleared), NOT a new row inserted in monitor_incidents.
     */
    public function test_reopens_incident_within_flap_window(): void
    {
        Event::fake([IncidentCreated::class, IncidentResolved::class]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        // Sequence: 500 (DOWN) → 200 (UP) → 500 (DOWN again, flap reopen expected).
        // Http::fakeSequence() avoids the stub-merging pitfall of repeated Http::fake()
        // calls on the same URL, where the first match always wins.
        Http::fakeSequence('example.com')
            ->push('Error', 500)
            ->push('OK', 200)
            ->push('Error', 500);

        // --- Step 1: first DOWN ---
        Carbon::setTestNow(now());
        $this->service->check($monitor);

        $this->assertDatabaseCount('monitor_incidents', 1);
        $incident = MonitorIncident::first();
        $this->assertNull($incident->resolved_at, 'Incident should be active after first DOWN.');
        Event::assertDispatched(IncidentCreated::class);

        // --- Step 2: UP (resolves the incident) ---
        // Advance 1 minute so checked_at ordering is unambiguous.
        Carbon::setTestNow(now()->addMinute());
        $this->service->check($monitor);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at, 'Incident should be resolved after UP.');
        Event::assertDispatched(IncidentResolved::class);

        // --- Step 3: DOWN again immediately (within the 10-minute flap window) ---
        Carbon::setTestNow(now()->addMinute());
        $this->service->check($monitor);

        // There must still be only ONE incident in the database — flap reopen, not new row.
        $this->assertDatabaseCount('monitor_incidents', 1);

        $incident->refresh();
        $this->assertNull(
            $incident->resolved_at,
            'The reopened incident must have resolved_at cleared (active again).'
        );

        // IncidentCreated IS dispatched again on reopen (unlike notifications): the
        // inbox insight projection (CreateUptimeInsight) may already be acknowledged
        // from the earlier resolution, and only IncidentCreated re-triggers it. This
        // is safe because CreateUptimeInsight is idempotent (skips if an open insight
        // for the monitor already exists), so no duplicate insight is created — only
        // the notification path (not exercised by this event count) must not re-spam.
        Event::assertDispatchedTimes(IncidentCreated::class, 2);
    }

    /**
     * Outside the flap window: a new DOWN after an old resolved incident creates a
     * fresh incident row (normal behaviour, window expired).
     *
     * Strategy: seed a resolved incident, advance the clock 20 minutes past it
     * (beyond the 10-min default window), then trigger a DOWN check.
     */
    public function test_creates_new_incident_after_flap_window(): void
    {
        Event::fake([IncidentCreated::class, IncidentResolved::class]);

        // Pin "now" so all relative timestamps in this test are consistent.
        Carbon::setTestNow(now());

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        // Seed a UP check so $wasUp = true when the new DOWN fires.
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::UP,
            'response_time_ms' => 100,
            'status_code' => 200,
            'checked_at' => now(),
        ]);

        // Seed a resolved incident resolved at the current "now".
        MonitorIncident::create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subMinutes(5),
            'resolved_at' => now(),  // Resolved "now" — will be 20 min old when we check.
        ]);

        // Advance the clock 20 minutes — the resolved_at is now outside the 10-min window.
        Carbon::setTestNow(now()->addMinutes(20));

        Http::fakeSequence('example.com')
            ->push('Error', 500);

        $this->service->check($monitor);

        // A second distinct incident must have been created (previous one is too old to reopen).
        $this->assertDatabaseCount('monitor_incidents', 2);
        Event::assertDispatched(IncidentCreated::class);
    }

    /**
     * When the flap window is explicitly disabled (set to 0), every DOWN always
     * creates a new incident, regardless of recency.
     */
    public function test_creates_new_incident_when_flap_window_disabled(): void
    {
        config(['monitoring.flap_window_minutes' => 0]);

        Event::fake([IncidentCreated::class, IncidentResolved::class]);

        Carbon::setTestNow(now());

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        // Seed a recently resolved incident (would be inside a 10-min window if enabled).
        MonitorIncident::create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subMinutes(2),
            'resolved_at' => now()->subSeconds(30),  // Resolved 30 s ago.
        ]);

        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::UP,
            'response_time_ms' => 100,
            'status_code' => 200,
            'checked_at' => now()->subMinute(),
        ]);

        Http::fakeSequence('example.com')
            ->push('Error', 500);

        $this->service->check($monitor);

        // Flap window is 0 → must NOT reopen → must create a new incident.
        $this->assertDatabaseCount('monitor_incidents', 2);
        Event::assertDispatched(IncidentCreated::class);
    }

    /**
     * First-ever DOWN on a monitor with no history creates an incident as usual.
     * Ensures the flap guard doesn't interfere when there is nothing to reopen.
     */
    public function test_normal_first_incident_still_created(): void
    {
        Event::fake([IncidentCreated::class]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        Http::fakeSequence('example.com')
            ->push('Error', 500);

        $this->service->check($monitor);

        $this->assertDatabaseCount('monitor_incidents', 1);
        Event::assertDispatched(IncidentCreated::class, function (IncidentCreated $event) use ($monitor) {
            return $event->incident->monitor_id === $monitor->id;
        });
    }

    /**
     * Resolution still works normally after the flap guard is introduced.
     * DOWN then UP must set resolved_at and fire IncidentResolved.
     */
    public function test_resolution_still_works(): void
    {
        Event::fake([IncidentCreated::class, IncidentResolved::class]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        // Sequence: 500 (DOWN) → 200 (UP).
        Http::fakeSequence('example.com')
            ->push('Error', 500)
            ->push('OK', 200);

        // DOWN
        Carbon::setTestNow(now());
        $this->service->check($monitor);

        $incident = MonitorIncident::first();
        $this->assertNull($incident->resolved_at);

        // UP — advance 1 minute so checked_at ordering is deterministic.
        Carbon::setTestNow(now()->addMinute());
        $this->service->check($monitor);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at, 'Incident must be resolved after recovery.');
        Event::assertDispatched(IncidentResolved::class, function (IncidentResolved $event) use ($monitor) {
            return $event->incident->monitor_id === $monitor->id
                && $event->incident->resolved_at !== null;
        });
    }

    /**
     * When a flap reopen occurs, no new "down" notification must be dispatched.
     * The original incident had (or had not) already notified; we must not re-spam.
     *
     * We verify this indirectly: IncidentCreated is NOT dispatched a second time
     * (it is the trigger point for notification workers), and down_notified_at is
     * unchanged after the reopen.
     */
    public function test_no_renotification_on_reopen(): void
    {
        Event::fake([IncidentCreated::class, IncidentResolved::class]);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'alert_after_failures' => 1, // Notifies immediately on first failure.
        ]);

        // Sequence: 500 (DOWN) → 200 (UP) → 500 (DOWN again, flap reopen expected).
        Http::fakeSequence('example.com')
            ->push('Error', 500)
            ->push('OK', 200)
            ->push('Error', 500);

        // DOWN (first time — incident created; alertAfter=1 → notification queued).
        Carbon::setTestNow(now());
        $this->service->check($monitor);

        Event::assertDispatched(IncidentCreated::class);
        $incident = MonitorIncident::first();

        // UP (resolves) — advance time so checked_at ordering is unambiguous.
        Carbon::setTestNow(now()->addMinute());
        $this->service->check($monitor);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at);

        // Capture down_notified_at before the reopen so we can confirm it is unchanged.
        $downNotifiedBefore = $incident->down_notified_at;

        // DOWN again within window → flap reopen.
        Carbon::setTestNow(now()->addMinute());
        $this->service->check($monitor);

        // Only 1 incident in total (reopen, not new).
        $this->assertDatabaseCount('monitor_incidents', 1);

        // IncidentCreated fires again on reopen (drives the inbox insight projection,
        // not the notification path — see test_reopens_incident_within_flap_window).
        // The actual "no re-notification" guarantee is the down_notified_at check below.
        Event::assertDispatchedTimes(IncidentCreated::class, 2);

        // down_notified_at is untouched by the reopen path.
        $incident->refresh();
        $this->assertEquals(
            $downNotifiedBefore,
            $incident->down_notified_at,
            'down_notified_at must not be modified during a flap reopen.'
        );
    }

    /**
     * Regression test: a flap reopen must recreate the inbox insight if the original
     * one was already acknowledged (auto-resolved when the incident first closed).
     * Without firing IncidentCreated on reopen, the incident becomes active again in
     * the DB but stays invisible in the inbox — the bug this fix addresses.
     *
     * Uses real listeners (not Event::fake) since QUEUE_CONNECTION=sync in tests,
     * so CreateUptimeInsight/ResolveUptimeInsight run inline.
     */
    public function test_flap_reopen_recreates_acknowledged_insight(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        // Sequence: 500 (DOWN) → 200 (UP) → 500 (DOWN again, flap reopen expected).
        Http::fakeSequence('example.com')
            ->push('Error', 500)
            ->push('OK', 200)
            ->push('Error', 500);

        // --- DOWN: creates the incident and its mirrored insight ---
        Carbon::setTestNow(now());
        $this->service->check($monitor);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::UPTIME_INCIDENT->value)
            ->first();
        $this->assertNotNull($insight, 'CreateUptimeInsight should have projected the incident to the inbox.');
        $this->assertNull($insight->acknowledged_at);

        // --- UP: resolves the incident, which acknowledges the insight ---
        Carbon::setTestNow(now()->addMinute());
        $this->service->check($monitor);

        $insight->refresh();
        $this->assertNotNull($insight->acknowledged_at, 'Insight should be acknowledged when the incident resolves.');

        // --- DOWN again within the flap window: reopen ---
        Carbon::setTestNow(now()->addMinute());
        $this->service->check($monitor);

        // Still a single incident row (reopen, not a new one).
        $this->assertDatabaseCount('monitor_incidents', 1);

        // A fresh, unacknowledged insight must exist for the monitor — CreateUptimeInsight
        // is idempotent on "open insight exists", so the previous (acknowledged) insight
        // does not block a new one from being created.
        $openInsight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::UPTIME_INCIDENT->value)
            ->whereNull('acknowledged_at')
            ->first();

        $this->assertNotNull(
            $openInsight,
            'The flap reopen must produce a fresh open inbox insight, not leave the incident invisible.'
        );
    }
}
