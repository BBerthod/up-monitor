<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LinkMonitorsToSitesCommand extends Command
{
    protected $signature = 'sites:link-monitors
                            {--dry-run : Show what would be linked without persisting}
                            {--relink : Also re-evaluate monitors that already have a site_id}';

    protected $description = 'Link existing monitors to their Site by matching the monitor host against each site\'s resolved domains.';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $relink = (bool) $this->option('relink');

        if ($isDryRun) {
            $this->warn('Dry run — no changes persisted');
        }

        // ----------------------------------------------------------------
        // Multi-tenant strategy
        //
        // Both TeamScope (Monitor) and ScopedByTeam (Site) are auto-disabled
        // in CLI context (auth()->check() === false).  We therefore get ALL
        // records across every team.  To prevent cross-team assignments we
        // iterate per-team: for each team we only match monitors against sites
        // that belong to the same team.
        // ----------------------------------------------------------------
        $teams = Team::all();

        if ($teams->isEmpty()) {
            $this->warn('No teams found — nothing to process.');

            return self::SUCCESS;
        }

        $totalLinked = 0;
        $totalUnmatched = 0;
        $totalSkipped = 0;
        $tableRows = [];

        foreach ($teams as $team) {
            // Load all sites for this team (global scope is inactive in CLI).
            $sites = Site::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->get();

            if ($sites->isEmpty()) {
                continue;
            }

            // Pre-compute the set of normalised hosts for every site so we
            // do a single pass — O(sites) build, O(1) lookup per monitor.
            //
            // Structure: [ site_id => ['fr.examplestore.com', 'us.examplestore.com', ...] ]
            $siteHostSets = [];
            foreach ($sites as $site) {
                $siteHostSets[$site->id] = $this->siteHosts($site);
            }

            // Build reverse lookup: normalised-host → site_id.
            // If two sites share the same host (data error), the first wins and
            // a warning is emitted.
            $hostToSite = [];
            foreach ($siteHostSets as $siteId => $hosts) {
                foreach ($hosts as $host) {
                    if (isset($hostToSite[$host])) {
                        $existingSiteId = $hostToSite[$host];
                        Log::warning('sites:link-monitors — host collision', [
                            'host' => $host,
                            'site_ids' => [$existingSiteId, $siteId],
                            'team_id' => $team->id,
                        ]);
                        $this->warn(
                            "  [team {$team->id}] Host collision: \"{$host}\" claimed by site IDs {$existingSiteId} and {$siteId}. First wins."
                        );
                    } else {
                        $hostToSite[$host] = $siteId;
                    }
                }
            }

            // Fetch monitors for this team.
            $monitorQuery = Monitor::withoutGlobalScopes()
                ->where('team_id', $team->id);

            // Without --relink: only process monitors that have no site yet.
            if (! $relink) {
                $monitorQuery->whereNull('site_id');
            }

            $monitors = $monitorQuery->get();

            foreach ($monitors as $monitor) {
                $monitorHost = $this->extractHost($monitor->url);
                $matchedSite = null;
                $matchedAlias = '—';

                if ($monitorHost !== '') {
                    $matchedSiteId = $hostToSite[$monitorHost] ?? null;

                    if ($matchedSiteId !== null) {
                        $matchedSite = $sites->firstWhere('id', $matchedSiteId);
                        $matchedAlias = $matchedSite?->alias ?? "site #{$matchedSiteId}";
                    }
                }

                // Determine what action to take.
                $currentSiteId = $monitor->site_id;

                if ($matchedSite === null) {
                    // No match found.
                    $totalUnmatched++;
                    $status = 'no match';
                } elseif ($currentSiteId === $matchedSite->id) {
                    // Already pointing to the correct site — nothing to do.
                    $totalSkipped++;
                    $status = 'already linked';
                } else {
                    // New or corrected link.
                    if (! $isDryRun) {
                        $monitor->site_id = $matchedSite->id;
                        $monitor->saveQuietly();
                    }

                    $totalLinked++;
                    $status = $isDryRun ? 'would link' : 'linked';
                }

                $tableRows[] = [
                    $monitor->name,
                    $monitor->url,
                    $matchedAlias,
                    $status,
                ];
            }
        }

        // ----------------------------------------------------------------
        // Summary
        // ----------------------------------------------------------------
        $this->table(
            ['Monitor', 'URL', '→ Site', 'Status'],
            $tableRows
        );

        $verb = $isDryRun ? 'Would link' : 'Linked';
        $this->info("{$verb} {$totalLinked}, unmatched {$totalUnmatched}, skipped {$totalSkipped}.");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the full set of normalised hostnames that a Site "owns".
     *
     * Two cases are handled:
     *
     * 1. Simple domain — e.g. `domains = ['webcompare.fr']`
     *    → hosts: { 'webcompare.fr' }
     *
     * 2. Locale template — e.g. `domains = ['{locale}.examplestore.com']`
     *    with `locales = ['fr', 'us', 'uk', 'de', ...]`
     *    → each locale is substituted to produce one host per locale:
     *      { 'fr.examplestore.com', 'us.examplestore.com', 'uk.examplestore.com', 'de.examplestore.com', ... }
     *
     * Additionally, `primary_domain` is always included as a candidate (it may
     * already be covered by the expansion above, but a Set deduplicates).
     *
     * All hosts are lowercased and stripped of leading "www." so comparisons
     * are normalisation-safe.
     *
     * @return array<string> Unique, normalised hostnames.
     */
    private function siteHosts(Site $site): array
    {
        $hosts = [];
        $locales = (array) ($site->locales ?? []);
        $domains = (array) ($site->domains ?? []);

        foreach ($domains as $template) {
            $template = (string) $template;

            if (str_contains($template, '{locale}')) {
                // Expand the template for every configured locale.
                foreach ($locales as $locale) {
                    $hosts[] = $this->normaliseHost(
                        str_replace('{locale}', (string) $locale, $template)
                    );
                }
            } else {
                $hosts[] = $this->normaliseHost($template);
            }
        }

        // Always include primary_domain as a fallback candidate.
        if (! empty($site->primary_domain)) {
            $hosts[] = $this->normaliseHost($site->primary_domain);
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Extract and normalise the host from a monitor URL.
     *
     * Returns an empty string if the URL is empty or unparseable.
     */
    private function extractHost(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return '';
        }

        return $this->normaliseHost($host);
    }

    /**
     * Lowercase and strip a leading "www." from a hostname.
     */
    private function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
