<?php

namespace App\Services\Vikunja;

use App\Exceptions\VikunjaException;
use App\Models\Site;
use App\Models\VikunjaTaskLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the "projet: <title>" label a card gets in BOARD MODE, replacing
 * what used to be its routing project.
 *
 * WHY A LABEL AND NOT THE PROJECT ANYMORE
 * ────────────────────────────────────────
 * The Kanban migrated to a single shared project (see config/vikunja.php,
 * board_project_id). What used to route a card to its own project now decorates
 * it with a label instead, so the old per-site/per-server organisation survives
 * as metadata on a board that otherwise has none.
 *
 * MATCHING RULE — same spirit as SiteProjectMapper, one candidate wins
 * ──────────────────────────────────────────────────────────────────
 *  1. The site's OLD project title (sites.vikunja_project_id, pre-migration) —
 *     the most specific, human-picked source of truth, when it still resolves.
 *  2. The strict candidates SiteProjectMapper would have matched a project by:
 *     primary_domain, alias, domain-minus-TLD.
 *  3. For a server-scoped link: the fixed "Infrastructure" title.
 *
 * NEVER THROWS
 * ────────────
 * Vikunja is a convenience, not a dependency (see VikunjaTaskService). A card
 * missing its project label beats a card that never gets created — every
 * failure here is caught, logged, and resolved as "no label".
 */
class ProjectLabelResolver
{
    private const CACHE_TTL = 3600;

    public function __construct(private readonly VikunjaClient $client) {}

    /** The label id to attach to this link's card, or null if none could be resolved. */
    public function labelIdFor(VikunjaTaskLink $link): ?int
    {
        return $this->resolve($this->titleCandidatesFor($link), ['link_id' => $link->id]);
    }

    /**
     * Same resolution, keyed on a Site directly rather than a link — used by
     * SiteProjectMapper to decide whether a site is "mapped" in board mode
     * (a project label exists for it), without going through a link.
     */
    public function labelIdForSite(Site $site): ?int
    {
        return $this->resolve($this->siteTitleCandidates($site), ['site_id' => $site->id]);
    }

    /**
     * @param  array<int,string>  $candidates
     * @param  array<string,mixed>  $context
     */
    private function resolve(array $candidates, array $context): ?int
    {
        try {
            if ($candidates === []) {
                Log::warning('Vikunja: no project label candidate', $context);

                return null;
            }

            $prefix = (string) config('vikunja.project_label_prefix');
            $labels = $this->labels();

            foreach ($candidates as $candidate) {
                $wanted = mb_strtolower(trim($prefix.$candidate));

                foreach ($labels as $label) {
                    if (mb_strtolower(trim((string) ($label['title'] ?? ''))) === $wanted) {
                        return (int) $label['id'];
                    }
                }
            }

            Log::warning('Vikunja: no matching project label found', $context + ['candidates' => $candidates]);

            return null;
        } catch (VikunjaException $e) {
            Log::warning('Vikunja: project label resolution failed', $context + ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<int,string> Title candidates, most specific first. */
    private function titleCandidatesFor(VikunjaTaskLink $link): array
    {
        if ($link->server_id !== null) {
            return [(string) config('vikunja.server_project_title')];
        }

        $site = $link->site ?? $link->monitor?->site;

        return $site !== null ? $this->siteTitleCandidates($site) : [];
    }

    /** @return array<int,string> */
    private function siteTitleCandidates(Site $site): array
    {
        $candidates = [];

        $boardId = (int) config('vikunja.board_project_id');

        // The site's pre-migration project is the most specific source of truth,
        // when it is not itself the board (nothing to resolve in that case).
        if ($site->vikunja_project_id !== null && (int) $site->vikunja_project_id !== $boardId) {
            $title = $this->projectTitle((int) $site->vikunja_project_id);

            if ($title !== null) {
                $candidates[] = $title;
            }
        }

        foreach ([$site->primary_domain, $site->alias, $this->withoutTld((string) $site->primary_domain)] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $candidates[] = $candidate;
            }
        }

        return array_values(array_unique($candidates));
    }

    private function projectTitle(int $projectId): ?string
    {
        return Cache::remember(
            "vikunja:project:title:{$projectId}",
            self::CACHE_TTL,
            function () use ($projectId): ?string {
                $project = $this->client->project($projectId);

                return is_string($project['title'] ?? null) ? $project['title'] : null;
            },
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function labels(): array
    {
        return Cache::remember('vikunja:labels:all', self::CACHE_TTL, fn () => $this->client->labels());
    }

    private function withoutTld(string $domain): string
    {
        $parts = explode('.', $domain);

        return count($parts) > 1 ? implode('.', array_slice($parts, 0, -1)) : $domain;
    }
}
