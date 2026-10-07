<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Jobs\Notifications\SendInsightAlert;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Services\SeoAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for SeoAlertService::dispatchForTeam().
 *
 * QUEUE_CONNECTION=sync (phpunit.xml) — Queue::fake() still intercepts dispatches
 * and prevents actual execution, which is what we want here.
 */
class SeoAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private SeoAlertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SeoAlertService::class);
    }

    // -------------------------------------------------------------------------
    // 1. Basic dispatch
    // -------------------------------------------------------------------------

    public function test_dispatches_warning_insight_to_active_channel(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['team_id' => $team->id]);

        NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        $insight = Insight::factory()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => null,
            'acknowledged_at' => null,
            'title' => 'Traffic drop on example.com',
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(1, $result);
        Queue::assertPushed(SendInsightAlert::class, 1);

        // The insight must be stamped with notified_at after dispatch.
        $this->assertNotNull(
            Insight::withoutGlobalScopes()->find($insight->id)->notified_at
        );
    }

    // -------------------------------------------------------------------------
    // 2. INFO / OPPORTUNITY severities are ignored
    // -------------------------------------------------------------------------

    public function test_does_not_dispatch_info_or_opportunity_insights(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        // INFO insight
        Insight::factory()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::INFO->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        // OPPORTUNITY insight
        Insight::factory()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::OPPORTUNITY->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(0, $result);
        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // 3. Idempotency: already-notified insights are skipped
    // -------------------------------------------------------------------------

    public function test_does_not_redispatch_already_notified(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => now()->subHour(),  // already notified
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(0, $result);
        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // 4. Acknowledged insights are skipped
    // -------------------------------------------------------------------------

    public function test_skips_acknowledged_insights(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => null,
            'acknowledged_at' => now()->subMinutes(5),
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(0, $result);
        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // 5. No active channel → returns 0, nothing dispatched
    // -------------------------------------------------------------------------

    public function test_returns_zero_when_no_active_channel(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        // Channel exists but is inactive.
        NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'is_active' => false,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(0, $result);
        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // 6. Fan-out: one insight × N channels = N jobs, return value is insight count
    // -------------------------------------------------------------------------

    public function test_fans_out_to_all_active_channels(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        NotificationChannel::factory()->webhook()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        // Return value = number of insights dispatched (not number of jobs).
        $this->assertSame(1, $result);

        // Two jobs — one per channel.
        Queue::assertPushed(SendInsightAlert::class, 2);
    }

    // -------------------------------------------------------------------------
    // 7. Priority monitor prefixes the title in titleOverride
    // -------------------------------------------------------------------------

    public function test_priority_monitor_prefixes_title(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        $monitor = Monitor::factory()->create([
            'team_id' => $team->id,
            'is_priority' => true,
        ]);

        NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => null,
            'acknowledged_at' => null,
            'title' => 'Ranking drop',
        ]);

        $this->service->dispatchForTeam($team);

        Queue::assertPushed(
            SendInsightAlert::class,
            fn (SendInsightAlert $job) => str_starts_with($job->titleOverride ?? '', '[Priority] ')
        );
    }

    // -------------------------------------------------------------------------
    // 8. Non-priority monitor → titleOverride is null
    // -------------------------------------------------------------------------

    public function test_non_priority_monitor_has_null_title_override(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        $monitor = Monitor::factory()->create([
            'team_id' => $team->id,
            'is_priority' => false,
        ]);

        NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $this->service->dispatchForTeam($team);

        Queue::assertPushed(
            SendInsightAlert::class,
            fn (SendInsightAlert $job) => $job->titleOverride === null
        );
    }

    // -------------------------------------------------------------------------
    // 9. Anti-spam cap limits WARNINGs
    // -------------------------------------------------------------------------

    public function test_anti_spam_cap_limits_warnings(): void
    {
        Queue::fake();

        config(['monitoring.seo_alerts.max_per_run' => 3]);

        $team = Team::factory()->create();

        NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        // Create 5 WARNING insights.
        Insight::factory()->count(5)->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        // Only 3 should be dispatched (cap = 3).
        $this->assertSame(3, $result);
        Queue::assertPushed(SendInsightAlert::class, 3);
    }

    // -------------------------------------------------------------------------
    // 10. CRITICAL insights bypass the cap entirely
    // -------------------------------------------------------------------------

    public function test_criticals_bypass_cap(): void
    {
        Queue::fake();

        config(['monitoring.seo_alerts.max_per_run' => 2]);

        $team = Team::factory()->create();

        NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        // Create 4 CRITICAL insights.
        Insight::factory()->count(4)->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::CRITICAL->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        // All 4 must be dispatched — CRITICAL bypasses the cap.
        $this->assertSame(4, $result);
        Queue::assertPushed(SendInsightAlert::class, 4);
    }

    // -------------------------------------------------------------------------
    // 11. Business weight tiebreaker: at equal severity+impact, REVENUE_AT_RISK
    //     is dispatched before CTR_CHANGE
    // -------------------------------------------------------------------------

    public function test_revenue_at_risk_dispatched_before_ctr_change_at_equal_severity_and_impact(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create(['team_id' => $team->id, 'is_priority' => false]);

        NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        // Both WARNING, same impact_score=50, no priority monitor.
        // After businessWeight sort: REVENUE_AT_RISK (weight=10) must come first.
        $ctr = Insight::factory()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'type' => \App\Enums\InsightType::CTR_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 50,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $revenue = Insight::factory()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'type' => \App\Enums\InsightType::REVENUE_AT_RISK->value,
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 50,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        // Cap to 1 so only the top-ranked insight is dispatched.
        config(['monitoring.seo_alerts.max_per_run' => 1]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(1, $result);

        // The dispatched job must be for the REVENUE_AT_RISK insight.
        Queue::assertPushed(
            SendInsightAlert::class,
            fn (SendInsightAlert $job) => $job->insight->id === $revenue->id
        );

        // The CTR_CHANGE insight must still have notified_at=null (not dispatched).
        $this->assertNull(
            Insight::withoutGlobalScopes()->find($ctr->id)->notified_at
        );
    }

    // -------------------------------------------------------------------------
    // 12. EMAIL scoping — a non-allowlisted type (e.g. revenue/SEO) is not
    //     emailed, but is still marked notified_at so it is not retried forever.
    // -------------------------------------------------------------------------

    public function test_non_allowlisted_type_does_not_email_but_is_marked_notified(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'is_active' => true, // default factory type = EMAIL
        ]);

        $insight = Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::REVENUE_AT_RISK->value,
            'severity' => InsightSeverity::CRITICAL->value, // bypasses the anti-spam cap
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        // The insight still counts as "dispatched" for the return value/notified_at
        // bookkeeping, even though no EMAIL job was actually queued.
        $this->assertSame(1, $result);
        Queue::assertNothingPushed();

        $this->assertNotNull(
            Insight::withoutGlobalScopes()->find($insight->id)->notified_at
        );
    }

    // -------------------------------------------------------------------------
    // 13. EMAIL scoping — SERVER_HEALTH and UPTIME_INCIDENT stay allowlisted
    // -------------------------------------------------------------------------

    public function test_allowlisted_types_still_dispatch_to_email(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'is_active' => true, // default factory type = EMAIL
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::SERVER_HEALTH->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::UPTIME_INCIDENT->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(2, $result);
        Queue::assertPushed(SendInsightAlert::class, 2);
    }

    // -------------------------------------------------------------------------
    // 14. EMAIL scoping — a functional-check-sourced UPTIME_INCIDENT is skipped
    //     even though its type is allowlisted, to avoid double-emailing (it
    //     already fired an immediate MonitorAlertMail when the incident opened).
    // -------------------------------------------------------------------------

    public function test_functional_check_uptime_incident_is_not_double_emailed(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'is_active' => true, // default factory type = EMAIL
        ]);

        $insight = Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::UPTIME_INCIDENT->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'payload' => ['already_notified_directly' => true],
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(1, $result);
        Queue::assertNothingPushed();

        $this->assertNotNull(
            Insight::withoutGlobalScopes()->find($insight->id)->notified_at
        );
    }

    // -------------------------------------------------------------------------
    // 15. EMAIL scoping — non-email channels are unaffected by the allowlist
    // -------------------------------------------------------------------------

    public function test_non_email_channel_ignores_the_allowlist(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::REVENUE_AT_RISK->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(1, $result);
        Queue::assertPushed(SendInsightAlert::class, 1);
    }

    // -------------------------------------------------------------------------
    // 16. EMAIL scoping — WARMING_DISABLED and DEPLOY_ROLLBACK_FAILED insights
    //     already went out through a direct notification (WarmSiteDisabledNotification /
    //     DeployRollbackFailedNotification), so the email channel must not double-send.
    // -------------------------------------------------------------------------

    public function test_warming_disabled_insight_is_not_double_emailed(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::WARMING_DISABLED->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'payload' => ['already_notified_directly' => true],
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(1, $result);
        Queue::assertNothingPushed();
    }

    public function test_deploy_rollback_failed_insight_is_not_double_emailed(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::DEPLOY_ROLLBACK_FAILED->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'payload' => ['already_notified_directly' => true],
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $result = $this->service->dispatchForTeam($team);

        $this->assertSame(1, $result);
        Queue::assertNothingPushed();
    }
}
