<?php

namespace Tests\Unit\Models;

use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // resolvedPrimaryDomain
    // -----------------------------------------------------------------------

    public function test_resolved_primary_domain_returns_primary_domain_when_no_locale_template(): void
    {
        $site = Site::factory()->create([
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'primary_locale' => null,
        ]);

        $this->assertSame('example.com', $site->resolvedPrimaryDomain());
    }

    public function test_resolved_primary_domain_substitutes_locale_placeholder(): void
    {
        $site = Site::factory()->create([
            'primary_domain' => 'fr.examplestore.com',
            'domains' => ['{locale}.examplestore.com'],
            'primary_locale' => 'fr',
        ]);

        $this->assertSame('fr.examplestore.com', $site->resolvedPrimaryDomain());
    }

    public function test_resolved_primary_domain_falls_back_to_first_domain_when_no_primary_domain(): void
    {
        $site = Site::factory()->create([
            'primary_domain' => '',
            'domains' => ['fallback.com'],
            'primary_locale' => null,
        ]);

        $this->assertSame('fallback.com', $site->resolvedPrimaryDomain());
    }

    public function test_resolved_primary_domain_ignores_locale_when_no_placeholder(): void
    {
        // Domain is a plain string — locale must not be injected.
        $site = Site::factory()->create([
            'primary_domain' => 'fr.examplestore.com',
            'domains' => ['fr.examplestore.com'],
            'primary_locale' => 'us',
        ]);

        // primary_domain wins because no placeholder in template.
        $this->assertSame('fr.examplestore.com', $site->resolvedPrimaryDomain());
    }

    // -----------------------------------------------------------------------
    // scopeActive
    // -----------------------------------------------------------------------

    public function test_scope_active_returns_only_active_sites(): void
    {
        $team = Team::factory()->create();
        Site::factory()->create(['team_id' => $team->id, 'is_active' => true]);
        Site::factory()->create(['team_id' => $team->id, 'is_active' => false]);

        $active = Site::withoutGlobalScopes()->active()->get();

        $this->assertCount(1, $active);
        $this->assertTrue($active->first()->is_active);
    }

    // -----------------------------------------------------------------------
    // scopeForOrganization
    // -----------------------------------------------------------------------

    public function test_scope_for_organization_filters_by_organization(): void
    {
        $team = Team::factory()->create();
        Site::factory()->create(['team_id' => $team->id, 'organization' => 'Radiank']);
        Site::factory()->create(['team_id' => $team->id, 'organization' => 'Other']);

        $results = Site::withoutGlobalScopes()->forOrganization('Radiank')->get();

        $this->assertCount(1, $results);
        $this->assertSame('Radiank', $results->first()->organization);
    }

    // -----------------------------------------------------------------------
    // Relation: monitors
    // -----------------------------------------------------------------------

    public function test_site_has_monitors_relationship(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->create(['team_id' => $team->id]);
        $monitor = Monitor::factory()->create([
            'team_id' => $team->id,
            'site_id' => $site->id,
        ]);

        $this->assertCount(1, $site->monitors);
        $this->assertTrue($site->monitors->first()->is($monitor));
    }

    public function test_monitor_belongs_to_site(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->create(['team_id' => $team->id]);
        $monitor = Monitor::factory()->create([
            'team_id' => $team->id,
            'site_id' => $site->id,
        ]);

        $this->assertTrue($monitor->site->is($site));
    }

    public function test_monitor_site_is_nullable(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'team_id' => $team->id,
            'site_id' => null,
        ]);

        $this->assertNull($monitor->site);
    }

    // -----------------------------------------------------------------------
    // findByHostname
    // -----------------------------------------------------------------------

    public function test_find_by_hostname_matches_primary_domain(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
        ]);

        $found = Site::findByHostname('example.com', $team->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($site));
    }

    public function test_find_by_hostname_matches_locale_template_in_domains(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'fr.examplestore.com',
            'domains' => ['{locale}.examplestore.com'],
            'locales' => ['fr', 'us', 'uk'],
            'primary_locale' => 'fr',
        ]);

        // The primary_domain is "fr.examplestore.com" so that matches directly.
        // We test a secondary locale to confirm template expansion works.
        $found = Site::findByHostname('us.examplestore.com', $team->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($site));
    }

    public function test_find_by_hostname_is_team_scoped(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        // The site belongs to teamA only.
        Site::factory()->create([
            'team_id' => $teamA->id,
            'primary_domain' => 'shared.example.com',
            'domains' => ['shared.example.com'],
        ]);

        // Querying with teamB's ID must return null.
        $found = Site::findByHostname('shared.example.com', $teamB->id);

        $this->assertNull($found);
    }

    public function test_find_by_hostname_returns_null_for_unknown_hostname(): void
    {
        $team = Team::factory()->create();
        Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'known.example.com',
            'domains' => ['known.example.com'],
        ]);

        $found = Site::findByHostname('unknown.example.com', $team->id);

        $this->assertNull($found);
    }
}
