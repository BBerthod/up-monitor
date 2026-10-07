<?php

namespace App\Services;

use App\Enums\FunctionalCheckStatus;
use App\Enums\FunctionalCheckType;
use App\Enums\IncidentCause;
use App\Events\IncidentCreated;
use App\Events\IncidentResolved;
use App\Models\FunctionalCheck;
use App\Models\FunctionalCheckResult;
use App\Models\MonitorIncident;
use App\Services\Checkers\Functional\ContentChecker;
use App\Services\Checkers\Functional\RedirectChecker;
use App\Services\Checkers\Functional\RobotsChecker;
use App\Services\Checkers\Functional\SitemapChecker;

class FunctionalCheckService
{
    public function __construct(private NotificationService $notificationService) {}

    public function run(FunctionalCheck $check): FunctionalCheckResult
    {
        $result = match ($check->type) {
            FunctionalCheckType::CONTENT => (new ContentChecker)->check($check),
            FunctionalCheckType::REDIRECT => (new RedirectChecker)->check($check),
            FunctionalCheckType::SITEMAP => (new SitemapChecker)->check($check),
            FunctionalCheckType::ROBOTS_TXT => (new RobotsChecker)->check($check),
        };

        $status = $result->passed
            ? FunctionalCheckStatus::PASSED
            : FunctionalCheckStatus::FAILED;

        $record = FunctionalCheckResult::create([
            'functional_check_id' => $check->id,
            'status' => $status,
            'duration_ms' => $result->durationMs,
            'details' => $result->details,
            'error_message' => $result->errorMessage,
            'checked_at' => now(),
        ]);

        $check->update([
            'last_checked_at' => now(),
            'last_status' => $status,
        ]);

        $this->handleIncident($check, $record);

        return $record;
    }

    private function handleIncident(FunctionalCheck $check, FunctionalCheckResult $record): void
    {
        $activeIncidents = MonitorIncident::where('functional_check_id', $check->id)
            ->whereNull('resolved_at')
            ->latest('started_at')
            ->get();

        if ($record->status === FunctionalCheckStatus::PASSED) {
            // Resolve ALL open incidents for this check — resolving only the latest
            // one leaves any older duplicate open forever (zombie incident).
            foreach ($activeIncidents as $incident) {
                $incident->resolve();
                event(new IncidentResolved($incident));
                $this->notificationService->notifyUp($check->monitor, $incident);
            }

            if ($activeIncidents->isNotEmpty()) {
                MetricsService::invalidateCache($check->monitor->team_id);
            }

            return;
        }

        // Failed — check if 3 consecutive failures to open an incident
        if ($activeIncidents->isNotEmpty()) {
            return;
        }

        $lastThree = FunctionalCheckResult::where('functional_check_id', $check->id)
            ->latest('checked_at')
            ->limit(3)
            ->get();

        $allFailed = $lastThree->count() === 3
            && $lastThree->every(fn ($r) => $r->status === FunctionalCheckStatus::FAILED);

        if ($allFailed) {
            $incident = MonitorIncident::create([
                'monitor_id' => $check->monitor_id,
                'functional_check_id' => $check->id,
                'started_at' => now(),
                'cause' => IncidentCause::FUNCTIONAL,
            ]);

            // Mirrors CheckService: fires the same event a network-level incident would,
            // so CreateUptimeInsight projects this into the Vikunja inbox exactly like
            // any other incident. Previously never fired here, so functional-check
            // failures (sitemap/redirect/content/robots.txt) existed only as an email,
            // invisible to the board.
            event(new IncidentCreated($incident));

            $this->notificationService->notifyDown($check->monitor, $incident);
        }
    }
}
