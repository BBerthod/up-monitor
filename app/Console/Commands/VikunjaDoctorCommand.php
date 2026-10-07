<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\VikunjaException;
use App\Models\Server;
use App\Models\Site;
use App\Models\VikunjaTaskLink;
use App\Services\Vikunja\ProjectLabelResolver;
use App\Services\Vikunja\SiteProjectMapper;
use App\Services\Vikunja\VikunjaClient;
use Illuminate\Console\Command;

/**
 * Inspects the Vikunja integration end to end, and maps sites to board projects.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Every id this integration relies on lives in another system: project ids,
 * label ids, column names. None of them can be validated by a test suite, and a
 * wrong one fails silently — cards pile up in the fallback Inbox, or the @claude
 * label is never attached, and nobody notices until someone opens the board.
 *
 * `vikunja:doctor` is the one command that answers "is this actually wired up?"
 * against the live instance.
 *
 * `--map` fills sites.vikunja_project_id by matching a site's domain or alias to
 * a project title. It is a DRY RUN unless --apply is passed: guessing at the
 * routing of every future card is not something to do blind.
 */
class VikunjaDoctorCommand extends Command
{
    protected $signature = 'vikunja:doctor
                            {--map : Propose a site → project mapping by name}
                            {--apply : Persist the proposed mapping (implies --map)}';

    protected $description = 'Check the Vikunja integration and map sites to board projects';

    public function handle(VikunjaClient $client, SiteProjectMapper $mapper, ProjectLabelResolver $projectLabels): int
    {
        if (! $client->isConfigured()) {
            $this->error('Vikunja is disabled or has no token.');
            $this->line('Set VIKUNJA_ENABLED=true and VIKUNJA_API_TOKEN in the environment.');

            return self::FAILURE;
        }

        try {
            $projects = $client->projects();
        } catch (VikunjaException $e) {
            $this->error('Cannot reach Vikunja: '.$e->getMessage());

            return self::FAILURE;
        }

        // Saved filters come back as projects with negative ids — they cannot
        // hold tasks, so they must never be offered as a routing target.
        $projects = array_values(array_filter($projects, fn (array $p): bool => (int) $p['id'] > 0));

        $this->info('Connected to '.config('vikunja.base_url').' — '.count($projects).' projects.');
        $this->newLine();

        $boardMode = (int) config('vikunja.board_project_id') > 0;

        if ($boardMode) {
            $this->reportBoardMode($client, $projectLabels);
        } else {
            $this->checkBoardLayout($client, $projects);
            $this->reportMapping($projects);
        }

        $this->reportLinks();

        // In board mode there is no project to route a site to anymore — the
        // "Site → project label" table above already reports what --map would;
        // persisting it happens automatically the moment a card is created
        // (VikunjaTaskService::projectIdFor) or a site is created
        // (MapSiteToVikunjaProject), so there is nothing left for --map to do.
        if (! $boardMode && ($this->option('map') || $this->option('apply'))) {
            $this->mapSites($mapper, $projects);
        }

        return self::SUCCESS;
    }

    /**
     * Board-mode report: everything now routes to one project, so what is
     * worth checking is narrower — the board itself, the sphere label, and
     * whether each site still resolves a "projet: …" label to decorate its
     * cards with.
     */
    private function reportBoardMode(VikunjaClient $client, ProjectLabelResolver $projectLabels): void
    {
        $boardId = (int) config('vikunja.board_project_id');
        $sphereLabelId = (int) config('vikunja.labels.sphere');

        $this->line('<comment>Board mode</comment>');
        $this->line("  board project id : {$boardId}");
        $this->line('  sphere label id  : '.($sphereLabelId > 0 ? $sphereLabelId : '<fg=yellow>not set</>'));

        try {
            $viewId = $client->kanbanViewId($boardId);
            $bucketId = $viewId !== null ? $client->bucketId($boardId, (string) config('vikunja.buckets.todo')) : null;
            $this->line('  todo column      : '.match (true) {
                $viewId === null => '<fg=red>no Kanban view</>',
                $bucketId === null => '<fg=red>no « '.config('vikunja.buckets.todo').' » column</>',
                default => "ok (bucket {$bucketId})",
            });
        } catch (VikunjaException $e) {
            $this->line('  todo column      : <fg=red>ERROR: '.mb_substr($e->getMessage(), 0, 60).'</>');
        }

        $this->newLine();

        $rows = [];

        foreach (Site::query()->where('is_active', true)->orderBy('primary_domain')->get() as $site) {
            $labelId = $projectLabels->labelIdForSite($site);
            $rows[] = [$site->primary_domain, $labelId !== null ? (string) $labelId : '<fg=yellow>aucune</>'];
        }

        $this->line('<comment>Site → project label</comment>');
        $this->table(['site', 'label id'], $rows);
        $this->newLine();
    }

