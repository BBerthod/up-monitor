<?php

namespace App\Jobs;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\DeployEvent;
use App\Models\Insight;
use App\Models\Site;
use App\Models\User;
use App\Notifications\DeployFailedSmokeTestsNotification;
use App\Notifications\DeployRollbackFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TriggerDokployRollback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public function __construct(public DeployEvent $deployEvent) {}

    public function handle(): void
    {
        // Notify admins that smoke tests failed and rollback is being triggered
        $this->notifyAdmins(new DeployFailedSmokeTestsNotification($this->deployEvent));

        $baseUrl = rtrim((string) config('services.dokploy.base_url'), '/');
        $apiToken = config('services.dokploy.api_token');

        if (empty($apiToken)) {
            Log::error('TriggerDokployRollback: DOKPLOY_API_TOKEN is not set', [
                'deploy_event_id' => $this->deployEvent->id,
            ]);

            $this->handleRollbackFailure('DOKPLOY_API_TOKEN is not configured');

            return;
        }

        try {
            $response = Http::timeout(30)
                ->withToken($apiToken)
                ->post("{$baseUrl}/api/application.deploy", [
                    'applicationId' => $this->deployEvent->application_id,
                    'rollback' => true,
                ]);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Dokploy rollback API returned {$response->status()}: {$response->body()}"
                );
            }

            $this->deployEvent->update(['rollback_triggered' => true]);

            Log::info('TriggerDokployRollback: rollback triggered successfully', [
                'deploy_event_id' => $this->deployEvent->id,
                'application_id' => $this->deployEvent->application_id,
            ]);
        } catch (Throwable $e) {
            Log::error('TriggerDokployRollback: rollback API call failed', [
                'deploy_event_id' => $this->deployEvent->id,
                'application_id' => $this->deployEvent->application_id,
                'error' => $e->getMessage(),
            ]);

            $this->handleRollbackFailure($e->getMessage());

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('TriggerDokployRollback job permanently failed', [
            'deploy_event_id' => $this->deployEvent->id,
            'application_id' => $this->deployEvent->application_id,
            'error' => $e->getMessage(),
        ]);

        $this->handleRollbackFailure($e->getMessage());
    }

    private function handleRollbackFailure(string $error): void
    {
        $this->notifyAdmins(new DeployRollbackFailedNotification($this->deployEvent, $error));

        $this->createRollbackFailedInsight($error);
    }

    /**
     * Mirror a failed rollback into the copilot inbox as a DEPLOY_ROLLBACK_FAILED
     * insight. Scoped to this notification only — DeployFailedSmokeTestsNotification
     * (rollback already in flight, no action needed yet) and DeployPassedNotification
     * (nothing wrong) do not need a board card.
     *
     * DeployEvent carries no team_id (only Dokploy's raw application_id/name), so the
     * owning team is resolved by matching against Site::dokploy_app_id — the same
     * lookup DeployHookController already uses for the deploy-silence window. A
     * deploy for an application that is not attached to any Site has no team to
     * attribute the insight to; that is logged and skipped rather than raising an
     * exception, since it must never abort the rollback job itself.
     *
     * No automatic resolution path exists: unlike uptime/warming, a failed rollback
     * does not self-heal — a human must intervene on the server, then close the
     * Vikunja card by hand (VikunjaTaskService::reconcileOne already acknowledges
     * the insight when the card is marked done).
     */
    private function createRollbackFailedInsight(string $error): void
    {
        try {
            $site = Site::withoutGlobalScopes()
                ->where('dokploy_app_id', $this->deployEvent->application_id)
                ->first();

            if ($site === null) {
                Log::info('TriggerDokployRollback: no Site matches this Dokploy application, skipping inbox insight', [
                    'deploy_event_id' => $this->deployEvent->id,
                    'application_id' => $this->deployEvent->application_id,
                ]);

                return;
            }

            // Idempotence: handleRollbackFailure() can run more than once for the
            // same DeployEvent (each retry attempt, then failed()) — one open
            // insight per deploy event is enough.
            $exists = Insight::withoutGlobalScopes()
                ->where('type', InsightType::DEPLOY_ROLLBACK_FAILED->value)
                ->where('payload->deploy_event_id', $this->deployEvent->id)
                ->whereNull('acknowledged_at')
                ->exists();

            if ($exists) {
                return;
            }

            Insight::create([
                'team_id' => $site->team_id,
                'site' => $site->primary_domain,
                'site_id' => $site->id,
                'monitor_id' => null,
                'type' => InsightType::DEPLOY_ROLLBACK_FAILED->value,
                // Always CRITICAL: a broken deploy stuck in production with no
                // working automatic recovery is never a lesser severity.
                'severity' => InsightSeverity::CRITICAL->value,
                'title' => "Deployment rollback failed: {$this->deployEvent->application_name}",
                'payload' => [
                    'deploy_event_id' => $this->deployEvent->id,
                    'application_id' => $this->deployEvent->application_id,
                    'application_name' => $this->deployEvent->application_name,
                    'commit_sha' => $this->deployEvent->commit_sha,
                    'rollback_error' => $error,
                    // DeployRollbackFailedNotification already emailed every admin
                    // above — SeoAlertService must not send a second email.
                    'already_notified_directly' => true,
                ],
                'impact_score' => 100,
                'detected_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('TriggerDokployRollback: failed to mirror rollback failure to inbox', [
                'deploy_event_id' => $this->deployEvent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdmins(object $notification): void
    {
        User::admin()->get()->each(function (User $user) use ($notification): void {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                Log::error('TriggerDokployRollback: failed to notify admin', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
