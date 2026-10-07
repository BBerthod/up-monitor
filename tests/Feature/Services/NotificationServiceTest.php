<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Jobs\Notifications\SendEmailNotification;
use App\Jobs\Notifications\SendWebhookNotification;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\WarmRun;
use App\Models\WarmSite;
use App\Notifications\WarmSiteDisabledNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NotificationService::class);
    }

    // ──────────────────────────────────────────────────
    // notifyDown — per-channel cooldown
    // ──────────────────────────────────────────────────

    public function test_notify_down_dispatches_job_per_active_channel(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        $incident = MonitorIncident::factory()->for($monitor)->create();

        $this->service->notifyDown($monitor, $incident);

        Queue::assertPushed(SendEmailNotification::class, 1);
    }

    public function test_notify_down_respects_per_channel_cooldown(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();

        $channelA = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $channelB = NotificationChannel::factory()->webhook()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach([$channelA->id, $channelB->id]);

        $incident = MonitorIncident::factory()->for($monitor)->create();

        // First call → 2 jobs dispatched (one per channel).
        $this->service->notifyDown($monitor, $incident);
        Queue::assertPushedTimes(SendEmailNotification::class, 1);
        Queue::assertPushedTimes(SendWebhookNotification::class, 1);

        // Second call within the cooldown window → 0 new jobs.
        $this->service->notifyDown($monitor, $incident);
        Queue::assertPushedTimes(SendEmailNotification::class, 1);
        Queue::assertPushedTimes(SendWebhookNotification::class, 1);
    }

    public function test_notify_down_with_cache_miss_dispatches_exactly_once_per_channel(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();

        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        $incident = MonitorIncident::factory()->for($monitor)->create();

        // First call with empty cache → dispatches.
        $this->service->notifyDown($monitor, $incident);
        Queue::assertPushedTimes(SendEmailNotification::class, 1);

        // Manually clear the cooldown cache (simulates TTL expiry).
        Cache::flush();

        // After cache clear → dispatches again (fresh cooldown cycle).
        $this->service->notifyDown($monitor, $incident);
        Queue::assertPushedTimes(SendEmailNotification::class, 2);
    }

    public function test_notify_down_skips_inactive_channels(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();

        $activeChannel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $inactiveChannel = NotificationChannel::factory()->for($team)->create(['is_active' => false]);
        $monitor->notificationChannels()->attach([$activeChannel->id, $inactiveChannel->id]);

        $incident = MonitorIncident::factory()->for($monitor)->create();

        $this->service->notifyDown($monitor, $incident);

        Queue::assertPushedTimes(SendEmailNotification::class, 1);
    }

    // ──────────────────────────────────────────────────
    // notifyUp — independent cooldown
    // ──────────────────────────────────────────────────

    public function test_notify_up_has_independent_cooldown_from_notify_down(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();

        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        $incident = MonitorIncident::factory()->for($monitor)->create();

        // Trigger down notification → sets down cooldown key.
        $this->service->notifyDown($monitor, $incident);

        // Up notification uses a different key; should dispatch independently.
        $this->service->notifyUp($monitor, $incident);

        // Both should have fired once.
        Queue::assertPushedTimes(SendEmailNotification::class, 2);
    }

    public function test_notify_up_does_not_reset_down_cooldown(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();

        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        $incident = MonitorIncident::factory()->for($monitor)->create();

        // Down → sets down cooldown.
        $this->service->notifyDown($monitor, $incident);

        // Up → sets up cooldown, must NOT affect the down cooldown key.
        $this->service->notifyUp($monitor, $incident);

        // Second down call → still suppressed by the original down cooldown.
        $this->service->notifyDown($monitor, $incident);

        // Total: 1 down + 1 up = 2 jobs (third call suppressed).
        Queue::assertPushedTimes(SendEmailNotification::class, 2);
    }

    public function test_notify_up_is_suppressed_within_cooldown_window(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();

        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        // Incident must have down_notified_at set so the notifyUp guard does not
        // short-circuit before the cooldown logic is even reached.
        $incident = MonitorIncident::factory()->for($monitor)->create(['down_notified_at' => now()]);

        // First up notification → dispatches.
        $this->service->notifyUp($monitor, $incident);
        Queue::assertPushedTimes(SendEmailNotification::class, 1);

        // Second up call within cooldown → suppressed.
        $this->service->notifyUp($monitor, $incident);
        Queue::assertPushedTimes(SendEmailNotification::class, 1);
    }

    // ──────────────────────────────────────────────────
    // down_notified_at guard
    // ──────────────────────────────────────────────────

    public function test_notify_down_marks_incident_as_down_notified(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        $incident = MonitorIncident::factory()->for($monitor)->create(['down_notified_at' => null]);

        $this->assertNull($incident->down_notified_at);

        $this->service->notifyDown($monitor, $incident);

        $incident->refresh();
        $this->assertNotNull($incident->down_notified_at);
    }

    public function test_notify_up_is_skipped_when_down_was_never_notified(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        $incident = MonitorIncident::factory()->for($monitor)->create(['down_notified_at' => null]);

        $this->service->notifyUp($monitor, $incident);

        Queue::assertNothingPushed();
    }

    public function test_notify_up_dispatches_when_down_was_notified(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        $incident = MonitorIncident::factory()->for($monitor)->create(['down_notified_at' => now()]);

        $this->service->notifyUp($monitor, $incident);

        Queue::assertPushed(SendEmailNotification::class, 1);
    }

    // ──────────────────────────────────────────────────
    // LOT4.3 deploy window guard
    // ──────────────────────────────────────────────────

    public function test_notify_down_suppressed_during_active_deploy_window(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['deploying_until' => now()->addMinutes(5)]);
        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);
        $incident = MonitorIncident::factory()->for($monitor)->create();
        $this->service->notifyDown($monitor, $incident);
        Queue::assertNothingPushed();
    }

    public function test_notify_down_fires_after_deploy_window_expires(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['deploying_until' => now()->subMinute()]);
        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);
        $incident = MonitorIncident::factory()->for($monitor)->create();
        $this->service->notifyDown($monitor, $incident);
        Queue::assertPushed(SendEmailNotification::class, 1);
    }

    // ──────────────────────────────────────────────────
    // Per-channel isolation
    // ──────────────────────────────────────────────────

    public function test_each_channel_has_independent_down_cooldown_key(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();

        $channelA = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $channelB = NotificationChannel::factory()->webhook()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach([$channelA->id, $channelB->id]);

        $incident = MonitorIncident::factory()->for($monitor)->create();

        // First call: both channels notified.
        $this->service->notifyDown($monitor, $incident);

        // Manually expire only channel A's cooldown.
        Cache::forget("notify:{$monitor->id}:{$channelA->id}:down");

        // Second call: only channel A dispatches again.
        $this->service->notifyDown($monitor, $incident);

        Queue::assertPushedTimes(SendEmailNotification::class, 2);
        Queue::assertPushedTimes(SendWebhookNotification::class, 1);
    }

    public function test_mechanism_a_suppresses_when_heartbeat_insight_exists(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $server = Server::factory()->for($team)->create();
        $site = Site::factory()->for($team)->create(['server_id' => $server->id]);
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);
        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);
        $incident = MonitorIncident::factory()->for($monitor)->create();
        Insight::factory()->for($team)->create(['type' => InsightType::SERVER_HEALTH->value, 'severity' => InsightSeverity::CRITICAL->value, 'acknowledged_at' => null, 'payload' => ['server_id' => $server->id, 'metric' => 'heartbeat', 'server_name' => $server->name]]);
        $this->service->notifyDown($monitor, $incident);
        Queue::assertNothingPushed();
    }

    public function test_mechanism_b_first_down_fires_second_suppressed(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $server = Server::factory()->for($team)->create();
        $site = Site::factory()->for($team)->create(['server_id' => $server->id]);
        $mon1 = Monitor::factory()->for($team)->create(['site_id' => $site->id]);
        $mon2 = Monitor::factory()->for($team)->create(['site_id' => $site->id]);
        $ch1 = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $ch2 = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $mon1->notificationChannels()->attach($ch1);
        $mon2->notificationChannels()->attach($ch2);
        $inc1 = MonitorIncident::factory()->for($mon1)->create();
        $inc2 = MonitorIncident::factory()->for($mon2)->create();
        $this->service->notifyDown($mon1, $inc1);
        Queue::assertPushed(SendEmailNotification::class, 1);
        $this->service->notifyDown($mon2, $inc2);
        Queue::assertPushedTimes(SendEmailNotification::class, 1);
    }

    public function test_monitor_without_server_notifies_normally(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => null]);
        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);
        $incident = MonitorIncident::factory()->for($monitor)->create();
        $this->service->notifyDown($monitor, $incident);
        Queue::assertPushed(SendEmailNotification::class, 1);
    }

    // ──────────────────────────────────────────────────
    // notifyWarmingFailed — WARMING_DISABLED inbox mirror
    // ──────────────────────────────────────────────────

    /**
     * Seeds enough consecutive FAILED runs to reach the circuit-breaker threshold
     * (default 5) and returns the run that crosses it — the one passed to
     * notifyWarmingFailed() to trigger the auto-disable branch.
     */
    private function warmSiteAtCircuitBreakerThreshold(Team $team): array
    {
        $warmSite = WarmSite::factory()->for($team)->create(['is_active' => true]);

        // 4 prior failures + the one passed to notifyWarmingFailed() = 5 = threshold.
        WarmRun::factory()->for($warmSite)->failed()->count(4)->create();
        $warmRun = WarmRun::factory()->for($warmSite)->failed()->create();

        return [$warmSite, $warmRun];
    }

    public function test_notify_warming_failed_creates_insight_on_auto_disable(): void
    {
        Notification::fake();
        Cache::flush();

        $team = Team::factory()->create();
        [$warmSite, $warmRun] = $this->warmSiteAtCircuitBreakerThreshold($team);

        $this->service->notifyWarmingFailed($warmSite, $warmRun);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::WARMING_DISABLED->value)
            ->where('payload->warm_site_id', $warmSite->id)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame($team->id, $insight->team_id);
        $this->assertSame($warmSite->domain, $insight->site);
        $this->assertSame(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertTrue($insight->payload['already_notified_directly']);
        $this->assertSame(5, $insight->payload['consecutive_failures']);
        $this->assertNull($insight->acknowledged_at);
    }

    public function test_notify_warming_failed_does_not_duplicate_open_insight(): void
    {
        Notification::fake();
        Cache::flush();

        $team = Team::factory()->create();
        [$warmSite, $warmRun] = $this->warmSiteAtCircuitBreakerThreshold($team);

        $this->service->notifyWarmingFailed($warmSite, $warmRun);

        // Simulate a second circuit-breaker trip (e.g. re-disabled after a failed
        // reactivation) before the first insight was acknowledged.
        Cache::flush();
        $secondRun = WarmRun::factory()->for($warmSite)->failed()->create();
        $this->service->notifyWarmingFailed($warmSite, $secondRun);

        $this->assertSame(
            1,
            Insight::withoutGlobalScopes()
                ->where('type', InsightType::WARMING_DISABLED->value)
                ->where('payload->warm_site_id', $warmSite->id)
                ->count()
        );
    }

    public function test_notify_warming_failed_does_not_email_owner_a_second_channel(): void
    {
        Notification::fake();
        Cache::flush();

        $team = Team::factory()->create();
        $owner = User::factory()->for($team)->create();
        [$warmSite, $warmRun] = $this->warmSiteAtCircuitBreakerThreshold($team);

        $this->service->notifyWarmingFailed($warmSite, $warmRun);

        // The direct WarmSiteDisabledNotification email still fires as before —
        // the insight is an additional Vikunja-board projection, not a replacement.
        Notification::assertSentTo($owner, WarmSiteDisabledNotification::class);
    }

    public function test_resolve_warming_disabled_acknowledges_open_insight(): void
    {
        $team = Team::factory()->create();
        $warmSite = WarmSite::factory()->for($team)->create(['is_active' => false]);

        $insight = Insight::factory()->for($team)->create([
            'type' => InsightType::WARMING_DISABLED->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'payload' => ['warm_site_id' => $warmSite->id, 'already_notified_directly' => true],
            'acknowledged_at' => null,
        ]);

        $this->service->resolveWarmingDisabled($warmSite);

        $insight->refresh();
        $this->assertNotNull($insight->acknowledged_at);
    }

    public function test_resolve_warming_disabled_is_a_no_op_without_an_open_insight(): void
    {
        $team = Team::factory()->create();
        $warmSite = WarmSite::factory()->for($team)->create();

        // Must not throw when there is nothing to resolve.
        $this->service->resolveWarmingDisabled($warmSite);

        $this->assertDatabaseCount('insights', 0);
    }
}
