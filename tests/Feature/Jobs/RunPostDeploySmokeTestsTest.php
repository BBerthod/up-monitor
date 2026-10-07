<?php

namespace Tests\Feature\Jobs;

use App\Enums\SmokeTestStatus;
use App\Jobs\RunPostDeploySmokeTests;
use App\Jobs\TriggerDokployRollback;
use App\Models\DeployEvent;
use App\Models\SmokeTestConfig;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunPostDeploySmokeTestsTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeployEvent(string $applicationId = 'fr-example-shop'): DeployEvent
    {
        return DeployEvent::create([
            'application_id' => $applicationId,
            'application_name' => 'example-shop FR',
            'commit_sha' => 'abc1234',
            'deployed_at' => now(),
            'smoke_test_status' => 'pending',
        ]);
    }

    private function makeSmokeTestConfig(string $applicationId, array $tests): SmokeTestConfig
    {
        return SmokeTestConfig::create([
            'application_id' => $applicationId,
            'application_name' => 'example-shop FR',
            'tests' => $tests,
            'is_active' => true,
        ]);
    }

    public function test_all_tests_pass_marks_event_as_passed(): void
    {
        Queue::fake();

        $deployEvent = $this->makeDeployEvent();
        $this->makeSmokeTestConfig('fr-example-shop', [
            ['url' => 'https://example.com/', 'expected_status' => 200, 'timeout_ms' => 3000],
        ]);

        Http::fake([
            'example.com/*' => Http::response('OK', 200),
        ]);

        (new RunPostDeploySmokeTests($deployEvent))->handle();

        $deployEvent->refresh();
        $this->assertEquals(SmokeTestStatus::PASSED, $deployEvent->smoke_test_status);
        $this->assertNotNull($deployEvent->smoke_test_results);
        $this->assertTrue($deployEvent->smoke_test_results[0]['passed']);

        Queue::assertNotPushed(TriggerDokployRollback::class);
    }

    public function test_keyword_mismatch_marks_test_as_failed(): void
    {
        Queue::fake();

        $deployEvent = $this->makeDeployEvent();
        $this->makeSmokeTestConfig('fr-example-shop', [
            ['url' => 'https://example.com/', 'expected_status' => 200, 'expected_keyword' => 'MissingKeyword', 'timeout_ms' => 3000],
        ]);

        Http::fake([
            'example.com/*' => Http::response('Page content without keyword', 200),
        ]);

        (new RunPostDeploySmokeTests($deployEvent))->handle();

        $deployEvent->refresh();
        $this->assertEquals(SmokeTestStatus::FAILED, $deployEvent->smoke_test_status);
        $this->assertFalse($deployEvent->smoke_test_results[0]['passed']);

        Queue::assertPushed(TriggerDokployRollback::class);
    }

    public function test_wrong_status_code_marks_test_as_failed(): void
    {
        Queue::fake();

        $deployEvent = $this->makeDeployEvent();
        $this->makeSmokeTestConfig('fr-example-shop', [
            ['url' => 'https://example.com/', 'expected_status' => 200, 'timeout_ms' => 3000],
        ]);

        Http::fake([
            'example.com/*' => Http::response('Internal Server Error', 500),
        ]);

        (new RunPostDeploySmokeTests($deployEvent))->handle();

        $deployEvent->refresh();
        $this->assertEquals(SmokeTestStatus::FAILED, $deployEvent->smoke_test_status);

        Queue::assertPushed(TriggerDokployRollback::class, function (TriggerDokployRollback $job) use ($deployEvent): bool {
            return $job->deployEvent->id === $deployEvent->id;
        });
    }

    public function test_no_config_found_marks_event_as_passed(): void
    {
        Queue::fake();

        $deployEvent = $this->makeDeployEvent('unknown-app');

        (new RunPostDeploySmokeTests($deployEvent))->handle();

        $deployEvent->refresh();
        $this->assertEquals(SmokeTestStatus::PASSED, $deployEvent->smoke_test_status);

        Queue::assertNotPushed(TriggerDokployRollback::class);
    }

    public function test_inactive_config_is_ignored_and_marks_event_as_passed(): void
    {
        Queue::fake();

        $deployEvent = $this->makeDeployEvent();
        SmokeTestConfig::create([
            'application_id' => 'fr-example-shop',
            'application_name' => 'example-shop FR',
            'tests' => [
                ['url' => 'https://example.com/', 'expected_status' => 200],
            ],
            'is_active' => false,
        ]);

        (new RunPostDeploySmokeTests($deployEvent))->handle();

        $deployEvent->refresh();
        $this->assertEquals(SmokeTestStatus::PASSED, $deployEvent->smoke_test_status);
    }

    public function test_partial_failure_marks_event_as_failed_and_queues_rollback(): void
    {
        Queue::fake();

        $deployEvent = $this->makeDeployEvent();
        $this->makeSmokeTestConfig('fr-example-shop', [
            ['url' => 'https://example.com/', 'expected_status' => 200, 'timeout_ms' => 3000],
            ['url' => 'https://example.com/broken', 'expected_status' => 200, 'timeout_ms' => 3000],
        ]);

        Http::fake([
            'example.com' => Http::response('OK', 200),
            'example.com/broken' => Http::response('Error', 503),
        ]);

        (new RunPostDeploySmokeTests($deployEvent))->handle();

        $deployEvent->refresh();
        $this->assertEquals(SmokeTestStatus::FAILED, $deployEvent->smoke_test_status);

        $results = $deployEvent->smoke_test_results;
        $this->assertCount(2, $results);

        Queue::assertPushed(TriggerDokployRollback::class);
    }

    public function test_all_tests_pass_notifies_admins(): void
    {
        Queue::fake();
        Notification::fake();

        $team = Team::factory()->create();
        User::factory()->admin()->create(['team_id' => $team->id]);

        $deployEvent = $this->makeDeployEvent();
        $this->makeSmokeTestConfig('fr-example-shop', [
            ['url' => 'https://example.com/', 'expected_status' => 200, 'timeout_ms' => 3000],
        ]);

        Http::fake([
            'example.com/*' => Http::response('OK', 200),
        ]);

        (new RunPostDeploySmokeTests($deployEvent))->handle();

        Notification::assertSentTo(
            User::admin()->first(),
            \App\Notifications\DeployPassedNotification::class
        );
    }
}