    /**
     * Verify that the configured columns exist on the projects we actually use.
     *
     * A missing "À faire" is not fatal — the card still lands in the project's
     * default column — but it means every card arrives untriaged, which is worth
     * knowing before it happens fifty times.
     *
     * @param  array<int,array<string,mixed>>  $projects
     */
    private function checkBoardLayout(VikunjaClient $client, array $projects): void
    {
        $targets = Site::query()->whereNotNull('vikunja_project_id')->pluck('vikunja_project_id')
            ->merge(Server::query()->whereNotNull('vikunja_project_id')->pluck('vikunja_project_id'))
            ->push((int) config('vikunja.fallback_project_id'))
            ->unique()
            ->values();

        if ($targets->isEmpty()) {
            return;
        }

        $titles = collect($projects)->keyBy('id');
        $todo = (string) config('vikunja.buckets.todo');
        $rows = [];

        foreach ($targets as $projectId) {
            $projectId = (int) $projectId;
            $name = $titles[$projectId]['title'] ?? '(unknown project)';

            try {
                $viewId = $client->kanbanViewId($projectId);
                $bucketId = $viewId !== null ? $client->bucketId($projectId, $todo) : null;
            } catch (VikunjaException $e) {
                $rows[] = [$projectId, $name, 'ERROR: '.mb_substr($e->getMessage(), 0, 40)];

                continue;
            }

            $rows[] = [
                $projectId,
                $name,
                match (true) {
                    $viewId === null => 'no Kanban view',
                    $bucketId === null => "no « {$todo} » column",
                    default => "ok (bucket {$bucketId})",
                },
            ];
        }

        $this->line('<comment>Board layout of target projects</comment>');
        $this->table(['project', 'title', 'column « '.$todo.' »'], $rows);
        $this->newLine();
    }

    /** @param array<int,array<string,mixed>> $projects */
    private function reportMapping(array $projects): void
    {
        $titles = collect($projects)->keyBy('id');
        $sites = Site::query()->where('is_active', true)->orderBy('primary_domain')->get();
        $unmapped = 0;
        $rows = [];

        foreach ($sites as $site) {
            $mapped = $site->vikunja_project_id !== null;
            $unmapped += $mapped ? 0 : 1;

            $rows[] = [
                $site->primary_domain,
                $mapped
                    ? $site->vikunja_project_id.' — '.($titles[$site->vikunja_project_id]['title'] ?? '⚠ project gone')
                    : '<fg=yellow>fallback Inbox</>',
            ];
        }

        $this->line('<comment>Site → project routing</comment>');
        $this->table(['site', 'vikunja project'], $rows);

        if ($unmapped > 0) {
            $this->warn("{$unmapped} site(s) unmapped — their cards land in the fallback project. Run with --map.");
        }

        $this->newLine();
    }

    private function reportLinks(): void
    {
        $open = VikunjaTaskLink::query()->open()->count();
        $promoted = VikunjaTaskLink::query()->promoted()->count();
        $closed = VikunjaTaskLink::query()->whereNotNull('closed_at')->count();

        $this->line('<comment>Links</comment>');
        $this->line('  observed, no card yet : '.($open - $promoted));
        $this->line("  card on the board     : {$promoted}");
        $this->line("  closed                : {$closed}");
        $this->newLine();
    }

    /**
     * Match sites to projects by name.
     *
     * Matching is deliberately conservative — exact title, or title equal to the
     * domain minus its TLD. A fuzzy match that silently files webcompare.fr under
     * webcompare.com would be worse than no mapping at all, since the fallback
     * Inbox at least makes the omission visible. The rule itself lives in
     * SiteProjectMapper, shared with the automatic mapping fired on
     * Site::created (see MapSiteToVikunjaProject).
     *
     * On --apply, a site that still has no match raises a VIKUNJA_UNMAPPED_SITE
     * insight — a dry run never does, since it must not have side effects.
     *
     * @param  array<int,array<string,mixed>>  $projects
     */
    private function mapSites(SiteProjectMapper $mapper, array $projects): void
    {
        $apply = (bool) $this->option('apply');
        $rows = [];
        $matched = 0;

        foreach (Site::query()->whereNull('vikunja_project_id')->get() as $site) {
            $match = $mapper->matchProjectFor($site, $projects);

            if ($match === null) {
                $rows[] = [$site->primary_domain, '<fg=yellow>no match</>'];

                if ($apply) {
                    $mapper->raiseUnmappedInsight($site);
                }

                continue;
            }

            $matched++;
            $rows[] = [$site->primary_domain, $match['id'].' — '.$match['title']];

            if ($apply) {
                $mapper->applyMatch($site, $match);
            }
        }

        $this->line('<comment>Proposed mapping</comment>');
        $this->table(['site', 'project'], $rows);

        $this->line($apply
            ? "<info>{$matched} site(s) mapped.</info>"
            : "<comment>Dry run — {$matched} site(s) would be mapped. Re-run with --apply.</comment>");
    }
}
