<?php

namespace App\Services;

use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Team;
use App\Services\Ai\AiProvider;
use App\Services\Ai\NullProvider;
use Illuminate\Support\Facades\Log;

/**
 * Assembles and narrates the weekly AI digest for a team.
 *
 * Responsibility split:
 *   - All numeric facts come from existing services (HealthScoreService,
 *     WhatChangedService, WeeklyReportService) — the AI never invents figures.
 *   - AiProvider generates the narrative from those facts.
 *   - NullProvider is the fallback when AI is unavailable or throws.
 *
 * IMPORTANT — no auth() dependency: called from SendDigests job where auth()
 * is null and the ScopedByTeam global scope is inactive. team_id is always
 * supplied explicitly; Insight queries use withoutGlobalScopes().
 */
class DigestService
{
    public function __construct(
        private readonly HealthScoreService $healthScoreService,
        private readonly WhatChangedService $whatChangedService,
        private readonly WeeklyReportService $weeklyReportService,
        private readonly AiProvider $aiProvider,
        private readonly ActionPlanService $actionPlanService,
    ) {}

    /**
     * Generate the full weekly digest payload for a team.
     *
     * Returned array is passed directly to DigestMail and the Blade template.
     *
     * @return array{
     *   team_name: string,
     *   period_start: \Carbon\Carbon,
     *   period_end: \Carbon\Carbon,
     *   narrative: string,
     *   health: list<array>,
     *   what_changed: list<array>,
     *   top_opportunities: list<array>,
     *   server_health: list<array>,
     *   availability_alerts: list<array>,
     *   perf_alerts: list<array>,
     *   report: array,
     *   alerts: array{core_update_suspected: bool, ga4_broken_sites: list<string>},
     *   action_plan: array{actions: list<array>, total_actionable: int, generated_at: string},
     * }
     */
    public function generateForTeam(Team $team): array
    {
        // ── 1. Gather all factual data from existing services ──────────────────
        $health = $this->healthScoreService->scoreForTeam($team);

        // forTeam() also persists fresh WhatChanged insights — intentional:
        // the weekly digest is the designated trigger for this computation.
        $whatChanged = $this->whatChangedService->forTeam($team);

        $report = $this->weeklyReportService->generate($team);

        // ── 2. Prioritised action plan (top 5 for the mail) ───────────────────
        // Uses the same withoutGlobalScopes() pattern as the insight queries
        // below — no auth() dependency.
        $actionPlan = $this->actionPlanService->forTeam($team, 5);

        // ── 3. Top striking-distance SEO opportunities ─────────────────────────
        // withoutGlobalScopes() is required: ScopedByTeam needs auth() which is
        // null in a job context. We supply team_id explicitly as a safeguard.
        $topOpportunities = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->whereNull('acknowledged_at')
            ->notSnoozed()
            ->orderByDesc('impact_score')
            ->limit(5)
            ->get()
            ->map(fn ($i) => [
                'title' => $i->title,
                'estimated_gain' => (float) $i->impact_score,
                'query' => $i->payload['query'] ?? null,
                'site' => $i->site,
            ])
            ->all();

        // ── 3b. Unacknowledged server-health alerts ────────────────────────────
        // Surfaces servers under resource pressure (disk/RAM/CPU) so the digest
        // can flag infrastructure risk alongside SEO. withoutGlobalScopes() for
        // the same job-context reason as the queries above.
        $serverHealth = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->whereNull('acknowledged_at')
            ->notSnoozed()
            ->orderByDesc('impact_score')
            ->limit(5)
            ->get()
            ->map(fn ($i) => [
                'server' => $i->payload['server_name'] ?? $i->site,
                'metric' => $i->payload['metric'] ?? null,
                'severity' => $i->severity->value,
                'value' => $i->payload['value'] ?? null,
                'sites' => $i->payload['sites'] ?? [],
            ])
            ->all();

        // ── 3c. Availability alerts (SSL/domain expiry + open outages) ─────────
        // Groups SSL_EXPIRY, DOMAIN_EXPIRY, and UPTIME_INCIDENT so the digest
        // can surface certificate/domain risk and open downtime in one section.
        $availabilityAlerts = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('type', [
                InsightType::SSL_EXPIRY->value,
                InsightType::DOMAIN_EXPIRY->value,
                InsightType::UPTIME_INCIDENT->value,
            ])
            ->whereNull('acknowledged_at')
            ->notSnoozed()
            ->orderByDesc('severity')
            ->orderByDesc('impact_score')
            ->limit(5)
            ->get()
            ->map(fn ($i) => [
                'site' => $i->site,
                'title' => $i->title,
                'severity' => $i->severity->value,
                'days_remaining' => $i->payload['days_remaining'] ?? null,
            ])
            ->all();

        // ── 3d. Performance / health-grade regression alerts ──────────────────
        // Groups PERF_REGRESSION and HEALTH_DROP so the digest can flag speed
        // and composite-score degradations detected by the copilot detectors.
        $perfAlerts = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('type', [
                InsightType::PERF_REGRESSION->value,
                InsightType::HEALTH_DROP->value,
            ])
            ->whereNull('acknowledged_at')
            ->notSnoozed()
            ->orderByDesc('severity')
            ->orderByDesc('impact_score')
            ->limit(5)
            ->get()
            ->map(fn ($i) => [
                'site' => $i->site,
                'title' => $i->title,
                'severity' => $i->severity->value,
                'metric' => $i->payload['metric'] ?? null,
                'delta' => $i->payload['delta'] ?? null,
            ])
            ->all();

        // ── 4. Build the compact facts array for the AI ────────────────────────
        // Health sites are sorted ascending (worst first); best = last.
        $sites = $health['sites'];
        $facts = [
            'period' => [
                'start' => $report['period_start']->format('M j'),
                'end' => $report['period_end']->format('M j'),
            ],
            'portfolio' => [
                'sites_count' => count($sites),
                'overall_uptime' => $report['overall_uptime'],
                'incident_count' => $report['incident_count'],
                // Worst-scoring site is first (ASC sort), best is last.
                'best_site' => $sites ? end($sites)['name'] : null,
                'worst_site' => $sites[0]['name'] ?? null,
            ],
            'health_scores' => array_map(
                fn ($s) => [
                    'name' => $s['name'],
                    'score' => $s['score'],
                    'grade' => $s['grade'],
                    'trend' => $s['trend'],
                ],
                $sites
            ),
            // Cap at 8 changes to keep the AI prompt focused and token-lean.
            'what_changed' => array_map(
                fn ($c) => [
                    'title' => sprintf(
                        '%s on %s: %s%% (%s)',
                        $c['label'],
                        $c['site'],
                        round($c['delta_pct'], 1),
                        $c['direction']
                    ),
                ],
                array_slice($whatChanged['changes'], 0, 8)
            ),
            'top_opportunities' => $topOpportunities,
            // Server resource alerts — the AI mentions infrastructure risk when present.
            'server_health' => $serverHealth,
            // Availability alerts: expiring certificates/domains + open outages.
            'availability_alerts' => $availabilityAlerts,
            // Performance/health-grade regressions detected by the copilot.
            'perf_alerts' => $perfAlerts,
            'alerts' => [
                'core_update_suspected' => $whatChanged['core_update_suspected'],
                'ga4_broken_sites' => $whatChanged['ga4_broken_sites'],
            ],
            // Top 5 priority actions for AI narrative — keeps the model aware of
            // what concrete tasks exist without overwhelming the prompt.
            'priority_actions' => array_map(
                fn ($a) => ['action' => $a['action'], 'priority' => $a['priority_score']],
                array_slice($actionPlan['actions'], 0, 5)
            ),
        ];

        // ── 5. Narrate via AI, with deterministic fallback ─────────────────────
        $systemPrompt = 'You are an SEO copilot writing a concise weekly digest for a portfolio of websites. '
            .'Write 3-5 short factual bullet points in plain English. '
            .'CRITICAL: only use the numbers provided in the facts JSON — never invent or estimate any figure. '
            .'Highlight the most important wins, the biggest risks, and the top SEO opportunity. '
            .'If server_health is non-empty, add one bullet flagging the server resource risk and the sites it affects. '
            .'If availability_alerts is non-empty, add one bullet flagging any expiring certificates or domains (use days_remaining when provided) and any open outages. '
            .'If perf_alerts is non-empty, add one bullet flagging the performance or health-grade regressions and the affected sites. '
            .'Be direct and skimmable. No preamble, no markdown headers, just bullets.';

        try {
            $narrative = $this->aiProvider->narrate($systemPrompt, $facts);
        } catch (\Throwable $e) {
            // AI call failed — degrade to the deterministic NullProvider rather
            // than breaking the digest entirely for the team.
            Log::warning('DigestService: AI narration failed, using fallback', [
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);
            $narrative = app(NullProvider::class)->narrate($systemPrompt, $facts);
        }

        // ── 6. Return the full digest payload ─────────────────────────────────
        return [
            'team_name' => $team->name,
            'period_start' => $report['period_start'],   // Carbon — used for mail subject
            'period_end' => $report['period_end'],
            'narrative' => $narrative,
            'health' => $sites,
            'what_changed' => $whatChanged['changes'],
            'top_opportunities' => $topOpportunities,
            'server_health' => $serverHealth,          // servers under resource pressure
            'availability_alerts' => $availabilityAlerts, // SSL/domain expiry + open outages
            'perf_alerts' => $perfAlerts,              // performance/health regressions
            'report' => $report,                   // full uptime/incident data for tables
            'alerts' => $facts['alerts'],
            'action_plan' => $actionPlan,          // prioritised SEO to-do list
        ];
    }
}
