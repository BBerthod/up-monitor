<?php

namespace App\Services\Vikunja;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Exceptions\VikunjaException;
use App\Models\Insight;
use App\Models\Team;
use App\Models\VikunjaTaskLink;
use App\Services\FixPromptService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Turns persistent monitoring problems into Kanban cards, and keeps both sides
 * in agreement afterwards.
 *
 * THE GUIDING RULE
 * ────────────────
 * Up stays the inbox; Vikunja receives WORK. An insight that resolves itself
 * within a few hours must never reach the board — otherwise the Kanban degrades
 * into an alert dump, which is exactly the noise problem the insight pipeline
 * already had to be cured of once.
 *
 * That is why promotion is driven by PERSISTENCE, not by detection: a problem
 * earns a card by still being there hours later. Transient signals die on their
 * own and cost nothing; only what lasts becomes work.
 *
 * THREE PHASES, RUN IN ORDER
 * ──────────────────────────
 *   observe()    — every live insight refreshes (or opens) its link. This is the
 *                  only place first_seen_at is set, and the only reason the link
 *                  table exists: insights are deleted and recreated on every
 *                  detector run, so they cannot remember their own age.
 *   promote()    — links old enough (and severe enough) get a card.
 *   reconcile()  — cards get closed when the problem goes away, and insights get
 *                  acknowledged when a human closes the card.
 *
 * FAILURE POLICY
 * ──────────────
 * Vikunja is a convenience, never a dependency. Every API failure is caught and
 * logged; the sweep returns in fifteen minutes. A dead board delays a card, it
 * never fails an insight run and never loses an alert.
 */
class VikunjaTaskService
{
    public function __construct(
        private readonly VikunjaClient $client,
        private readonly FixPromptService $fixPrompts,
        private readonly ProjectLabelResolver $projectLabels,
    ) {}

    // -------------------------------------------------------------------------
    // Phase 1 — observe
    // -------------------------------------------------------------------------

