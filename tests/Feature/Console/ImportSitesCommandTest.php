<?php

namespace Tests\Feature\Console;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportSitesCommandTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Write a temporary YAML file and return its path. */
    private function writeTempYaml(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sites_import_test_').'.yaml';
        file_put_contents($path, $content);

        return $path;
    }

    /** Minimal valid YAML with a single site. */
    private function minimalYaml(string $organization = 'Radiank'): string
    {
        return <<<YAML
organization: {$organization}
sites:
  - alias: "minimal"
    domains: ["example.com"]
    type: "static"
YAML;
    }

    // -----------------------------------------------------------------------
    // Test 1 — happy path: import two sites with different configurations
    // -----------------------------------------------------------------------

    public function test_imports_radiank_sites_from_yaml(): void
    {
        $team = Team::factory()->create();

        $yaml = <<<'YAML'
organization: Radiank
sites:
  - alias: "examplestore"
    domains: ["{locale}.examplestore.com"]
    locales: [fr, us, uk]
    primary_locale: "fr"
    type: "laravel"
    health_endpoint: "/api/health"
    gsc: "sc-domain:examplestore.com"
    bing: "https://www.bing.com/webmasters/?siteUrl=https://fr.examplestore.com"
    ga4: "p528945610"
    sitemap_path: "/sitemap.xml"
    sitemap_locale_pattern: "/sitemap-{locale}.xml"
    key_pages: ["/", "/top"]
    ad_networks: null
    merchant_domains: [amazon]
    dokploy_app_id: "d9q20iabc"
    dokploy_resource_type: "application"

  - alias: "blague"
    domains: ["jokes.example"]
    type: "wordpress"
YAML;

        $path = $this->writeTempYaml($yaml);

        $this->artisan('sites:import', [
            'path' => $path,
            '--organization' => 'Radiank',
            '--team' => $team->id,
        ])->assertExitCode(0);

        unlink($path);

        $this->assertSame(2, Site::withoutGlobalScopes()->where('team_id', $team->id)->count());

        // Full-featured site
        $examplestore = Site::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('alias', 'examplestore')
            ->firstOrFail();

        $this->assertSame('sc-domain:examplestore.com', $examplestore->gsc_property);
        $this->assertSame(['fr', 'us', 'uk'], $examplestore->locales);
        $this->assertSame(['amazon'], $examplestore->merchant_domains);
        $this->assertSame('fr.examplestore.com', $examplestore->primary_domain);
        $this->assertSame('Radiank', $examplestore->organization);
        $this->assertTrue($examplestore->is_active);

        // Minimal site
        $blague = Site::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('alias', 'blague')
            ->firstOrFail();

        $this->assertNull($blague->gsc_property);
        $this->assertSame('wordpress', $blague->type);
    }

    // -----------------------------------------------------------------------
    // Test 2 — organisation filter: non-matching file must produce 0 sites
    // -----------------------------------------------------------------------

    public function test_skips_non_matching_organization(): void
    {
        $team = Team::factory()->create();
        $path = $this->writeTempYaml($this->minimalYaml('Other'));

        $this->artisan('sites:import', [
            'path' => $path,
            '--organization' => 'Radiank',
            '--team' => $team->id,
        ])->assertExitCode(0);

        unlink($path);

        $this->assertSame(0, Site::withoutGlobalScopes()->count());
    }

    // -----------------------------------------------------------------------
    // Test 3 — false values for integrations must become null
    // -----------------------------------------------------------------------

    public function test_handles_false_values(): void
    {
        $team = Team::factory()->create();

        $yaml = <<<'YAML'
organization: Radiank
sites:
  - alias: "nogsc"
    domains: ["nogsc.com"]
    type: "static"
    gsc: false
    bing: false
    ga4: false
YAML;

        $path = $this->writeTempYaml($yaml);

        $this->artisan('sites:import', [
            'path' => $path,
            '--organization' => 'Radiank',
            '--team' => $team->id,
        ])->assertExitCode(0);

        unlink($path);

        $site = Site::withoutGlobalScopes()->where('alias', 'nogsc')->firstOrFail();

        $this->assertNull($site->gsc_property);
        $this->assertNull($site->bing_url);
        $this->assertNull($site->ga4_property);
    }

    // -----------------------------------------------------------------------
    // Test 4 — idempotent: re-importing the same YAML must not duplicate rows
    // -----------------------------------------------------------------------

    public function test_idempotent_updates(): void
    {
        $team = Team::factory()->create();

        $yaml = <<<'YAML'
organization: Radiank
sites:
  - alias: "idempotent"
    domains: ["idempotent.com"]
    type: "static"
    gsc: "sc-domain:idempotent.com"
YAML;

        $path = $this->writeTempYaml($yaml);

        // First import
        $this->artisan('sites:import', [
            'path' => $path,
            '--organization' => 'Radiank',
            '--team' => $team->id,
        ])->assertExitCode(0);

        // Second import — must update, not insert
        $this->artisan('sites:import', [
            'path' => $path,
            '--organization' => 'Radiank',
            '--team' => $team->id,
        ])->assertExitCode(0);

        unlink($path);

        // Exactly one row, not two
        $this->assertSame(1, Site::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('alias', 'idempotent')
            ->count());
    }

    // -----------------------------------------------------------------------
    // Test 5 — locale substitution: "{locale}" in domain must produce the
    //          expected primary_domain after substitution
    // -----------------------------------------------------------------------

    public function test_resolves_primary_domain_with_locale(): void
    {
        $team = Team::factory()->create();

        $yaml = <<<'YAML'
organization: Radiank
sites:
  - alias: "locale-site"
    domains: ["{locale}.examplestore.com"]
    primary_locale: "fr"
    locales: [fr, us]
    type: "laravel"
YAML;

        $path = $this->writeTempYaml($yaml);

        $this->artisan('sites:import', [
            'path' => $path,
            '--organization' => 'Radiank',
            '--team' => $team->id,
        ])->assertExitCode(0);

        unlink($path);

        $site = Site::withoutGlobalScopes()->where('alias', 'locale-site')->firstOrFail();

        $this->assertSame('fr.examplestore.com', $site->primary_domain);
    }

    // -----------------------------------------------------------------------
    // Test 6 — default team: command uses first team when --team not given
    // -----------------------------------------------------------------------

    public function test_defaults_to_first_team_when_team_not_specified(): void
    {
        $team = Team::factory()->create();
        $path = $this->writeTempYaml($this->minimalYaml());

        $this->artisan('sites:import', ['path' => $path])->assertExitCode(0);

        unlink($path);

        $this->assertSame(1, Site::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    // -----------------------------------------------------------------------
    // Test 7 — error when no teams exist
    // -----------------------------------------------------------------------

    public function test_fails_when_no_teams_exist(): void
    {
        $path = $this->writeTempYaml($this->minimalYaml());

        $this->artisan('sites:import', ['path' => $path])->assertExitCode(1);

        unlink($path);
    }
}
