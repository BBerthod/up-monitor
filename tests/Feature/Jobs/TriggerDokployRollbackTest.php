<?php

namespace Tests\Feature\Jobs;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Jobs\TriggerDokployRollback;
use App\Models\DeployEvent;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Notifications\DeployRollbackFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TriggerDokployRollbackTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeployEvent(string $applicationId = 'fr-example-shop'): DeployEvent
    {
        return DeployEvent::create([
            'application_id' => $applicationId,
            'application_name' => 'example-shop FR',
            'commit_sha' => 'abc1234',
            'deployed_at' => now(),
            'smoke_test_status' => 'failed',
        ]);
    }

    // A missing DOKPLOY_API_TOKEN takes the same handleRollbackFailure() path as a
    // failed rollback call, without needing to fake the HTTP client — the simplest
    // deterministic way to reach the branch under test.
    private function triggerRollbackFailure(DeployEvent $deployEvent): void
    {
        config(['services.dokploy.api_token' => null]);

        (new TriggerDokployRollback($deployEvent))->handle();
    }

    public function test_rollback_failure_creates_insight_scoped_to_the_matching_site(): void
    {
        Notification::fake();

        $team = Team::factory()->create();
        User::factory()->for($team)->admin()->create();
        $site = Site::factory()->for($team)->create(['dokploy_app_id' => 'fr-example-shop']);

        $deployEvent = $this->makeDeployEvent('fr-example-shop');

        $this->triggerRollbackFailure($deployEvent);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::DEPLOY_ROLLBACK_FAILED->value)
            ->where('payload->deploy_event_id', $deployEvent->id)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame($team->id, $insight->team_id);
        $this->assertSame($site->id, $insight->site_id);
        $this->assertSame(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertTrue($insight->payload['already_notified_directly']);
        $this->assertSame($deployEvent->application_id, $insight->payload['application_id']);
        $this->assertNull($insight->acknowledged_at);
    }

    public function test_rollback_failure_without_matching_site_does_not_create_insight(): void
    {
        Notification::fake();
        Log::spy();

        $team = Team::factory()->create();
        User::factory()->for($team)->admin()->create();

        // No Site anywhere carries this application_id.
        $deployEvent = $this->makeDeployEvent('unmonitored-app');

        $this->triggerRollbackFailure($deployEvent);

        $this->assertDatabaseCount('insights', 0);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'no Site matches this Dokploy application'))
            ->once();

        // The admin email must still go out even though there is no board card.
        Notification::assertSentTo(
            User::where('team_id', $team->id)->first(),
            DeployRollbackFailedNotification::class
        );
    }

    public function test_rollback_failure_does_not_duplicate_insight_across_retries(): void
    {
        Notification::fake();

        $team = Team::factory()->create();
        User::factory()->for($team)->admin()->create();
        Site::factory()->for($team)->create(['dokploy_app_id' => 'fr-example-shop']);

        $deployEvent = $this->makeDeployEvent('fr-example-shop');

        // Simulates handleRollbackFailure() firing again on a later retry attempt,
        // then once more from failed() after tries are exhausted.
        $this->triggerRollbackFailure($deployEvent);
        $this->triggerRollbackFailure($deployEvent);

        $this->assertSame(
            1,
            Insight::withoutGlobalScopes()
                ->where('type', InsightType::DEPLOY_ROLLBACK_FAILED->value)
                ->where('payload->deploy_event_id', $deployEvent->id)
                ->count()
        );
    }
}
