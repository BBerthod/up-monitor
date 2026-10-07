<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Enums\IncidentCause;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\WarmRunStatus;
use App\Jobs\Notifications\SendBusinessKpiNotification;
use App\Jobs\Notifications\SendDiscordNotification;
use App\Jobs\Notifications\SendEmailNotification;
use App\Jobs\Notifications\SendPushNotification;
use App\Jobs\Notifications\SendSlackNotification;
use App\Jobs\Notifications\SendTelegramNotification;
use App\Jobs\Notifications\SendWebhookNotification;
use App\Models\BusinessKpiIncident;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\WarmRun;
use App\Models\WarmSite;
use App\Notifications\WarmRunFailedNotification;
use App\Notifications\WarmSiteDisabledNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    public function notifyDown(Monitor $monitor, MonitorIncident $incident, ?MonitorCheck $check = null): void
    {
        // LOT4.3 deploy window guard — suppress alerts while a deploy is in progress.
        if ($monitor->deploying_until && $monitor->deploying_until->isFuture()) {
            Log::info('NotificationService: suppressed notifyDown — deploy window', ['monitor_id' => $monitor->id, 'deploying_until' => $monitor->deploying_until]);

            return;
        }

        // LOT4.2 server correlation guard.
        $monitor->loadMissing('site');
        $serverId = $monitor->site?->server_id;
        if ($serverId !== null) {
            // Mechanism A: heartbeat suppression. Mechanism B: correlation window.
            $hb = Insight::withoutGlobalScopes()->where('type', InsightType::SERVER_HEALTH->value)->whereNull('acknowledged_at')->where('payload->server_id', $serverId)->where('payload->metric', 'heartbeat')->exists();
            if ($hb) {
                Log::info('NotificationService: suppressed — server heartbeat alerted', ['monitor_id' => $monitor->id, 'server_id' => $serverId]);

                return;
            }
            $corrKey = "server_down_corr:{$serverId}";
            $corrWindow = config('monitoring.server_correlation_window_seconds', 120);
            if (Cache::has($corrKey)) {
                $count = (int) Cache::get($corrKey) + 1;
                Cache::put($corrKey, $count, now()->addSeconds($corrWindow));
                Log::info('NotificationService: suppressed — server correlation window active', ['monitor_id' => $monitor->id, 'server_id' => $serverId, 'correlated_count' => $count]);

                return;
            }
            Cache::put($corrKey, 1, now()->addSeconds($corrWindow));
        }
        // Record that a "down" notification was triggered for this incident.
        // notifyUp() relies on this to avoid sending an orphan "recovery" alert
        // when a monitor flapped briefly without ever reaching alert_after_failures.
        if ($incident->exists && $incident->down_notified_at === null) {
            $incident->down_notified_at = now();
            $incident->save();
        }

        $cooldownMinutes = config('monitoring.notification_cooldown_minutes', 5);

        $channels = $monitor->relationLoaded('notificationChannels')
            ? $monitor->notificationChannels->where('is_active', true)
            : $monitor->notificationChannels()->where('is_active', true)->get();

        foreach ($channels as $channel) {
            // Per-channel cooldown: each channel gets its own independent key so a
            // slow/misconfigured channel does not suppress notifications on others.
            $cooldownKey = "notify:{$monitor->id}:{$channel->id}:down";

            if (Cache::has($cooldownKey)) {
                continue;
            }

            Cache::put($cooldownKey, true, now()->addMinutes($cooldownMinutes));
            $this->dispatchForChannel($channel, 'down', $monitor, $incident, $check);
        }
    }

    public function notifyUp(Monitor $monitor, MonitorIncident $incident, ?MonitorCheck $check = null): void
    {
        // Never send a recovery alert for an incident that never triggered a "down"
        // notification (e.g. a brief flap resolved before reaching the failure
        // threshold). Prevents "is UP" spam with no preceding "is DOWN".
        if ($incident->down_notified_at === null) {
            return;
        }

        $cooldownMinutes = config('monitoring.notification_cooldown_minutes', 5);

        $channels = $monitor->relationLoaded('notificationChannels')
            ? $monitor->notificationChannels->where('is_active', true)
            : $monitor->notificationChannels()->where('is_active', true)->get();

        foreach ($channels as $channel) {
            // Per-channel cooldown for "up" events prevents flap spam.
            // We do NOT reset the "down" cooldown here — that key expires naturally,
            // ensuring a fresh outage always triggers a new "down" notification.
            $cooldownKey = "notify:{$monitor->id}:{$channel->id}:up";

            if (Cache::has($cooldownKey)) {
                continue;
            }

            Cache::put($cooldownKey, true, now()->addMinutes($cooldownMinutes));
            $this->dispatchForChannel($channel, 'up', $monitor, $incident, $check);
        }
    }

    /**
     * Notify on warming failure with transition detection and circuit breaker.
     *
     * - Notifies only on the first failure after a successful run (success→fail transition).
     * - After CIRCUIT_BREAKER_THRESHOLD consecutive failures, auto-disables the site
     *   and sends a single "auto-disabled" notification. No further notifications after that.
     * - A 1-hour dedup cache key acts as a safety net in all cases.
     */
    public function notifyWarmingFailed(WarmSite $warmSite, WarmRun $warmRun): void
    {
        // Safety net: never send more than once per hour per site.
        $dedupKey = "notify:warming:{$warmSite->id}:failed";
        if (Cache::has($dedupKey)) {
            return;
        }

        $consecutiveFailures = $this->countConsecutiveFailures($warmSite, $warmRun);

        $threshold = config('warming.circuit_breaker_threshold', 5);

        // Circuit breaker: disable site and send the final "auto-disabled" email.
        if ($consecutiveFailures >= $threshold) {
            $warmSite->update(['is_active' => false]);

            Log::warning('WarmSite auto-disabled after consecutive failures', [
                'warm_site_id' => $warmSite->id,
                'consecutive_failures' => $consecutiveFailures,
            ]);

            Cache::put($dedupKey, true, now()->addHour());

            $owner = $warmSite->team->users()->first();
            if ($owner) {
                $owner->notify(new WarmSiteDisabledNotification($warmSite, $consecutiveFailures));
            }

            // Auto-disabling is the actionable case (unlike a single transient
            // WarmRunFailedNotification below, which usually self-resolves on the
            // next run) — mirror it into the copilot inbox, or it stays invisible
            // outside this one email until someone happens to open /warming.
            $this->createWarmingDisabledInsight($warmSite, $consecutiveFailures);

            return;
        }

        // Transition guard: only notify on the first failure after a success.
        // If the previous non-current run was also a FAILED run, this is already a
        // known ongoing failure — stay silent to avoid spam.
        if (! $this->isSuccessToFailTransition($warmSite, $warmRun)) {
            return;
        }

        Cache::put($dedupKey, true, now()->addHour());

        $owner = $warmSite->team->users()->first();
        if ($owner) {
            $owner->notify(new WarmRunFailedNotification($warmSite, $warmRun));
        }
    }

    /**
     * Mirror a circuit-breaker auto-disable into the copilot inbox as a
     * WARMING_DISABLED insight, so it is visible on the Vikunja board and not
     * only in the WarmSiteDisabledNotification email already sent above.
     *
     * Idempotent: skips creation if an unacknowledged WARMING_DISABLED insight
     * already exists for this warm site (e.g. re-disabled after a reactivation
     * attempt that failed again before the previous insight was resolved).
     */
    private function createWarmingDisabledInsight(WarmSite $warmSite, int $consecutiveFailures): void
    {
        try {
            $exists = Insight::withoutGlobalScopes()
                ->where('type', InsightType::WARMING_DISABLED->value)
                ->where('payload->warm_site_id', $warmSite->id)
                ->whereNull('acknowledged_at')
                ->exists();

            if ($exists) {
                return;
            }

            Insight::create([
                'team_id' => $warmSite->team_id,
                'site' => $warmSite->domain,
                'site_id' => $warmSite->monitor?->site_id,
                'monitor_id' => $warmSite->monitor_id,
                'type' => InsightType::WARMING_DISABLED->value,
                'severity' => InsightSeverity::CRITICAL->value,
                'title' => "Cache warming auto-disabled: {$warmSite->domain}",
                'payload' => [
                    'warm_site_id' => $warmSite->id,
                    'consecutive_failures' => $consecutiveFailures,
                    // WarmSiteDisabledNotification already emailed the owner above —
                    // SeoAlertService must not send a second email for this insight.
                    'already_notified_directly' => true,
                ],
                'impact_score' => 60,
                'detected_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('NotificationService: failed to mirror warming auto-disable to inbox', [
                'warm_site_id' => $warmSite->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Acknowledge the open WARMING_DISABLED insight for a warm site, if any.
     * Called both when a site is manually reactivated (WarmSiteController::update)
     * and when a run completes successfully regardless of trigger (RunWarmSite),
     * since "Warm Now" can be fired manually while the site is still disabled.
     */
    public function resolveWarmingDisabled(WarmSite $warmSite): void
    {
        try {
            $insight = Insight::withoutGlobalScopes()
                ->where('type', InsightType::WARMING_DISABLED->value)
                ->where('payload->warm_site_id', $warmSite->id)
                ->whereNull('acknowledged_at')
                ->first();

            $insight?->acknowledge();
        } catch (Throwable $e) {
            Log::error('NotificationService: failed to auto-resolve warming inbox insight', [
                'warm_site_id' => $warmSite->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Count how many consecutive FAILED runs precede (and include) the given run,
     * ordered by started_at DESC. Scans at most the last 20 runs to avoid full-table scans.
     */
    private function countConsecutiveFailures(WarmSite $warmSite, WarmRun $warmRun): int
    {
        $recentRuns = WarmRun::where('warm_site_id', $warmSite->id)
            ->whereIn('status', [WarmRunStatus::FAILED->value, WarmRunStatus::COMPLETED->value])
            ->orderByDesc('started_at')
            ->limit(20)
            ->get(['id', 'status']);

        $count = 0;

        foreach ($recentRuns as $run) {
            if ($run->status === WarmRunStatus::FAILED) {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Returns true when the run just before the current one (excluding RUNNING status)
     * was COMPLETED — i.e., we are at the transition point success→fail.
     */
    private function isSuccessToFailTransition(WarmSite $warmSite, WarmRun $warmRun): bool
    {
        $previousRun = WarmRun::where('warm_site_id', $warmSite->id)
            ->where('id', '!=', $warmRun->id)
            ->whereIn('status', [WarmRunStatus::FAILED->value, WarmRunStatus::COMPLETED->value])
            ->orderByDesc('started_at')
            ->limit(1)
            ->first(['status']);

        // No previous run at all → first failure ever, treat as transition.
        if ($previousRun === null) {
            return true;
        }

        return $previousRun->status === WarmRunStatus::COMPLETED;
    }

    /**
     * Send a synchronous test notification through the real Send*Notification jobs.
     * Uses dispatchSync() so the caller receives immediate feedback on success/failure.
     * A fake Monitor and MonitorIncident are used — no real data is stored.
     */
    public function sendTestNotification(NotificationChannel $channel): void
    {
        $monitor = new Monitor([
            'name' => 'Test Monitor',
            'url' => 'https://example.com',
        ]);
        $monitor->id = 0;

        $incident = new MonitorIncident([
            'monitor_id' => 0,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now(),
        ]);
        $incident->id = 0;

        $check = new MonitorCheck([
            'monitor_id' => 0,
            'status_code' => 0,
            'response_time_ms' => 0,
            'checked_at' => now(),
        ]);
        $check->id = 0;

        $jobClass = match ($channel->type) {
            ChannelType::EMAIL => SendEmailNotification::class,
            ChannelType::WEBHOOK => SendWebhookNotification::class,
            ChannelType::SLACK => SendSlackNotification::class,
            ChannelType::DISCORD => SendDiscordNotification::class,
            ChannelType::PUSH => SendPushNotification::class,
            ChannelType::TELEGRAM => SendTelegramNotification::class,
        };

        $jobClass::dispatchSync($channel, 'down', $monitor, $incident, $check);
    }

    /**
     * Dispatch a business KPI regression alert to a single notification channel.
     */
    public function notifyBusinessKpi(NotificationChannel $channel, string $message, BusinessKpiIncident $incident): void
    {
        SendBusinessKpiNotification::dispatch($channel, $message, $incident);
    }

    private function dispatchForChannel(NotificationChannel $channel, string $event, Monitor $monitor, MonitorIncident $incident, ?MonitorCheck $check): void
    {
        $jobClass = match ($channel->type) {
            ChannelType::EMAIL => SendEmailNotification::class,
            ChannelType::WEBHOOK => SendWebhookNotification::class,
            ChannelType::SLACK => SendSlackNotification::class,
            ChannelType::DISCORD => SendDiscordNotification::class,
            ChannelType::PUSH => SendPushNotification::class,
            ChannelType::TELEGRAM => SendTelegramNotification::class,
        };

        $jobClass::dispatch($channel, $event, $monitor, $incident, $check);
    }
}
