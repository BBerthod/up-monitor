<?php

namespace App\Listeners;

use App\Enums\IncidentSeverity;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Events\IncidentCreated;
use App\Models\Insight;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mirrors a freshly-opened uptime incident into the unified copilot inbox as an
 * UPTIME_INCIDENT insight, so availability signals sit alongside SEO/server ones.
 * Covers both real monitor-down incidents and FunctionalCheck failures
 * (sitemap/redirect/content/robots.txt) — the latter previously only existed as an
 * email (MonitorAlertMail), invisible to the Vikunja board and never auto-resolved
 * anywhere but the inbox.
 *
 * The MonitorIncident remains the source of truth for the incident lifecycle and
 * notifications; this insight is purely the triage-inbox projection. Resolution is
 * handled by ResolveUptimeInsight (listens to IncidentResolved).
 *
 * Queued on `monitors` and wrapped in try/catch: a failure here must never break
 * the check pipeline that fired the event.
 */
class CreateUptimeInsight implements ShouldQueue
{
    public string $queue = 'monitors';

    public int $tries = 3;

    public array $backoff = [30, 60];

    public function handle(IncidentCreated $event): void
    {
        $incident = $event->incident;

        try {
            $monitor = $incident->monitor;

            if ($monitor === null) {
                return;
            }

            // Idempotence: one open uptime insight per monitor. If a previous DOWN is
            // still unacknowledged (e.g. a flap reopened the incident), keep it.
            $exists = Insight::withoutGlobalScopes()
                ->where('monitor_id', $monitor->id)
                ->where('type', InsightType::UPTIME_INCIDENT->value)
                ->whereNull('acknowledged_at')
                ->exists();

            if ($exists) {
                return;
            }

            $hostname = $this->hostname((string) $monitor->url);
            $cause = $incident->cause?->value ?? 'error';
            $isFunctional = $incident->functional_check_id !== null;

            // Functional checks (sitemap/redirect/content/robots.txt) all share the
            // generic IncidentCause::FUNCTIONAL, which says nothing about what actually
            // broke — read the check itself for a title that is actually actionable.
            $title = $isFunctional
                ? sprintf('Functional check failed: %s (%s)', $incident->functionalCheck?->name ?? 'unknown check', $hostname)
                : sprintf('Monitor DOWN: %s (%s)', $hostname, $cause);

            Insight::create([
                'team_id' => $monitor->team_id,
                'site' => $hostname,
                'site_id' => $monitor->site_id,
                'monitor_id' => $monitor->id,
                'type' => InsightType::UPTIME_INCIDENT->value,
                'severity' => $this->mapSeverity($incident->severity)->value,
                'title' => $title,
                'payload' => [
                    'incident_id' => $incident->id,
                    'monitor_id' => $monitor->id,
                    'cause' => $cause,
                    'severity' => $incident->severity?->value,
                    'started_at' => $incident->started_at?->toIso8601String(),
                    'functional_check_id' => $incident->functional_check_id,
                    // FunctionalCheckService already dispatched an immediate
                    // MonitorAlertMail (NotificationService::notifyDown) when this
                    // incident opened. This insight exists purely for Vikunja-board
                    // visibility, so SeoAlertService must not email it a second time
                    // during the next DispatchSeoAlerts run.
                    'already_notified_directly' => $isFunctional,
                ],
                // Availability incidents are top-priority in the inbox.
                'impact_score' => 100,
                'detected_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('CreateUptimeInsight: failed to mirror incident to inbox', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map an incident severity onto an insight severity.
     * CRITICAL/MAJOR are genuine outages -> CRITICAL; everything else -> WARNING.
     */
    private function mapSeverity(?IncidentSeverity $severity): InsightSeverity
    {
        return match ($severity) {
            IncidentSeverity::CRITICAL, IncidentSeverity::MAJOR => InsightSeverity::CRITICAL,
            default => InsightSeverity::WARNING,
        };
    }

    private function hostname(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        // str_starts_with + substr, NOT ltrim($host, 'www.') -- ltrim strips a char set.
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host !== '' ? $host : $url;
    }
}
