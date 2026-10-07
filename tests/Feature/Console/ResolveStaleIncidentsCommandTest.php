<?php

namespace Tests\Feature\Console;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Enums\InsightType;
use App\Models\FunctionalCheck;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the incidents:resolve-stale Artisan command.
 *
 * The command is a safety net: it resolves open incidents whose monitor
 * has been continuously UP for more than N hours (default 24).
 *
 * Key invariants:
 * - Dry-run (no --force) never touches the database.
 * - A monitor that is currently DOWN is not touched.
 * - Incidents younger than --hours are not touched.
 * - Functional incidents whose check is still enabled are skipped (owned by
 *   FunctionalCheckService), but functional incidents whose check was disabled
 *   or deleted are covered — nothing else can ever resolve them.
 */
class ResolveStaleIncidentsCommandTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function makeMonitorWithLatestCheck(Team $team, CheckStatus $status): Monitor
    {
        $monitor = Monitor::factory()->for($team)->create();

        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => $status,
            'response_time_ms' => 100,
            'status_code' => $status === CheckStatus::UP ? 200 : 500,
            'checked_at' => now()->subMinutes(5),
        ]);

        return $monitor;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 1 — dry-run leaves incidents open
    // ──────────────────────────────────────────────────────────────────────

    public function test_dry_run_does_not_resolve_incidents(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::UP);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHours(30),
        ]);

        // Default (no --force) = dry-run
        $this->artisan('incidents:resolve-stale', ['--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNull($incident->resolved_at, 'Dry-run must not resolve incidents');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 2 — --force resolves a stale zombie incident
    // ──────────────────────────────────────────────────────────────────────

    public function test_force_resolves_zombie_incident(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::UP);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHours(30),
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at, '--force must resolve the stale incident');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 3 — incident too recent is not touched even with --force
    // ──────────────────────────────────────────────────────────────────────

    public function test_recent_incident_is_not_resolved(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::UP);

        // Incident started only 2 hours ago — below the 24-hour window
        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subHours(2),
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNull($incident->resolved_at, 'Recent incident must not be resolved');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 4 — monitor that is DOWN is not resolved
    // ──────────────────────────────────────────────────────────────────────

    public function test_incident_for_down_monitor_is_not_resolved(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::DOWN);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subHours(30),
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNull($incident->resolved_at, 'Incident for a DOWN monitor must not be resolved');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 5 — functional incidents with an ENABLED check are skipped
    // (owned by FunctionalCheckService: a passing run resolves them)
    // ──────────────────────────────────────────────────────────────────────

    public function test_functional_incidents_with_enabled_check_are_not_resolved(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::UP);

        $functionalCheck = FunctionalCheck::factory()->create([
            'monitor_id' => $monitor->id,
        ]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'functional_check_id' => $functionalCheck->id,
            'cause' => IncidentCause::FUNCTIONAL,
            'started_at' => now()->subHours(30),
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNull($incident->resolved_at, 'Functional incidents with an enabled check must be skipped');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 5b — functional incident whose check was DISABLED is resolved
    // (a disabled check never runs again, so nothing else can resolve it)
    // ──────────────────────────────────────────────────────────────────────

    public function test_functional_incident_with_disabled_check_is_resolved(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::UP);

        $functionalCheck = FunctionalCheck::factory()->disabled()->create([
            'monitor_id' => $monitor->id,
        ]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'functional_check_id' => $functionalCheck->id,
            'cause' => IncidentCause::FUNCTIONAL,
            'started_at' => now()->subHours(30),
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at, 'A functional incident whose check is disabled must be resolved');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 5c — functional incident whose check was DELETED (FK nulled) is resolved
    // ──────────────────────────────────────────────────────────────────────

    public function test_functional_incident_with_deleted_check_is_resolved(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::UP);

        $functionalCheck = FunctionalCheck::factory()->create([
            'monitor_id' => $monitor->id,
        ]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'functional_check_id' => $functionalCheck->id,
            'cause' => IncidentCause::FUNCTIONAL,
            'started_at' => now()->subHours(30),
        ]);

        // Deleting the check nulls functional_check_id on the incident (nullOnDelete FK).
        $functionalCheck->delete();

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at, 'A functional incident whose check was deleted must be resolved');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 6 — no stale incidents returns success
    // ──────────────────────────────────────────────────────────────────────

    public function test_no_stale_incidents_exits_cleanly(): void
    {
        $this->artisan('incidents:resolve-stale', ['--force' => true])
            ->assertExitCode(0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 7 — resolving a zombie incident also acknowledges its mirrored
    // UPTIME_INCIDENT insight (regression test for the missing IncidentResolved event)
    // ──────────────────────────────────────────────────────────────────────

    public function test_force_resolves_zombie_incident_and_acknowledges_its_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::UP);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHours(30),
        ]);

        // Mirrors the CreateUptimeInsight projection: an open inbox insight
        // referencing this incident, as would exist for a real "Monitor DOWN" alert.
        $insight = Insight::create([
            'team_id' => $monitor->team_id,
            'site' => 'example.com',
            'site_id' => $monitor->site_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::UPTIME_INCIDENT->value,
            'severity' => 'critical',
            'title' => 'Monitor DOWN: example.com (timeout)',
            'payload' => ['incident_id' => $incident->id],
            'impact_score' => 100,
            'detected_at' => now()->subHours(30),
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $insight->refresh();

        $this->assertNotNull($incident->resolved_at, '--force must resolve the stale incident');
        $this->assertNotNull(
            $insight->acknowledged_at,
            'The mirrored UPTIME_INCIDENT insight must be acknowledged when the safety net closes the incident'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // B4 regression — hard age cap
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A functional incident whose check is still ENABLED but keeps failing
     * forever never gets resolved by FunctionalCheckService (nothing else
     * runs it), so without an absolute cap it stays open indefinitely. Past
     * --hard-hours it must be force-closed regardless of the check's status.
     */
    public function test_functional_incident_with_enabled_check_is_closed_past_hard_hours(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::DOWN);

        $functionalCheck = FunctionalCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'is_enabled' => true,
        ]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'functional_check_id' => $functionalCheck->id,
            'cause' => IncidentCause::FUNCTIONAL,
            'started_at' => now()->subHours(200), // older than the 168h default hard cap
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24, '--hard-hours' => 168])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at, 'An incident older than --hard-hours must be force-closed even with an enabled functional check');
    }

    /**
     * Same setup but younger than the hard cap: the soft net still skips it
     * (owned by an enabled functional check) and the hard cap does not apply yet.
     */
    public function test_functional_incident_with_enabled_check_stays_open_below_hard_hours(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::DOWN);

        $functionalCheck = FunctionalCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'is_enabled' => true,
        ]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'functional_check_id' => $functionalCheck->id,
            'cause' => IncidentCause::FUNCTIONAL,
            'started_at' => now()->subHours(100), // below the 168h default hard cap
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24, '--hard-hours' => 168])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNull($incident->resolved_at, 'An incident younger than --hard-hours with an enabled functional check must stay open');
    }

    /**
     * The hard cap emits IncidentResolved too — otherwise the mirrored insight
     * (if any) would stay open forever, same trap as the soft net. This also
     * exercises the hard cap for a NON-functional incident whose monitor is
     * still DOWN — proof that it bypasses the "monitor recovered" gate the
     * soft net requires, not just the "enabled functional check" exclusion.
     */
    public function test_hard_cap_closure_emits_incident_resolved_event(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::DOWN);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHours(200), // older than the 168h default hard cap
        ]);

        $insight = Insight::create([
            'team_id' => $monitor->team_id,
            'site' => 'example.com',
            'site_id' => $monitor->site_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::UPTIME_INCIDENT->value,
            'severity' => 'critical',
            'title' => 'Monitor DOWN forever',
            'payload' => ['incident_id' => $incident->id],
            'impact_score' => 100,
            'detected_at' => now()->subHours(200),
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hard-hours' => 168])
            ->assertExitCode(0);

        $incident->refresh();
        $insight->refresh();

        $this->assertNotNull($incident->resolved_at, 'The hard cap must resolve the incident even while the monitor is still DOWN');
        $this->assertNotNull(
            $insight->acknowledged_at,
            'IncidentResolved must be emitted on the hard-cap path too, exactly like the soft net'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // B4 regression — a monitor paused while DOWN must not stay open forever
    // ──────────────────────────────────────────────────────────────────────

    public function test_incident_for_inactive_monitor_with_down_last_check_is_resolved(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::DOWN);
        $monitor->update(['is_active' => false]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHours(30),
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNotNull(
            $incident->resolved_at,
            'A monitor paused while DOWN never writes another check — the safety net must still close its incident'
        );
    }

    public function test_incident_for_active_down_monitor_below_hard_hours_stays_open(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->makeMonitorWithLatestCheck($team, CheckStatus::DOWN);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHours(30),
        ]);

        $this->artisan('incidents:resolve-stale', ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $incident->refresh();
        $this->assertNull($incident->resolved_at, 'An active, still-DOWN monitor below the hard cap must not be resolved');
    }
}
