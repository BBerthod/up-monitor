<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class ImportSitesCommand extends Command
{
    protected $signature = 'sites:import
                            {path : Path to a sites YAML file}
                            {--organization=Radiank : Only import sites from this organization}
                            {--team= : Team ID to attach sites to (default: first team)}';

    protected $description = 'Import or update Site records from a YAML configuration file.';

    public function handle(): int
    {
        $filePath = $this->argument('path');
        $filterOrg = (string) $this->option('organization');

        // ----------------------------------------------------------------
        // Parse the YAML file
        // ----------------------------------------------------------------
        try {
            $data = Yaml::parseFile($filePath);
        } catch (ParseException $e) {
            $this->error("Failed to parse YAML: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($data)) {
            $this->error('Invalid YAML structure: expected a mapping at the root level.');

            return self::FAILURE;
        }

        // ----------------------------------------------------------------
        // Organisation filter — skip the whole file if it doesn't match.
        // This prevents silently importing the wrong portfolio.
        // ----------------------------------------------------------------
        $fileOrg = $data['organization'] ?? null;

        if ((string) $fileOrg !== $filterOrg) {
            $this->warn(
                "Skipping file: organization \"{$fileOrg}\" does not match --organization=\"{$filterOrg}\"."
            );

            return self::SUCCESS;
        }

        // ----------------------------------------------------------------
        // Resolve team
        // ----------------------------------------------------------------
        $teamId = $this->option('team');

        if ($teamId !== null) {
            $team = Team::find((int) $teamId);

            if (! $team) {
                $this->error("Team ID {$teamId} not found.");

                return self::FAILURE;
            }
        } else {
            $team = Team::first();

            if (! $team) {
                $this->error('No teams exist. Create a team before importing sites.');

                return self::FAILURE;
            }
        }

        // ----------------------------------------------------------------
        // Process sites
        // ----------------------------------------------------------------
        $sites = $data['sites'] ?? [];

        if (empty($sites)) {
            $this->warn('No sites defined in the YAML file.');

            return self::SUCCESS;
        }

        $imported = 0;
        $updated = 0;
        $tableRows = [];

        foreach ($sites as $raw) {
            $alias = (string) ($raw['alias'] ?? '');

            if ($alias === '') {
                $this->warn('Skipping a site entry with no alias.');

                continue;
            }

            try {
                $attributes = $this->buildAttributes($raw, $team->id, $fileOrg);

                $existing = Site::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('alias', $alias)
                    ->first();

                if ($existing) {
                    $existing->update($attributes);
                    $updated++;
                    $status = 'updated';
                } else {
                    Site::create($attributes);
                    $imported++;
                    $status = 'imported';
                }

                $tableRows[] = [
                    $alias,
                    implode(', ', (array) ($raw['domains'] ?? [])),
                    $this->flag($attributes['gsc_property']),
                    $this->flag($attributes['bing_url']),
                    $this->flag($attributes['ga4_property']),
                    $this->flag($attributes['amazon_tag']),
                    $status,
                ];
            } catch (Throwable $e) {
                Log::error("sites:import failed for alias \"{$alias}\"", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $this->error("  Error importing \"{$alias}\": {$e->getMessage()}");
            }
        }

        // ----------------------------------------------------------------
        // Summary table
        // ----------------------------------------------------------------
        $this->table(
            ['Alias', 'Domains', 'GSC', 'Bing', 'GA4', 'Amazon', 'Status'],
            $tableRows
        );

        $this->info("{$imported} site(s) imported, {$updated} updated.");

        return self::SUCCESS;
    }

    /**
     * Build the array of Site attributes from a raw YAML site entry.
     *
     * YAML may store boolean false for optional integrations (gsc, bing, ga4) to
     * indicate "intentionally not configured".  We normalise these to null so the
     * database stays clean.
     */
    private function buildAttributes(array $raw, int $teamId, string $organization): array
    {
        $domains = (array) ($raw['domains'] ?? []);
        $primaryLocale = $raw['primary_locale'] ?? null;

        // Resolve primary_domain from template + locale when available.
        $primaryDomain = $this->resolvePrimaryDomain($domains, $primaryLocale);

        return [
            'team_id' => $teamId,
            'alias' => (string) $raw['alias'],
            'organization' => $organization,
            'primary_domain' => $primaryDomain,
            'domains' => $domains,
            'locales' => ! empty($raw['locales']) ? (array) $raw['locales'] : null,
            'primary_locale' => $primaryLocale ?: null,
            'type' => (string) ($raw['type'] ?? 'static'),
            'health_endpoint' => $raw['health_endpoint'] ?? null ?: null,
            'gsc_property' => $this->stringOrNull($raw['gsc'] ?? null),
            'bing_url' => $this->stringOrNull($raw['bing'] ?? null),
            'ga4_property' => $this->stringOrNull($raw['ga4'] ?? null),
            'sitemap_path' => $raw['sitemap_path'] ?? null ?: null,
            'sitemap_locale_pattern' => $raw['sitemap_locale_pattern'] ?? null ?: null,
            'key_pages' => ! empty($raw['key_pages']) ? (array) $raw['key_pages'] : null,
            'ad_networks' => ! empty($raw['ad_networks']) ? (array) $raw['ad_networks'] : null,
            'merchant_domains' => ! empty($raw['merchant_domains']) ? (array) $raw['merchant_domains'] : null,
            'amazon_tag' => $this->stringOrNull($raw['amazon_tag'] ?? null),
            'dokploy_app_id' => $raw['dokploy_app_id'] ?? null ?: null,
            'dokploy_resource_type' => $raw['dokploy_resource_type'] ?? null ?: null,
            'is_active' => true,
        ];
    }

    /**
     * Resolve the canonical primary domain from domain templates and locale.
     *
     * If the first domain template contains "{locale}" and a primary_locale is
     * given, we substitute to get the actual hostname (e.g. "fr.examplestore.com").
     */
    private function resolvePrimaryDomain(array $domains, ?string $primaryLocale): string
    {
        $template = $domains[0] ?? '';

        if ($template && $primaryLocale && str_contains($template, '{locale}')) {
            return str_replace('{locale}', $primaryLocale, $template);
        }

        return $template;
    }

    /**
     * Normalise YAML values that can be either a string or boolean false.
     *
     * In the portfolio YAML, unsupported integrations are written as `false`
     * rather than being omitted.  We store null in the database to keep queries
     * clean ("WHERE gsc_property IS NOT NULL").
     */
    private function stringOrNull(mixed $value): ?string
    {
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /** Returns "yes" or "—" for display in the summary table. */
    private function flag(mixed $value): string
    {
        return ($value !== null && $value !== '') ? 'yes' : '—';
    }
}
