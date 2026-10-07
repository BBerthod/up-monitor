<?php

namespace App\Services;

use App\Contracts\MonitorChecker;
use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Enums\IncidentSeverity;
use App\Enums\MonitorType;
use App\Events\IncidentCreated;
use App\Events\IncidentResolved;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Services\Checkers\DnsChecker;
use App\Services\Checkers\HttpChecker;
use App\Services\Checkers\PingChecker;
use App\Services\Checkers\PortChecker;
use Illuminate\Support\Facades\Cache;

class CheckService
{
    private array $checkers = [];

    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function check(Monitor $monitor): MonitorCheck
    {
        $checker = $this->resolveChecker($monitor->type ?? MonitorType::HTTP);
        $result = $checker->check($monitor);

        $check = MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => $result->status,
            'response_time_ms' => $result->responseTimeMs,
            'status_code' => $result->statusCode,
            'ssl_expires_at' => $result->sslExpiresAt,
            'error_message' => $result->errorMessage,
            'checked_at' => now(),
        ]);

        $monitor->update(['last_checked_at' => now()]);

        $monitor->load('notificationChannels');

        // TTL of 60s: the lock covers incident detection + checkThresholds() DB queries.
        // 30s was too tight under load; flapping monitors with threshold checks could exceed it.
        $lock = Cache::lock("monitor:check:{$monitor->id}", 60);

        if ($lock->block(10)) {
            try {
                $alertAfter = $monitor->alert_after_failures ?? 3;

                // Fetch enough checks to detect the transition and count consecutive failures.
                // The current check is already persisted, so checks()->latest() includes it.
                $recentChecks = $monitor->checks()
                    ->latest('checked_at')
                    ->limit($alertAfter + 1)
                    ->get();

                $isUp = $result->status === CheckStatus::UP;
                $previousCheck = $recentChecks->skip(1)->first();
                $wasUp = $previousCheck === null || $previousCheck->status === CheckStatus::UP;

                if ($wasUp && ! $isUp) {
                    // Anti-flapping guard: if a previous incident was resolved very
                    // recently (within the configured flap window), reopen it instead
                    // of creating a new one. This collapses rapid down/up oscillations
                    // — e.g. 765 incidents/week on a flapping monitor — into a single
                    // incident record, avoiding dashboard noise and notification spam.
                    $flapWindowMinutes = config('monitoring.flap_window_minutes', 10);
                    $incident = null;

                    if ($flapWindowMinutes > 0) {
                        $recentlyResolved = $monitor->incidents()
                            ->whereNotNull('resolved_at')
                            ->latest('resolved_at')
                            ->first();

                        if (
                            $recentlyResolved !== null
                            && $recentlyResolved->resolved_at->gte(now()->subMinutes($flapWindowMinutes))
                        ) {
                            // Reopen: clear resolved_at so the incident is active again.
                            // We intentionally keep the original cause — the initial failure
                            // cause is more stable and avoids noise from transient error types.
                            // We do NOT reset down_notified_at (if it already alerted, no re-alert;
                            // if it never alerted, the threshold logic on subsequent checks handles it).
                            $recentlyResolved->update(['resolved_at' => null]);
                            $incident = $recentlyResolved;

                            // We DO fire IncidentCreated on reopen (unlike notifications, which
                            // must not re-spam): the inbox projection (CreateUptimeInsight) may
                            // have already been acknowledged if the original DOWN was resolved
                            // and its insight closed. Without this event, a flap reopen would
                            // leave the incident active in the DB but invisible in the inbox.
                            // This is safe to fire unconditionally — CreateUptimeInsight is
                            // idempotent and skips if an open insight for this monitor already
                            // exists, so no duplicate insight is created.
                            event(new IncidentCreated($incident));
                            MetricsService::invalidateCache($monitor->team_id);
                            // No notification here: the threshold counter in the next
                            //  cycle will fire if alertAfter > 1,
                            // and alertAfter <= 1 monitors had already been notified on
                            // the first down — we must not re-spam on flap reopen.
                        }
                    }

                    if ($incident === null) {
                        // No recent resolved incident (or flap window disabled): create a new one.
                        $incident = MonitorIncident::create([
                            'monitor_id' => $monitor->id,
                            'started_at' => now(),
                            'cause' => $result->cause,
                            'severity' => $this->resolveSeverity($result->cause),
                        ]);

                        event(new IncidentCreated($incident));
                        MetricsService::invalidateCache($monitor->team_id);

                        // Notify immediately only when the threshold is 1 (default behaviour).
                        if ($alertAfter <= 1) {
                            $this->notificationService->notifyDown($monitor, $incident, $check);
                        }
                    }
                } elseif (! $wasUp && ! $isUp) {
                    // Continuing failure: count consecutive failures from the most-recent check.
                    $consecutiveFailures = 0;
                    foreach ($recentChecks as $c) {
                        if ($c->status !== CheckStatus::UP) {
                            $consecutiveFailures++;
                        } else {
                            break;
                        }
                    }

                    // Fire the notification exactly when we cross the configured threshold.
                    if ($consecutiveFailures === $alertAfter) {
                        $incident = $monitor->incidents()
                            ->whereNull('resolved_at')
                            ->latest('started_at')
                            ->first();

                        if ($incident) {
                            $this->notificationService->notifyDown($monitor, $incident, $check);
                        }
                    }
                } elseif (! $wasUp && $isUp) {
                    // Resolve ALL non-functional open incidents for this monitor.
                    // Functional incidents are managed by FunctionalCheckService and must
                    // not be force-resolved here — they track content/redirect/sitemap health
                    // independently of the HTTP check status.
                    $openIncidents = $monitor->incidents()
                        ->whereNull('resolved_at')
                        ->whereNull('functional_check_id')
                        ->get();

                    foreach ($openIncidents as $incident) {
                        $incident->resolve();
                        event(new IncidentResolved($incident));
                        $this->notificationService->notifyUp($monitor, $incident, $check);
                    }

                    if ($openIncidents->isNotEmpty()) {
                        MetricsService::invalidateCache($monitor->team_id);
                    }
                }

                $this->checkThresholds($monitor, $check);
            } finally {
                $lock->release();
            }
        } else {
            \Log::warning('CheckService: could not acquire lock', ['monitor_id' => $monitor->id]);
        }

        return $check;
    }

    private function resolveChecker(MonitorType $type): MonitorChecker
    {
        return $this->checkers[$type->value] ??= match ($type) {
            MonitorType::HTTP => new HttpChecker,
            MonitorType::PING => new PingChecker,
            MonitorType::PORT => new PortChecker,
            MonitorType::DNS => new DnsChecker,
        };
    }

    private function checkThresholds(Monitor $monitor, MonitorCheck $check): void
    {
        if ($check->status !== CheckStatus::UP || ! $monitor->critical_threshold_ms) {
            return;
        }

        $recentChecks = $monitor->checks()
            ->latest('checked_at')
            ->limit(3)
            ->pluck('response_time_ms');

        if ($recentChecks->count() < 3) {
            return;
        }

        $allExceedCritical = $recentChecks->every(fn ($ms) => $ms >= $monitor->critical_threshold_ms);

        if ($allExceedCritical) {
            $hasActiveIncident = $monitor->incidents()
                ->whereNull('resolved_at')
                ->where('cause', IncidentCause::TIMEOUT)
                ->exists();

            if (! $hasActiveIncident) {
                $incident = MonitorIncident::create([
                    'monitor_id' => $monitor->id,
                    'started_at' => now(),
                    'cause' => IncidentCause::TIMEOUT,
                    'severity' => IncidentSeverity::CRITICAL,
                ]);
                event(new IncidentCreated($incident));
                MetricsService::invalidateCache($monitor->team_id);
                $this->notificationService->notifyDown($monitor, $incident, $check);
            }
        } else {
            // Asymmetric on purpose (same philosophy as ServerHealthDetector: opened
            // after N consecutive breaching samples, closed on the first healthy one).
            // Opening requires all 3 recent checks over the threshold to avoid flapping
            // on a single slow request. But requiring all 3 to be back under threshold
            // to resolve creates a dead zone when latency oscillates around the
            // threshold: e.g. 2 checks above / 1 below never satisfies either branch,
            // so the incident never closes even though the monitor is effectively UP.
            // Resolving on the most-recent check alone avoids that zombie incident.
            $latestBelowThreshold = $recentChecks->first() < $monitor->critical_threshold_ms;

            if ($latestBelowThreshold) {
                $incident = $monitor->incidents()
                    ->whereNull('resolved_at')
                    ->where('cause', IncidentCause::TIMEOUT)
                    ->latest('started_at')
                    ->first();

                if ($incident) {
                    $incident->resolve();
                    event(new IncidentResolved($incident));
                    MetricsService::invalidateCache($monitor->team_id);
                    $this->notificationService->notifyUp($monitor, $incident, $check);
                }
            }
        }
    }

    private function resolveSeverity(IncidentCause $cause): IncidentSeverity
    {
        return match ($cause) {
            IncidentCause::TIMEOUT, IncidentCause::ERROR => IncidentSeverity::CRITICAL,
            IncidentCause::STATUS_CODE, IncidentCause::KEYWORD => IncidentSeverity::MAJOR,
            IncidentCause::SSL => IncidentSeverity::MINOR,
            IncidentCause::FUNCTIONAL => IncidentSeverity::WARNING,
        };
    }
}
