<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Jobs\Notifications\SendInsightAlert;
use App\Models\Insight;
use App\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates SEO directional alert dispatching for one team.
 *
 * Responsibilities:
 *   1. Find unnotified WARNING/CRITICAL insights for the team.
 *   2. Prioritise alerts from monitors flagged is_priority=true (money sites).
 *   3. Apply an anti-spam cap (config seo_alerts.max_per_run).
 *   4. Fan-out to every active notification channel of the team.
 *   5. Mark each dispatched insight with notified_at (idempotency guard).
 *
 * Priority escalation design: we do NOT mutate the stored severity, because that
 * would distort historical reporting. Instead, priority monitors are sorted first
 * in the queue, and their alert title is prefixed with "[Priority]" so recipients
 * can visually distinguish them at a glance. This is a minimal, reversible signal.
 *
 * Called from DispatchSeoAlerts — no auth() context, all queries are explicit by
 * team_id with withoutGlobalScopes() so the job-tenant boundary does not rely on
 * session-bound middleware.
 */
class SeoAlertService
{
    /**
     * Dispatch pending SEO insight alerts for the given team.
     *
     * @param  array<string>|null  $onlyTypes  When provided, only insights whose type
     *                                         value is in this list are dispatched.
     *                                         Null (default) dispatches all alertable types,
     *                                         preserving existing behaviour for DispatchSeoAlerts.
     * @return int Number of insights dispatched (not individual channel deliveries).
     */
    public function dispatchForTeam(Team $team, ?array $onlyTypes = null): int
    {
        // 1. Fetch alertable insights that have never been notified and are not yet
        //    acknowledged. withoutGlobalScopes() bypasses ScopedByTeam middleware
        //    that expects an authenticated session — safe here because team_id is explicit.
        $query = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNull('notified_at')
            ->whereNull('acknowledged_at')
            ->notSnoozed()
            ->whereIn('severity', [InsightSeverity::WARNING->value, InsightSeverity::CRITICAL->value])
            ->with('monitor') // eager-load to read is_priority without N+1
            ->orderByDesc('impact_score');

        if ($onlyTypes !== null) {
            $query->whereIn('type', $onlyTypes);
        }

        $insights = $query->get();

        if ($insights->isEmpty()) {
            return 0;
        }

        // 2. Retrieve active channels for this team.
        $channels = NotificationChannel::where('team_id', $team->id)
            ->where('is_active', true)
            ->get();

        if ($channels->isEmpty()) {
            Log::debug('SeoAlertService: no active channels for team, skipping', [
                'team_id' => $team->id,
            ]);

            return 0;
        }
        // 3. Sort: CRITICAL+priority first, then CRITICAL|priority, then rest.
        //    Within each tier, business-weight breaks ties, then impact_score.
        //
        //    Composite key (descending integer):
        //      base_tier * 10_000  -- 3 tiers from original logic
        //      + businessWeight * 100 -- breaks ties within tier (0-10 range)
        //      + min(99, impact_score) -- final tiebreaker (clamped, no bucket bleed)
        //
        //    REVENUE_AT_RISK (weight=10) sorts above CTR_CHANGE (weight=1) at equal
        //    tier and equal impact_score. Existing tier contract unchanged:
        //      CRITICAL+priority > CRITICAL|priority > WARNING regular.
        $sorted = $insights->sortByDesc(function (Insight $insight): int {
            $isCritical = $insight->severity === InsightSeverity::CRITICAL;
            $isPriority = $insight->monitor?->is_priority ?? false;

            $baseTier = match (true) {
                $isCritical && $isPriority => 2,
                $isCritical || $isPriority => 1,
                default => 0,
            };

            // Guard: type is cast to InsightType by the model, but protect against
            // raw string values on rows created before the cast was in place.
            $typeEnum = $insight->type instanceof InsightType
                ? $insight->type
                : InsightType::tryFrom((string) $insight->type);

            $weight = $typeEnum?->businessWeight() ?? 0;

            return $baseTier * 10_000
                + $weight * 100
                + (int) min(99, (float) $insight->impact_score);
        })->values();

        // 4. Apply anti-spam cap. CRITICAL insights bypass the cap to avoid silently
        //    dropping the most urgent signals.
        $maxPerRun = (int) config('monitoring.seo_alerts.max_per_run', 10);
        $criticals = $sorted->filter(fn (Insight $i) => $i->severity === InsightSeverity::CRITICAL);
        $warnings = $sorted->filter(fn (Insight $i) => $i->severity === InsightSeverity::WARNING);

        // Always include all CRITICALs; fill remaining budget with WARNINGs.
        $criticalCount = $criticals->count();
        $warningBudget = max(0, $maxPerRun - $criticalCount);
        $retained = $criticals->concat($warnings->take($warningBudget));

        $suppressed = $insights->count() - $retained->count();
        if ($suppressed > 0) {
            Log::info('SeoAlertService: alerts suppressed by anti-spam cap', [
                'team_id' => $team->id,
                'suppressed' => $suppressed,
                'cap' => $maxPerRun,
            ]);
        }

        // 5. For each retained insight: prefix priority titles, fan out to all channels,
        //    mark notified_at once (regardless of channel count — one mark covers all).
        $dispatched = 0;

        foreach ($retained as $insight) {
            $isPriority = $insight->monitor?->is_priority ?? false;

            // Priority money-site alerts get a "[Priority]" prefix. We pass it as a
            // scalar override rather than mutating $insight->title, because the job
            // serialises $insight by ID (SerializesModels) and would reload the
            // original title from the DB at execution time, dropping any mutation.
            $titleOverride = $isPriority ? '[Priority] '.$insight->title : null;

            foreach ($channels as $channel) {
                // EMAIL is restricted to a category allowlist (see config comment): most
                // insight types already have an independent visibility path via Vikunja,
                // so an unrestricted daily email would just be noise. Non-email channels
                // are unaffected — none is active in production today, but the fan-out
                // stays generic rather than hardcoding EMAIL-only behaviour.
                if ($channel->type === ChannelType::EMAIL && ! $this->isEmailAlertable($insight)) {
                    continue;
                }

                SendInsightAlert::dispatch($channel, $insight, $titleOverride);
            }

            // Mark as notified now regardless of whether it actually reached an EMAIL
            // channel — an insight parked outside the email allowlist is still "handled"
            // for this purpose (Vikunja tracks it independently of notified_at), and
            // leaving it unmarked would make DispatchSeoAlerts retry it forever without
            // ever delivering anything new.
            //
            // If the job fails and retries, it will still deliver to the channel (circuit
            // breaker may open), but we will not dispatch a second wave of channel jobs
            // for this insight on the next scheduler run.
            Insight::withoutGlobalScopes()
                ->where('id', $insight->id)
                ->update(['notified_at' => now()]);

            $dispatched++;
        }

        Log::info('SeoAlertService: alerts dispatched', [
            'team_id' => $team->id,
            'dispatched' => $dispatched,
            'channels' => $channels->count(),
        ]);

        return $dispatched;
    }

    /**
     * Whether this insight is allowed to reach an EMAIL channel.
     *
     * Two gates: the type must be in the configured allowlist, AND the insight must
     * not already carry an "already_notified_directly" flag — set by CreateUptimeInsight
     * on incidents that came from a FunctionalCheck (sitemap/redirect/content/robots.txt),
     * which already fired an immediate MonitorAlertMail when the incident opened. Without
     * this second gate, an allowlisted UPTIME_INCIDENT of that origin would be emailed
     * twice: once immediately, once again the next day by DispatchSeoAlerts.
     */
    private function isEmailAlertable(Insight $insight): bool
    {
        if (($insight->payload['already_notified_directly'] ?? false) === true) {
            return false;
        }

        // Guard: type is cast to InsightType by the model, but protect against
        // raw string values on rows created before the cast was in place.
        $typeEnum = $insight->type instanceof InsightType
            ? $insight->type
            : InsightType::tryFrom((string) $insight->type);

        if ($typeEnum === null) {
            return false;
        }

        $allowlist = config('monitoring.seo_alerts.email_alertable_types', []);

        return in_array($typeEnum->value, $allowlist, true);
    }
}
