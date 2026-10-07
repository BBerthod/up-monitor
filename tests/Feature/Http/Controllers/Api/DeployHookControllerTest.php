<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Jobs\RunPostDeploySmokeTests;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeployHookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(): array
    {
        return [
            'applicationId' => 'fr-example-shop',
            'applicationName' => 'example-shop FR',
            'commitSha' => 'abc123def456',
            'deployedAt' => now()->toISOString(),
            'status' => 'success',
        ];
    }

    public function test_webhook_with_missing_secret_returns_401(): void
    {
        config()->set('services.dokploy.webhook_secret', 'valid-secret');

        $response = $this->postJson(route('api.deploy-hooks.dokploy'), $this->validPayload());

        $response->assertStatus(401);
    }

    public function test_webhook_with_wrong_secret_returns_401(): void
    {
        config()->set('services.dokploy.webhook_secret', 'valid-secret');

        $response = $this->postJson(
            route('api.deploy-hooks.dokploy'),
            $this->validPayload(),
            ['X-Dokploy-Secret' => 'wrong-secret']
        );

        $response->assertStatus(401);
    }

    public function test_webhook_with_valid_secret_creates_deploy_event(): void
    {
        Queue::fake();
        config()->set('services.dokploy.webhook_secret', 'valid-secret');

        $response = $this->postJson(
            route('api.deploy-hooks.dokploy'),
            $this->validPayload(),
            ['X-Dokploy-Secret' => 'valid-secret']
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('deploy_events', [
            'application_id' => 'fr-example-shop',
            'application_name' => 'example-shop FR',
            'commit_sha' => 'abc123def456',
            'smoke_test_status' => 'pending',
        ]);
    }

    public function test_webhook_with_valid_secret_dispatches_smoke_test_job(): void
    {
        Queue::fake();
        config()->set('services.dokploy.webhook_secret', 'valid-secret');

        $this->postJson(
            route('api.deploy-hooks.dokploy'),
            $this->validPayload(),
            ['X-Dokploy-Secret' => 'valid-secret']
        );

        Queue::assertPushed(RunPostDeploySmokeTests::class, function (RunPostDeploySmokeTests $job): bool {
            return $job->deployEvent->application_id === 'fr-example-shop';
        });
    }

    public function test_webhook_returns_deploy_event_id(): void
    {
        Queue::fake();
        config()->set('services.dokploy.webhook_secret', 'valid-secret');

        $response = $this->postJson(
            route('api.deploy-hooks.dokploy'),
            $this->validPayload(),
            ['X-Dokploy-Secret' => 'valid-secret']
        );

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertNotNull($response->json('id'));
    }

    public function test_webhook_missing_application_id_returns_422(): void
    {
        config()->set('services.dokploy.webhook_secret', 'valid-secret');

        $payload = $this->validPayload();
        unset($payload['applicationId']);

        $response = $this->postJson(
            route('api.deploy-hooks.dokploy'),
            $payload,
            ['X-Dokploy-Secret' => 'valid-secret']
        );

        $response->assertStatus(422);
    }

    public function test_webhook_with_empty_secret_config_returns_401(): void
    {
        config()->set('services.dokploy.webhook_secret', null);

        $response = $this->postJson(
            route('api.deploy-hooks.dokploy'),
            $this->validPayload(),
            ['X-Dokploy-Secret' => 'any-secret']
        );

        // Empty config means we reject all requests
        $response->assertStatus(401);
    }

    public function test_deploy_hook_silences_monitors_for_linked_site(): void
    {
        Queue::fake();
        config()->set('services.dokploy.webhook_secret', 'valid-secret');
        config()->set('monitoring.deploy_silence_minutes', 10);
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['dokploy_app_id' => 'fr-example-shop']);
        $monitor = Monitor::factory()->for($team)->for($site)->create();
        $this->postJson(route('api.deploy-hooks.dokploy'), $this->validPayload(), ['X-Dokploy-Secret' => 'valid-secret']);
        $monitor->refresh();
        $this->assertNotNull($monitor->deploying_until);
        $this->assertTrue($monitor->deploying_until->isFuture());
    }
}
