<?php

namespace Tests\Feature\Services;

use App\Enums\IncidentCause;
use App\Enums\MonitorMethod;
use App\Jobs\Notifications\SendEmailNotification;
use App\Jobs\ReemitDownAlertsAfterDeployWindow;
use App\Models\FunctionalCheck;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Services\CheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A "down" alert that falls inside the post-deploy silence window used to be
 * dropped for good. These tests cover its re-emission when the window ends.
 */
class DeployWindowDownReemissionTest extends TestCase
{
    use RefreshDatabase;

    private CheckService $service;

    /** Status code the faked site answers with; flip it to simulate recovery. */
    private int $siteStatus = 502;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(CheckService::class);
        Http::fake(fn () => Http::response('', $this->siteStatus));
    }

    private function assertNoDownAlertSent(): void
    {
        Queue::assertNotPushed(SendEmailNotification::class);
    }

    private function deployingMonitor(int $alertAfter = 3): Monitor
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET,
            'expected_status_code' => 200,
            'alert_after_failures' => $alertAfter,
            'deploying_until' => now()->addMinutes(10),
        ]);
        $channel = NotificationChannel::factory()->for($team)->create(['is_active' => true]);
        $monitor->notificationChannels()->attach($channel);

        return $monitor;
    }

    private function failChecks(Monitor $monitor, int $times): void
    {
        $this->siteStatus = 502;

        for ($i = 0; $i < $times; $i++) {
            $this->service->check($monitor->fresh());
        }
    }

    private function runReemission(Monitor $monitor): void
    {
        (new ReemitDownAlertsAfterDeployWindow([$monitor->id]))->handle($this->service);
    }

    public function test_outage_crossing_threshold_inside_window_is_notified_once_when_window_ends(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor();

        $this->failChecks($monitor, 3);

        // The defect: threshold crossed inside the window, alert dropped, and
        // checks after the window never fire it again (threshold is only
        // evaluated at the exact crossing).
        $this->assertNoDownAlertSent();
        $this->travel(11)->minutes();
        $this->failChecks($monitor, 1);
        $this->assertNoDownAlertSent();

        $this->runReemission($monitor);

        Queue::assertPushed(SendEmailNotification::class, 1);
        $incident = $monitor->incidents()->firstOrFail();
        $this->assertNotNull($incident->down_notified_at);
    }

    public function test_reemission_is_not_duplicated(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor();
        $this->failChecks($monitor, 3);
        $this->travel(11)->minutes();

        $this->runReemission($monitor);
        $this->runReemission($monitor);

        Queue::assertPushed(SendEmailNotification::class, 1);
    }

    public function test_no_reemission_when_incident_was_already_notified(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor();
        $this->failChecks($monitor, 3);
        $monitor->incidents()->update(['down_notified_at' => now()]);
        $this->travel(11)->minutes();

        $this->runReemission($monitor);

        $this->assertNoDownAlertSent();
    }

    public function test_no_reemission_when_monitor_recovered_during_window(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor();
        $this->failChecks($monitor, 3);

        $this->siteStatus = 200;
        $this->service->check($monitor->fresh());

        $this->travel(11)->minutes();
        $this->runReemission($monitor);

        // Neither a late "down" nor an orphan "up".
        $this->assertNoDownAlertSent();
        $this->assertNotNull($monitor->incidents()->firstOrFail()->resolved_at);
    }

    public function test_no_reemission_when_window_was_extended_by_a_newer_deploy(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor();
        $this->failChecks($monitor, 3);
        $this->travel(11)->minutes();
        $monitor->update(['deploying_until' => now()->addMinutes(10)]);

        $this->runReemission($monitor);

        $this->assertNoDownAlertSent();
    }

    public function test_no_reemission_below_threshold_and_regular_logic_still_notifies_once_at_crossing(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor();
        $this->failChecks($monitor, 2);
        $this->travel(11)->minutes();

        $this->runReemission($monitor);
        $this->assertNoDownAlertSent();

        // Third failure, after the window: the existing threshold logic notifies.
        $this->failChecks($monitor, 1);
        Queue::assertPushed(SendEmailNotification::class, 1);

        // And the re-emission does not add a second one.
        $this->runReemission($monitor);
        Queue::assertPushed(SendEmailNotification::class, 1);
    }

    public function test_threshold_of_one_inside_window_is_reemitted(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor(alertAfter: 1);
        $this->failChecks($monitor, 1);
        $this->assertNoDownAlertSent();
        $this->travel(11)->minutes();

        $this->runReemission($monitor);

        Queue::assertPushed(SendEmailNotification::class, 1);
    }

    public function test_functional_incident_swallowed_in_window_is_reemitted_while_still_open(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor();
        $functionalCheck = FunctionalCheck::factory()->create(['monitor_id' => $monitor->id]);
        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'functional_check_id' => $functionalCheck->id,
            'cause' => IncidentCause::FUNCTIONAL,
        ]);
        $this->travel(11)->minutes();

        $this->runReemission($monitor);

        Queue::assertPushed(SendEmailNotification::class, 1);
        $this->assertNotNull($incident->fresh()->down_notified_at);
    }

    public function test_no_reemission_when_window_is_extended_while_waiting_for_the_lock(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor();
        $this->failChecks($monitor, 3);
        $this->travel(11)->minutes();

        $lock = \Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $lock->shouldReceive('block')->andReturnUsing(function () use ($monitor): bool {
            Monitor::withoutGlobalScopes()->whereKey($monitor->id)->update(['deploying_until' => now()->addMinutes(10)]);

            return true;
        });
        $lock->shouldReceive('release')->once();
        Cache::partialMock()->shouldReceive('lock')->andReturn($lock);

        $this->service->reemitDeferredDownAlert($monitor->fresh());

        $this->assertNoDownAlertSent();
    }

    public function test_no_reemission_for_a_paused_monitor(): void
    {
        Queue::fake();
        $monitor = $this->deployingMonitor();
        $this->failChecks($monitor, 3);
        $monitor->update(['is_active' => false]);
        $this->travel(11)->minutes();

        $this->runReemission($monitor);

        $this->assertNoDownAlertSent();
    }
}
