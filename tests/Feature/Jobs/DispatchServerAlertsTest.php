<?php

namespace Tests\Feature\Jobs;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Jobs\DispatchServerAlerts;
use App\Jobs\Notifications\SendInsightAlert;
use App\Models\Heartbeat;
use App\Models\Insight;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Models\Team;
use App\Services\SeoAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for DispatchServerAlerts job.
 *
 * This job iterates teams that own at least one active Server or Heartbeat and
 * dispatches urgent SERVER_HEALTH and HEARTBEAT_MISSED insights for each.
 *
 * Key behaviours under test:
 *   1. An unnotified SERVER_HEALTH WARNING/CRITICAL Insight gets dispatched
 *      (notified_at is stamped) and a SendInsightAlert job is pushed per channel.
 *   2. An Insight of a different type (e.g. STRIKING_DISTANCE) is NOT dispatched
 *      by this job — the onlyTypes filter must work correctly.
 *   3. A HEARTBEAT_MISSED Insight reaches email once, even for a team without a Server.
 *
 * Queue::fake() prevents real job execution; we assert on the dispatch.
 * Http::fake() prevents any accidental outbound HTTP.
 */
class DispatchServerAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create an active Server for the given team so that team is included in
     * the job's "teams with at least one active server" query.
     */
    private function addActiveServerToTeam(Team $team): Server
    {
        return Server::factory()->withoutMonitoring()->for($team)->create([
            'is_active' => true,
            'ingest_token_hash' => Server::hashIngestToken(Server::generateIngestToken()),
        ]);
    }

    /**
     * Create an unnotified, unacknowledged SERVER_HEALTH Insight for the team.
     */
    private function makeServerHealthInsight(Team $team, Server $server, string $severity = 'warning'): Insight
    {
        return Insight::create([
            'team_id' => $team->id,
            'site' => $server->name,
            'monitor_id' => null,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => $severity,
            'title' => "Server {$server->name}: disk at 93.0%",
            'payload' => [
                'metric' => 'disk',
                'server_id' => $server->id,
                'server_name' => $server->name,
                'sites' => [],
            ],
            'impact_score' => 8.0,
            'detected_at' => now(),
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);
    }

    /**
     * Create an unnotified, unacknowledged HEARTBEAT_MISSED Insight for the team.
     */
    private function makeHeartbeatMissedInsight(Team $team, Heartbeat $heartbeat): Insight
    {
        return Insight::factory()->create([
            'team_id' => $team->id,
            'site' => 'example.com',
            'monitor_id' => null,
            'type' => InsightType::HEARTBEAT_MISSED->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'Scheduled task "nightly backup" has not reported',
            'payload' => ['heartbeat_id' => $heartbeat->id],
            'impact_score' => 8.0,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 1 — unnotified SERVER_HEALTH WARNING → notified_at is set
    // ──────────────────────────────────────────────────────────────────────

    public function test_unnotified_server_health_insight_is_dispatched_and_stamped(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $server = $this->addActiveServerToTeam($team);

        // Active notification channel for this team.
        NotificationChannel::factory()->telegram()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        $insight = $this->makeServerHealthInsight($team, $server, InsightSeverity::WARNING->value);

        $this->assertNull($insight->notified_at);

        // Run the job directly (sync) via handle() to avoid queue serialisation.
        (new DispatchServerAlerts)->handle(app(SeoAlertService::class));

        $insight->refresh();
        $this->assertNotNull($insight->notified_at, 'notified_at should be set after dispatch.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 2 — CRITICAL SERVER_HEALTH also dispatched
    // ──────────────────────────────────────────────────────────────────────

    public function test_critical_server_health_insight_is_dispatched(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $server = $this->addActiveServerToTeam($team);

        NotificationChannel::factory()->telegram()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        $insight = $this->makeServerHealthInsight($team, $server, InsightSeverity::CRITICAL->value);

        (new DispatchServerAlerts)->handle(app(SeoAlertService::class));

        $insight->refresh();
        $this->assertNotNull($insight->notified_at, 'CRITICAL alert should be dispatched.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 3 — a non-immediate insight type is NOT dispatched
    // ──────────────────────────────────────────────────────────────────────

    public function test_non_immediate_insight_type_is_not_dispatched(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $this->addActiveServerToTeam($team);

        NotificationChannel::factory()->telegram()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        // Create a STRIKING_DISTANCE insight — must NOT be notified by this job.
        $strikingInsight = Insight::create([
            'team_id' => $team->id,
            'site' => 'example.com',
            'monitor_id' => null,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'Quick-win keyword at position 15',
            'payload' => ['page' => '/some-page', 'position' => 15],
            'impact_score' => 5.0,
            'detected_at' => now(),
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        (new DispatchServerAlerts)->handle(app(SeoAlertService::class));

        $strikingInsight->refresh();
        $this->assertNull(
            $strikingInsight->notified_at,
            'A STRIKING_DISTANCE insight must not be dispatched by DispatchServerAlerts.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 4 — team with no active server or heartbeat is not processed
    // ──────────────────────────────────────────────────────────────────────

    public function test_team_without_active_server_or_heartbeat_has_no_insights_dispatched(): void
    {
        Queue::fake();

        // Team with no server or heartbeat — it must be absent from the team_id list.
        $team = Team::factory()->create();

        NotificationChannel::factory()->telegram()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        // Insight on a team with no server or heartbeat.
        $insight = Insight::create([
            'team_id' => $team->id,
            'site' => 'example.com',
            'monitor_id' => null,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'title' => 'Orphan server health alert',
            'payload' => ['metric' => 'disk', 'server_id' => 999, 'server_name' => 'ghost', 'sites' => []],
            'impact_score' => 10.0,
            'detected_at' => now(),
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        (new DispatchServerAlerts)->handle(app(SeoAlertService::class));

        $insight->refresh();
        $this->assertNull(
            $insight->notified_at,
            'An insight for a team with no active server or heartbeat should not be dispatched.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 5 — already-notified insight is NOT re-dispatched
    // ──────────────────────────────────────────────────────────────────────

    public function test_already_notified_insight_is_not_re_dispatched(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $server = $this->addActiveServerToTeam($team);

        NotificationChannel::factory()->telegram()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        // Pre-stamp notified_at.
        $notifiedAt = now()->subHour();
        $insight = $this->makeServerHealthInsight($team, $server, InsightSeverity::WARNING->value);
        $insight->update(['notified_at' => $notifiedAt]);

        (new DispatchServerAlerts)->handle(app(SeoAlertService::class));

        $insight->refresh();

        // notified_at must remain unchanged — no second dispatch.
        $this->assertEquals(
            $notifiedAt->toDateTimeString(),
            $insight->notified_at->toDateTimeString(),
            'notified_at should not be updated for an already-notified insight.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 6 — HEARTBEAT_MISSED reaches email without an active server
    // ──────────────────────────────────────────────────────────────────────

    public function test_heartbeat_missed_for_team_without_server_is_dispatched_to_email(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $heartbeat = Heartbeat::factory()->for($team)->create(['is_active' => true]);
        $emailChannel = NotificationChannel::factory()->for($team)->create([
            'is_active' => true,
        ]);
        $insight = $this->makeHeartbeatMissedInsight($team, $heartbeat);

        $this->assertFalse(Server::withoutGlobalScopes()->where('team_id', $team->id)->exists());
        $this->assertContains(
            InsightType::HEARTBEAT_MISSED->value,
            config('monitoring.seo_alerts.email_alertable_types'),
        );

        (new DispatchServerAlerts)->handle(app(SeoAlertService::class));

        $this->assertNotNull($insight->fresh()->notified_at);
        Queue::assertPushed(
            SendInsightAlert::class,
            fn (SendInsightAlert $job): bool => $job->channel->is($emailChannel)
                && $job->insight->is($insight),
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 7 — HEARTBEAT_MISSED is not re-dispatched on the next run
    // ──────────────────────────────────────────────────────────────────────

    public function test_heartbeat_missed_is_not_dispatched_twice(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $heartbeat = Heartbeat::factory()->for($team)->create(['is_active' => true]);
        NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $insight = $this->makeHeartbeatMissedInsight($team, $heartbeat);

        $job = new DispatchServerAlerts;
        $job->handle(app(SeoAlertService::class));
        $firstNotifiedAt = $insight->fresh()->notified_at;

        $job->handle(app(SeoAlertService::class));

        $this->assertNotNull($firstNotifiedAt);
        $this->assertEquals($firstNotifiedAt, $insight->fresh()->notified_at);
        Queue::assertPushed(SendInsightAlert::class, 1);
    }
}
