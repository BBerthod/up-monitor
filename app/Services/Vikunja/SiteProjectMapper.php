<?php

namespace App\Services\Vikunja;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Exceptions\VikunjaException;
use App\Models\Insight;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Matches a Site to its Vikunja project, and keeps the "unmapped site" alert
 * in sync with the outcome.
 *
 * Shared by `vikunja:doctor --map` (bulk, human-reviewed) and
 * MapSiteToVikunjaProject (one site, fired automatically on Site::created) so
 * the matching rule — and its safety margin — is defined exactly once.
 *
 * WHY THE MATCHING IS STRICT
 * ──────────────────────────
 * Exact title, or title equal to the domain minus its TLD — nothing fuzzier.
 * A looser match silently filed "radiank.com" under the sphere container
 * project "Radiank" before this rule was tightened (see
 * VikunjaDoctorCommandTest::test_mapping_prefers_the_site_project_over_the_sphere_container).
 * Do not relax it without re-reading that regression test.
 */
class SiteProjectMapper
{
    public function __construct(
        private readonly VikunjaClient $client,
        private readonly ProjectLabelResolver $projectLabels,
    ) {}

    /**
     * Fetch every project that can actually hold a task.
     *
     * Saved filters come back as projects with negative ids and must never be
     * offered as a routing target.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws VikunjaException
     */
    public function mappableProjects(): array
    {
        return array_values(array_filter(
            $this->client->projects(),
            fn (array $p): bool => (int) $p['id'] > 0,
        ));
    }

    /**
     * Match a site to a project by name.
     *
     * Candidates are tried BEFORE projects: the most specific candidate must
     * fail against every project before a looser one is considered. Trying
     * projects first would let a loosely-named container project win just
     * because it was listed earlier.
     *
     * @param  array<int,array<string,mixed>>  $projects
     * @return array<string,mixed>|null The matched project, or null.
     */
    public function matchProjectFor(Site $site, array $projects): ?array
    {
        $candidates = array_filter(
            [$site->primary_domain, $site->alias, $this->withoutTld((string) $site->primary_domain)],
            fn (?string $c): bool => is_string($c) && trim($c) !== '',
        );

        foreach ($candidates as $candidate) {
            foreach ($projects as $project) {
                if (mb_strtolower(trim((string) $project['title'])) === mb_strtolower(trim($candidate))) {
                    return $project;
                }
            }
        }

        return null;
    }

    /**
     * Match and, on success, persist the mapping for one site.
     *
     * On a match: sets sites.vikunja_project_id and acknowledges any open
     * VIKUNJA_UNMAPPED_SITE insight for it — the gap this call just closed.
     * On no match: raises (or refreshes) that insight so an unmapped site is
     * visible on the board instead of silently draining into the fallback
     * Inbox.
     *
     * Never throws — Vikunja is a convenience, never a dependency. A failure
     * here must not abort site creation or a scheduled sweep; it is logged and
     * the site stays unmapped for the next attempt.
     *
     * @param  array<int,array<string,mixed>>|null  $projects  Reuse an already
     *                                                         fetched list (bulk callers); fetched fresh when null.
     * @return array<string,mixed>|null The matched project, or null.
     */
    public function mapOne(Site $site, ?array $projects = null): ?array
    {
        try {
            if ($this->isBoardMode()) {
                return $this->mapOneInBoardMode($site);
            }

            $projects ??= $this->mappableProjects();
            $match = $this->matchProjectFor($site, $projects);

            if ($match !== null) {
                $this->applyMatch($site, $match);

                return $match;
            }

            $this->raiseUnmappedInsight($site);

            return null;
        } catch (Throwable $e) {
            Log::warning('SiteProjectMapper: could not map site to a Vikunja project', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Persist a match found elsewhere (e.g. the doctor command's own preview
     * loop) and resolve the VIKUNJA_UNMAPPED_SITE insight it closes, if any.
     *
     * @param  array<string,mixed>  $match
     */
    public function applyMatch(Site $site, array $match): void
    {
        $site->update(['vikunja_project_id' => (int) $match['id']]);
        $this->resolveUnmappedInsight($site);
    }

    /**
     * Board-mode equivalent of mapOne(): a site is "mapped" once a "projet: …"
     * label exists for it (see ProjectLabelResolver) — there is no project left
     * to route it to, every card lands in the single board project. This still
     * records vikunja_project_id = board id so downstream code (createCard,
     * the doctor's board-layout check…) has one thing to read regardless of mode.
     *
     * @return array<string,mixed>|null Null — kept for API parity with mapOne();
     *                                  board mode has no "matched project" to return.
     */
    private function mapOneInBoardMode(Site $site): ?array
    {
        $boardId = (int) config('vikunja.board_project_id');

        if ($this->projectLabels->labelIdForSite($site) !== null) {
            if ((int) $site->vikunja_project_id !== $boardId) {
                $site->update(['vikunja_project_id' => $boardId]);
            }

            $this->resolveUnmappedInsight($site);

            return null;
        }

        $this->raiseUnmappedInsight($site, board: true);

        return null;
    }

    private function isBoardMode(): bool
    {
        return (int) config('vikunja.board_project_id') > 0;
    }

    /**
     * Raise a VIKUNJA_UNMAPPED_SITE insight so the gap is visible on the board.
     *
     * Idempotent: skips creation if an unacknowledged one already exists for
     * this site — a daily sweep re-attempting every unmapped site must not
     * pile up a new card each time it still finds no match.
     */
    public function raiseUnmappedInsight(Site $site, bool $board = false): void
    {
        $exists = Insight::withoutGlobalScopes()
            ->where('type', InsightType::VIKUNJA_UNMAPPED_SITE->value)
            ->where('payload->site_id', $site->id)
            ->whereNull('acknowledged_at')
            ->exists();

        if ($exists) {
            return;
        }

        $domain = $site->primary_domain ?: $site->alias ?: "site #{$site->id}";

        $title = $board
            ? "Aucune étiquette projet Vikunja pour {$domain}"
            : "Aucun projet Vikunja pour {$domain} — ses alertes tombent dans l'Inbox";

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'type' => InsightType::VIKUNJA_UNMAPPED_SITE->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'title' => $title,
            'payload' => [
                'site_id' => $site->id,
                'primary_domain' => $site->primary_domain,
                'alias' => $site->alias,
            ],
            'impact_score' => 30,
            'detected_at' => now(),
        ]);
    }

    /**
     * Acknowledge the open VIKUNJA_UNMAPPED_SITE insight for a site, if any —
     * mirrors NotificationService::resolveWarmingDisabled()'s pattern for the
     * same reason: the gap it reported no longer exists.
     */
    private function resolveUnmappedInsight(Site $site): void
    {
        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::VIKUNJA_UNMAPPED_SITE->value)
            ->where('payload->site_id', $site->id)
            ->whereNull('acknowledged_at')
            ->first();

        $insight?->acknowledge();
    }

    private function withoutTld(string $domain): string
    {
        $parts = explode('.', $domain);

        return count($parts) > 1 ? implode('.', array_slice($parts, 0, -1)) : $domain;
    }
}