    /**
     * Refresh the link table from the team's live insights.
     *
     * Acknowledged and snoozed insights are excluded: they have been dealt with
     * inside Up, and a card would just re-raise something a human already
     * dismissed.
     *
     * @return int Number of links touched.
     */
    public function observe(Team $team): int
    {
        $insights = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNull('acknowledged_at')
            ->notSnoozed()
            ->get();

        $touched = 0;

        foreach ($insights as $insight) {
            try {
                $this->observeOne($team, $insight);
                $touched++;
            } catch (\Throwable $e) {
                Log::error('Vikunja observe failed for insight', [
                    'insight_id' => $insight->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue — one bad insight must not abort the sweep.
            }
        }

        return $touched;
    }

    private function observeOne(Team $team, Insight $insight): void
    {
        $key = VikunjaTaskLink::scopeKeyFor($insight);
        $now = now();

        $link = VikunjaTaskLink::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('scope_key', $key)
            ->first();

        $attributes = [
            'insight_type' => $insight->type,
            'site_id' => $insight->site_id,
            'server_id' => $insight->server_id,
            'monitor_id' => $insight->monitor_id,
            'insight_id' => $insight->id,
            'title' => mb_substr((string) $insight->title, 0, 500),
            'severity' => $insight->severity,
            'last_seen_at' => $now,
        ];

        if ($link === null) {
            VikunjaTaskLink::withoutGlobalScopes()->create($attributes + [
                'team_id' => $team->id,
                'scope_key' => $key,
                'first_seen_at' => $now,
            ]);

            return;
        }

        // A closed link means this problem already had its card. Coming back is a
        // RECURRENCE, and it starts a fresh observation window — but only after a
        // cooldown, otherwise a card closed this morning is recreated this
        // afternoon and the board flaps.
        if ($link->closed_at !== null) {
            $cooldown = (int) config('vikunja.reconcile.reopen_cooldown_hours', 24);

            if ($link->closed_at->addHours($cooldown)->isFuture()) {
                $link->update(['last_seen_at' => $now]);

                return;
            }

            $link->update($attributes + [
                'first_seen_at' => $now,
                'vikunja_task_id' => null,
                'vikunja_project_id' => null,
                'promoted_at' => null,
                'closed_at' => null,
                'close_reason' => null,
            ]);

            return;
        }

        // Still open: refresh what we know, but never touch first_seen_at — that
        // timestamp is the whole point of this table.
        $link->update($attributes);
    }

    // -------------------------------------------------------------------------
    // Phase 2 — promote
    // -------------------------------------------------------------------------

    /**
     * Create cards for links that have earned one.
     *
     * @return int Number of cards created.
     */
    public function promote(Team $team): int
    {
        $candidates = VikunjaTaskLink::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->pending()
            ->orderBy('first_seen_at')
            ->get()
            ->filter(fn (VikunjaTaskLink $link): bool => $this->isEligible($link));

        // Flood guard: a wildcard certificate expiring, or a host going down,
        // lights up every site at once. The overflow is not lost — the oldest
        // sightings go first and the rest follow on the next sweep.
        $cap = (int) config('vikunja.promotion.max_per_run', 5);
        $created = 0;

        foreach ($candidates->take($cap) as $link) {
            try {
                $this->createCard($link);
                $created++;
            } catch (VikunjaException $e) {
                Log::warning('Vikunja promotion failed', [
                    'link_id' => $link->id,
                    'scope_key' => $link->scope_key,
                    'error' => $e->getMessage(),
                ]);
                // Left pending on purpose: the next sweep retries it.
            }
        }

        if ($candidates->count() > $cap) {
            Log::info('Vikunja promotion capped', [
                'team_id' => $team->id,
                'eligible' => $candidates->count(),
                'promoted' => $created,
                'deferred' => $candidates->count() - $cap,
            ]);
        }

        return $created;
    }

    /**
     * Does this problem deserve a card yet?
     *
     * Three gates, in order: type veto, severity, then age.
     */
    public function isEligible(VikunjaTaskLink $link): bool
    {
        $type = $link->insight_type;
        $typeValue = $type instanceof InsightType ? $type->value : (string) $type;

        if (in_array($typeValue, (array) config('vikunja.promotion.never_types', []), true)) {
            return false;
        }

        // Severity is the FIRST gate, and nothing bypasses it: "WARNING is never
        // promoted automatically" has to hold for every type, or the rule is not a
        // rule. Deadline types are exempt from the *delay*, not from the threshold.
        $severity = $link->severity instanceof InsightSeverity
            ? $link->severity->value
            : (string) $link->severity;

        if (! in_array($severity, (array) config('vikunja.promotion.severities', ['critical']), true)) {
            return false;
        }

        // Deadline-bearing problems never resolve themselves, so waiting out a
        // persistence window would only shorten the runway to act. They still had
        // to clear the severity gate above — and they do so on their own schedule:
        // SslExpiryDetector only raises CRITICAL at 3 days remaining, WARNING at 14,
        // so an urgent certificate is promoted at once while a distant one waits for
        // someone to decide it is work.
        if (in_array($typeValue, (array) config('vikunja.promotion.immediate_types', []), true)) {
            return true;
        }

        $threshold = in_array($typeValue, (array) config('vikunja.promotion.transient_types', []), true)
            ? (int) config('vikunja.promotion.transient_persistence_hours', 24)
            : (int) config('vikunja.promotion.persistence_hours', 6);

        return $link->ageInHours() >= $threshold;
    }

    /**
     * Promote one problem on demand, bypassing the persistence gate.
     *
     * This is the "→ Vikunja" button: a human looking at an insight has already
     * decided it is work, so no threshold applies. Idempotent — clicking twice
     * returns the existing link instead of creating a second card.
     */
    public function promoteManually(Insight $insight): VikunjaTaskLink
    {
        $team = Team::findOrFail($insight->team_id);

        $this->observeOne($team, $insight);

        $link = VikunjaTaskLink::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('scope_key', VikunjaTaskLink::scopeKeyFor($insight))
            ->firstOrFail();

        if (! $link->isPromoted()) {
            $this->createCard($link);
        }

        return $link->refresh();
    }

    private function createCard(VikunjaTaskLink $link): void
    {
        $projectId = $this->projectIdFor($link);

        $task = $this->client->createTask($projectId, [
            'title' => $this->cardTitle($link),
            'description' => $this->cardDescription($link),
            'priority' => $this->cardPriority($link),
        ] + $this->dueDatePayload($link));

        $taskId = (int) ($task['id'] ?? 0);

        if ($taskId === 0) {
            throw new VikunjaException('Vikunja returned a task without an id');
        }

        $link->update([
            'vikunja_task_id' => $taskId,
            'vikunja_project_id' => $projectId,
            'promoted_at' => now(),
        ]);

        // Best-effort decoration: the card already exists and is linked, so a
        // failure here must not roll anything back or retry the creation.
        $this->decorate($link, $taskId, $projectId);
    }

    /**
     * Label the card and move it into the working column.
     *
     * Cards carry a ready-to-run fix prompt, so they are addressed to a Claude
     * session and land in "À faire" rather than the untriaged default column.
     *
     * In board mode, two more labels stand in for what a dedicated project used
     * to convey: the fixed sphere label (every card Up creates is the same
     * sphere) and a "projet: …" label resolved by ProjectLabelResolver. Either
     * is skipped, without failing the card, when it cannot be resolved.
     */
    private function decorate(VikunjaTaskLink $link, int $taskId, int $projectId): void
    {
        try {
            $labelId = (int) config('vikunja.labels.claude');

            if ($labelId > 0) {
                $this->client->addLabel($taskId, $labelId);
            }

            if ($this->isBoardMode()) {
                $sphereLabelId = (int) config('vikunja.labels.sphere');

                if ($sphereLabelId > 0) {
                    $this->client->addLabel($taskId, $sphereLabelId);
                }

                $projectLabelId = $this->projectLabels->labelIdFor($link);

                if ($projectLabelId !== null) {
                    $this->client->addLabel($taskId, $projectLabelId);
                }
            }

            $this->client->moveToBucket($projectId, $taskId, (string) config('vikunja.buckets.todo'));
        } catch (VikunjaException $e) {
            Log::warning('Vikunja card created but not fully decorated', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Phase 3 — reconcile
    // -------------------------------------------------------------------------

    /**
     * Keep both sides in agreement.
     *
     *   card deleted by hand   → close the link, stop polling a ghost
     *   card marked done       → acknowledge the insight, so Up's badge stops lying
     *   problem gone from Up   → close the card, with a comment saying why
     *
     * @return array{closed:int,acknowledged:int}
     */
    public function reconcile(Team $team): array
    {
        $links = VikunjaTaskLink::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->promoted()
            ->get();

        $closed = 0;
        $acknowledged = 0;

        foreach ($links as $link) {
            try {
                $outcome = $this->reconcileOne($link);
                $closed += $outcome['closed'];
                $acknowledged += $outcome['acknowledged'];
            } catch (VikunjaException $e) {
                Log::warning('Vikunja reconciliation failed', [
                    'link_id' => $link->id,
                    'task_id' => $link->vikunja_task_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['closed' => $closed, 'acknowledged' => $acknowledged];
    }

    /** @return array{closed:int,acknowledged:int} */
    private function reconcileOne(VikunjaTaskLink $link): array
    {
        $task = $this->client->getTask((int) $link->vikunja_task_id);

        // Deleted on the board: nothing left to synchronise.
        if ($task === null) {
            $link->update(['closed_at' => now(), 'close_reason' => 'abandoned']);

            return ['closed' => 1, 'acknowledged' => 0];
        }

        // Someone did the work. Acknowledge the insight so Up's triage badge
        // agrees with the board instead of showing a problem already handled.
        if (($task['done'] ?? false) === true) {
            $acknowledged = 0;

            $insight = $link->insight_id !== null
                ? Insight::withoutGlobalScopes()->find($link->insight_id)
                : null;

            if ($insight !== null && $insight->acknowledged_at === null) {
                $insight->update(['acknowledged_at' => now()]);
                $acknowledged = 1;
            }

            $link->update(['closed_at' => now(), 'close_reason' => 'done_in_vikunja']);

            return ['closed' => 1, 'acknowledged' => $acknowledged];
        }

        // Still open on the board — is the problem still real?
        $staleAfter = (int) config('vikunja.reconcile.resolved_after_hours', 2);
        $problemGone = $link->last_seen_at->addHours($staleAfter)->isPast();

        if (! $problemGone) {
            return ['closed' => 0, 'acknowledged' => 0];
        }

        return ['closed' => $this->closeResolved($link), 'acknowledged' => 0];
    }

    /**
     * Close a card whose problem has disappeared.
     *
     * Honours the board's locking convention: a card sitting in "En cours" belongs
     * to whoever moved it there. Up says its piece in a comment and leaves the
     * card alone — deciding that the work is finished is the holder's call, not a
     * sweep's.
     *
     * @return int 1 if the card was closed, 0 if it was only commented.
     */
    private function closeResolved(VikunjaTaskLink $link): int
    {
        $taskId = (int) $link->vikunja_task_id;
        $projectId = $this->currentProjectIdFor($link);

        $column = $this->client->bucketTitleOfTask($projectId, $taskId);
        $locked = $column === (string) config('vikunja.buckets.in_progress');

        $since = $link->last_seen_at->diffForHumans();

        if ($locked) {
            $this->client->comment(
                $taskId,
                "Up ne détecte plus ce problème (dernière occurrence {$since}). "
                .'La carte est en « En cours » : elle reste ouverte, à toi de la clore.',
            );

            // Not closed, but no longer worth re-commenting on every sweep.
            $link->update(['closed_at' => now(), 'close_reason' => 'resolved']);

            return 0;
        }

        // Close FIRST, then announce. The reverse order turns any failure of
        // markDone into an unbounded comment loop: the comment lands, the close
        // throws, closed_at is never written, and the next sweep comments again.
        // That is exactly what a 400 from the API produced — 50 identical
        // "Résolu" comments on a single card, one every 15 minutes.
        $this->client->markDone($taskId);

        $link->update(['closed_at' => now(), 'close_reason' => 'resolved']);

        $this->client->comment(
            $taskId,
            "Résolu : Up ne détecte plus ce problème (dernière occurrence {$since}). Carte fermée automatiquement.",
        );

        return 1;
    }

    // -------------------------------------------------------------------------
    // Card content
    // -------------------------------------------------------------------------

    private function projectIdFor(VikunjaTaskLink $link): int
    {
        if ($this->isBoardMode()) {
            return (int) config('vikunja.board_project_id');
        }

        // Server problems belong to the machine's project, not to any one of the
        // sites it hosts — a dead host must produce one card, not eight.
        $projectId = $link->server?->vikunja_project_id
            ?? $link->site?->vikunja_project_id
            ?? $link->monitor?->site?->vikunja_project_id;

        return (int) ($projectId ?: config('vikunja.fallback_project_id', 1));
    }

    /**
     * The project a link's card is actually reachable in RIGHT NOW.
     *
     * NOT the same as `$link->vikunja_project_id`: a link created before the
     * board-mode migration still stores the id of its old, now-archived
     * project. In board mode every card lives in the single board project
     * regardless of what a stale link record says — using the stored id here
     * would make bucketTitleOfTask() look in a project the card no longer
     * lives in, get back nothing, read that as "not locked", and let a sweep
     * force-close a card someone is actively working on.
     */
    private function currentProjectIdFor(VikunjaTaskLink $link): int
    {
        return $this->isBoardMode()
            ? (int) config('vikunja.board_project_id')
            : (int) $link->vikunja_project_id;
    }

    private function isBoardMode(): bool
    {
        return (int) config('vikunja.board_project_id') > 0;
    }

    private function cardTitle(VikunjaTaskLink $link): string
    {
        // Site::$name is an accessor returning `alias`, which is nullable — hence
        // the explicit fallback to primary_domain, the only label always present.
        $subject = $link->site?->alias
            ?: $link->site?->primary_domain
            ?: $link->server?->name
            ?: $link->monitor?->name
            ?: null;

        $title = (string) $link->title;

        return $subject !== null ? "[{$subject}] {$title}" : $title;
    }

    /**
     * Vikunja priorities run 1 (low) to 5 (DO NOW). CRITICAL maps to 4 (urgent)
     * rather than 5: 5 is reserved for what a human declares an emergency, and an
     * automated feed that shouts the loudest possible priority stops being read.
     */
    private function cardPriority(VikunjaTaskLink $link): int
    {
        $severity = $link->severity instanceof InsightSeverity
            ? $link->severity
            : InsightSeverity::tryFrom((string) $link->severity);

        return match ($severity) {
            InsightSeverity::CRITICAL => 4,
            InsightSeverity::WARNING => 3,
            default => 2,
        };
    }

    /**
     * Give the card a real deadline when the problem has one.
     *
     * Only expiry-type insights carry a genuine date; inventing due dates for
     * everything else would make the board's "En retard" filter meaningless.
     *
     * @return array<string,string>
     */
    private function dueDatePayload(VikunjaTaskLink $link): array
    {
        $payload = $link->insight?->payload ?? [];

        foreach (['expires_at', 'expiry_date', 'valid_until'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                continue;
            }

            try {
                return ['due_date' => Carbon::parse($payload[$field])->toIso8601ZuluString()];
            } catch (\Throwable) {
                // Unparseable date: a card without a due date beats a failed creation.
            }
        }

        return [];
    }

    /**
     * Build the card body.
     *
     * Vikunja stores descriptions as HTML (TipTap), so the fix prompt goes inside
     * a <pre> block — markdown fences would render as literal backticks and, worse,
     * the prompt would lose its line breaks and stop being copy-pasteable, which is
     * the entire value of the card.
     */
    private function cardDescription(VikunjaTaskLink $link): string
    {
        $type = $link->insight_type instanceof InsightType
            ? $link->insight_type->label()
            : (string) $link->insight_type;

        $severity = $link->severity instanceof InsightSeverity
            ? $link->severity->label()
            : (string) $link->severity;

        $age = (int) round($link->ageInHours());

        $parts = [];
        $parts[] = '<p><strong>Détecté par Up</strong> — '.e($type).' · '.e($severity)
            .' · observé depuis '.$age.' h.</p>';

        $insight = $link->insight;

        if ($insight !== null) {
            $prompt = $this->fixPrompts->forInsight($insight);
            $parts[] = '<p><strong>Prompt de correction</strong> — à coller dans Claude Code :</p>';
            $parts[] = '<pre>'.e($prompt).'</pre>';
        }

        $parts[] = '<p><em>Carte créée et suivie automatiquement par Up. '
            .'Elle se fermera seule si le problème disparaît.</em></p>';

        return implode("\n", $parts);
    }
}
