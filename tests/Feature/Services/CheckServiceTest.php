<?php

namespace Tests\Feature\Services;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Enums\MonitorMethod;
use App\Jobs\Notifications\SendDiscordNotification;
use App\Jobs\Notifications\SendEmailNotification;
use App\Jobs\Notifications\SendPushNotification;
use App\Jobs\Notifications\SendSlackNotification;
use App\Jobs\Notifications\SendTelegramNotification;
use App\Jobs\Notifications\SendWebhookNotification;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Services\CheckService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private CheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CheckService::class);
    }

    public function test_successful_check_creates_up_record(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'keyword' => null,
        ]);

        Http::fake([
            'example.com' => Http::response('Hello World', 200),
        ]);

        $check = $this->service->check($monitor);

        $this->assertEquals(CheckStatus::UP, $check->status);
        $this->assertEquals(200, $check->status_code);
        $this->assertNotNull($check->response_time_ms);
        $this->assertDatabaseHas('monitor_checks', [
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::UP->value,
        ]);
    }

    public function test_failed_status_code_creates_down_record(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'keyword' => null,
        ]);

        Http::fake([
            'example.com' => Http::response('Error', 500),
        ]);

        $check = $this->service->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $check->status);
        $this->assertEquals(500, $check->status_code);

        $this->assertDatabaseHas('monitor_incidents', [
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE->value,
            'resolved_at' => null,
        ]);
    }

    public function test_keyword_missing_creates_down_record(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'keyword' => 'foobar',
        ]);

        Http::fake([
            'example.com' => Http::response('hello', 200),
        ]);

        $check = $this->service->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $check->status);

        $this->assertDatabaseHas('monitor_incidents', [
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::KEYWORD->value,
            'resolved_at' => null,
        ]);
    }

    public function test_keyword_present_creates_up_record(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'keyword' => 'hello',
        ]);

        Http::fake([
            'example.com' => Http::response('hello world', 200),
        ]);

        $check = $this->service->check($monitor);

        $this->assertEquals(CheckStatus::UP, $check->status);
        $this->assertEquals(200, $check->status_code);
    }

    public function test_connection_timeout_creates_down_record(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        Http::fake([
            'example.com' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $check = $this->service->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $check->status);
        $this->assertNull($check->status_code);
        $this->assertNotNull($check->error_message);
        $this->assertStringContainsString('timed out', strtolower($check->error_message));
    }

    public function test_state_change_up_to_down_creates_incident(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
        ]);

        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::UP,
            'response_time_ms' => 100,
            'status_code' => 200,
            'checked_at' => now()->subMinute(),
        ]);

        Http::fake([
            'example.com' => Http::response('Error', 500),
        ]);

        $check = $this->service->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $check->status);

        $this->assertDatabaseHas('monitor_incidents', [
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE->value,
            'resolved_at' => null,
        ]);
    }

    public function test_state_change_down_to_up_resolves_incident(): void
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

        $incident = MonitorIncident::create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subMinute(),
        ]);

        Http::fake([
            'example.com' => Http::response('OK', 200),
        ]);

        $check = $this->service->check($monitor);

        $this->assertEquals(CheckStatus::UP, $check->status);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_threshold_exceeded_creates_timeout_incident(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'critical_threshold_ms' => 500,
        ]);

        foreach ([600, 700, 800] as $i => $ms) {
            MonitorCheck::create([
                'monitor_id' => $monitor->id,
                'status' => CheckStatus::UP,
                'response_time_ms' => $ms,
                'status_code' => 200,
                'checked_at' => now()->subMinutes(4 - $i),
            ]);
        }

        Http::fake(['example.com' => Http::response('OK', 200, [])]);

        // The fake response will be fast, but checkThresholds reads from DB
        // so we seed a slow response for the new check too
        $monitor->checks()->latest('checked_at')->first()->update(['response_time_ms' => 900]);

        // Simulate: 3 consecutive slow DB checks trigger the incident
        // We do it by calling check() and checking the incident was created
        $this->assertDatabaseMissing('monitor_incidents', ['monitor_id' => $monitor->id]);

        // Manually create an additional slow check to trigger threshold
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::UP,
            'response_time_ms' => 950,
            'status_code' => 200,
            'checked_at' => now(),
        ]);

        // Invoke threshold check directly via reflection
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkThresholds');
        $method->setAccessible(true);

        $check = $monitor->checks()->latest('checked_at')->first();
        $method->invoke($this->service, $monitor, $check);

        $this->assertDatabaseHas('monitor_incidents', [
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT->value,
            'resolved_at' => null,
        ]);
    }

    public function test_threshold_recovery_resolves_active_incident(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'critical_threshold_ms' => 500,
        ]);

        // Active threshold incident (monitor was slow but UP)
        $incident = MonitorIncident::create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHour(),
        ]);

        // 3 recent checks now back under threshold
        foreach ([100, 120, 90] as $i => $ms) {
            MonitorCheck::create([
                'monitor_id' => $monitor->id,
                'status' => CheckStatus::UP,
                'response_time_ms' => $ms,
                'status_code' => 200,
                'checked_at' => now()->subMinutes(3 - $i),
            ]);
        }

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkThresholds');
        $method->setAccessible(true);

        $check = $monitor->checks()->latest('checked_at')->first();
        $method->invoke($this->service, $monitor, $check);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at);
    }

    /**
     * Dead-zone regression: latency oscillating around the threshold (2 checks
     * above, 1 below) must still resolve the incident as long as the MOST RECENT
     * check is back under threshold. Requiring all 3 to be below (the old logic)
     * left the incident open forever in this scenario.
     */
    public function test_threshold_recovery_resolves_incident_when_only_latest_check_is_below(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'critical_threshold_ms' => 500,
        ]);

        $incident = MonitorIncident::create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHour(),
        ]);

        // 2 checks above threshold, then the most recent one back under it.
        foreach ([600, 700, 450] as $i => $ms) {
            MonitorCheck::create([
                'monitor_id' => $monitor->id,
                'status' => CheckStatus::UP,
                'response_time_ms' => $ms,
                'status_code' => 200,
                'checked_at' => now()->subMinutes(3 - $i),
            ]);
        }

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkThresholds');
        $method->setAccessible(true);

        $check = $monitor->checks()->latest('checked_at')->first();
        $method->invoke($this->service, $monitor, $check);

        $incident->refresh();
        $this->assertNotNull(
            $incident->resolved_at,
            'The incident must resolve as soon as the latest check is back under threshold, even with a mixed history.'
        );
    }

    /**
     * Opening a TIMEOUT incident stays strict: 2 out of 3 recent checks above
     * threshold must NOT trigger a new incident (avoids flapping on a single
     * slow request). Only 3/3 above threshold opens one.
     */
    public function test_threshold_does_not_open_incident_when_only_two_of_three_exceed(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'critical_threshold_ms' => 500,
        ]);

        // 2 checks above threshold, 1 below — must NOT open an incident.
        foreach ([600, 450, 700] as $i => $ms) {
            MonitorCheck::create([
                'monitor_id' => $monitor->id,
                'status' => CheckStatus::UP,
                'response_time_ms' => $ms,
                'status_code' => 200,
                'checked_at' => now()->subMinutes(3 - $i),
            ]);
        }

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkThresholds');
        $method->setAccessible(true);

        $check = $monitor->checks()->latest('checked_at')->first();
        $method->invoke($this->service, $monitor, $check);

        $this->assertDatabaseMissing('monitor_incidents', [
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT->value,
        ]);
    }

    public function test_updates_monitor_last_checked_at(): void
    {
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'last_checked_at' => null,
        ]);

        Http::fake([
            'example.com' => Http::response('OK', 200),
        ]);

        $this->service->check($monitor);

        $monitor->refresh();
        $this->assertNotNull($monitor->last_checked_at);
    }

    public function test_notification_sent_only_after_threshold_failures(): void
    {
        $notificationService = $this->mock(NotificationService::class);

        // notifyDown must NOT be called on the 1st or 2nd failure.
        // It MUST be called exactly once on the 3rd (threshold = 3).
        $notificationService->expects('notifyDown')->once();
        $notificationService->expects('notifyUp')->never();

        // Re-resolve CheckService so it gets the mocked NotificationService.
        $this->service = app(CheckService::class);

        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'alert_after_failures' => 3,
        ]);

        Http::fake(['example.com' => Http::response('Internal Server Error', 500)]);

        // 1st failure: opens incident, consecutive count = 1 (below threshold 3).
        $this->service->check($monitor);

        // 2nd failure: consecutive count = 2, still below threshold.
        $this->service->check($monitor);

        // 3rd failure: consecutive count = 3 === threshold, notification fires.
        $this->service->check($monitor);
    }

    public function test_brief_flap_recovery_does_not_send_orphan_up_notification(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'alert_after_failures' => 3,
        ]);

        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        // Seed the "down" state directly so checked_at ordering is deterministic
        // (mirrors the pattern used in CheckServiceBroadcastTest).
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::DOWN,
            'response_time_ms' => 120,
            'status_code' => 500,
            'checked_at' => now()->subMinute(),
        ]);

        // Seed an open incident for this monitor. No down_notified_at — simulates a
        // flap where alert_after_failures (3) was never reached.
        MonitorIncident::create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now()->subMinute(),
        ]);

        // Step 2: recovery — no "up" notification should fire because
        // no "down" notification was ever sent for this incident (down_notified_at = null).
        Http::fake(['example.com' => Http::response('OK', 200)]);
        $this->service->check($monitor);

        // No notification jobs must have been dispatched (BroadcastEvent is
        // allowed — CheckService always dispatches MonitorChecked broadcasts).
        Queue::assertNotPushed(SendEmailNotification::class);
        Queue::assertNotPushed(SendWebhookNotification::class);
        Queue::assertNotPushed(SendSlackNotification::class);
        Queue::assertNotPushed(SendDiscordNotification::class);
        Queue::assertNotPushed(SendTelegramNotification::class);
        Queue::assertNotPushed(SendPushNotification::class);

        // The incident lifecycle must still work: the incident is resolved even though
        // no notification was sent.
        $incident = $monitor->incidents()->latest('started_at')->first();
        $this->assertNotNull($incident, 'An incident should have been created on the first failure');
        $this->assertNotNull($incident->resolved_at, 'The incident should be resolved after recovery');
    }
}
