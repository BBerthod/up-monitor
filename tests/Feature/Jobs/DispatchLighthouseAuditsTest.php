<?php

namespace Tests\Feature\Jobs;

use App\Jobs\DispatchLighthouseAudits;
use App\Jobs\RunLighthouseAudit;
use App\Models\Monitor;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchLighthouseAuditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_run_lighthouse_audit_for_active_http_monitors(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        Monitor::factory()->count(3)->for($team)->create([
            'type' => 'http',
            'is_active' => true,
        ]);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertPushed(RunLighthouseAudit::class, 3);
    }

    public function test_does_not_dispatch_for_inactive_http_monitors(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        Monitor::factory()->inactive()->for($team)->create(['type' => 'http']);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertNothingPushed();
    }

    public function test_does_not_dispatch_for_non_http_monitors(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        Monitor::factory()->ping()->for($team)->create(['is_active' => true]);
        Monitor::factory()->port()->for($team)->create(['is_active' => true]);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertNothingPushed();
    }

    public function test_dispatches_only_http_monitors_in_mixed_set(): void
    {
        Queue::fake();

        $team = Team::factory()->create();

        Monitor::factory()->count(2)->for($team)->create([
            'type' => 'http',
            'is_active' => true,
        ]);

        Monitor::factory()->ping()->for($team)->create(['is_active' => true]);
        Monitor::factory()->inactive()->for($team)->create(['type' => 'http']);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertPushed(RunLighthouseAudit::class, 2);
    }

    public function test_dispatches_correct_monitor_to_audit_job(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'type' => 'http',
            'is_active' => true,
        ]);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertPushed(RunLighthouseAudit::class, function (RunLighthouseAudit $job) use ($monitor) {
            return $job->monitor->id === $monitor->id;
        });
    }

    public function test_does_not_dispatch_for_monitor_with_lighthouse_disabled(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create([
            'type' => 'http',
            'is_active' => true,
            'url' => 'https://example.com/products/some-page',
            'lighthouse_enabled' => false,
        ]);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertNothingPushed();
    }

    public function test_does_not_dispatch_for_json_health_check_endpoint(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create([
            'type' => 'http',
            'is_active' => true,
            'url' => 'https://fr.examplestore.com/api/health/quick',
        ]);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertNothingPushed();
    }

    public function test_does_not_dispatch_for_affiliate_redirect_guard(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create([
            'type' => 'http',
            'is_active' => true,
            'url' => 'https://webcompare.fr/go/B0CGWWRBRQ/&keywords=test',
        ]);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertNothingPushed();
    }

    public function test_does_not_dispatch_for_monitor_expecting_a_redirect_status(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create([
            'type' => 'http',
            'is_active' => true,
            'url' => 'https://example.com/old-page',
            'expected_status_code' => 301,
        ]);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertNothingPushed();
    }

    public function test_dispatches_for_normal_page_monitor(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'type' => 'http',
            'is_active' => true,
            'url' => 'https://example.com/products/some-page',
            'expected_status_code' => 200,
        ]);

        (new DispatchLighthouseAudits)->handle();

        Queue::assertPushed(RunLighthouseAudit::class, function (RunLighthouseAudit $job) use ($monitor) {
            return $job->monitor->id === $monitor->id;
        });
    }
}
