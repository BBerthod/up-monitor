<?php

namespace Tests\Feature\Console;

use App\Models\Monitor;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for monitors:audit-duplicate-urls — the read-only audit that surfaces
 * the exact bug behind the 2026-09-01 PSI quota exhaustion: two HTTP
 * monitors for the same team resolving to the same content (trailing-slash
 * / host-casing variants) each burn their own daily PSI budget.
 *
 * The (team_id, normalized_url) unique index (see the corresponding
 * migration) makes it impossible to *create* such a duplicate going forward
 * — which is the point. To exercise the audit command itself we simulate
 * data that predates the index (legacy duplicates on a dataset the migration
 * found already dirty) by dropping it for the duration of a single test.
 * RefreshDatabase wraps each test in a transaction and PostgreSQL DDL is
 * transactional, so the index is always back for the next test regardless of
 * how this one ends.
 */
class AuditDuplicateMonitorUrlsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function dropUniqueUrlConstraintForThisTest(): void
    {
        DB::statement('DROP INDEX IF EXISTS monitors_team_id_normalized_url_http_unique');
    }

    public function test_reports_no_duplicates_when_all_urls_are_distinct(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://example.com']);
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://other.example.com']);

        $this->artisan('monitors:audit-duplicate-urls')
            ->expectsOutputToContain('No duplicate normalised URLs found')
            ->assertExitCode(0);
    }

    public function test_detects_a_trailing_slash_duplicate_within_the_same_team(): void
    {
        $this->dropUniqueUrlConstraintForThisTest();

        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://example.com', 'name' => 'Primary']);
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://example.com/', 'name' => 'Duplicate']);

        $this->artisan('monitors:audit-duplicate-urls')
            ->expectsOutputToContain('Primary')
            ->expectsOutputToContain('Duplicate')
            ->expectsOutputToContain('1 duplicate group(s) found, 2 monitor(s) involved')
            ->assertExitCode(0);
    }

    public function test_detects_a_host_casing_duplicate_within_the_same_team(): void
    {
        $this->dropUniqueUrlConstraintForThisTest();

        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://example.com']);
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://Example.com']);

        $this->artisan('monitors:audit-duplicate-urls')
            ->expectsOutputToContain('1 duplicate group(s) found, 2 monitor(s) involved')
            ->assertExitCode(0);
    }

    public function test_does_not_flag_the_same_normalized_url_across_different_teams(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        Monitor::factory()->for($teamA)->create(['type' => 'http', 'url' => 'https://example.com']);
        Monitor::factory()->for($teamB)->create(['type' => 'http', 'url' => 'https://example.com/']);

        $this->artisan('monitors:audit-duplicate-urls')
            ->expectsOutputToContain('No duplicate normalised URLs found')
            ->assertExitCode(0);
    }

    public function test_does_not_flag_a_port_monitor_sharing_a_host_with_an_http_monitor(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://example.com']);
        Monitor::factory()->for($team)->create(['type' => 'port', 'url' => 'example.com', 'port' => 22]);

        $this->artisan('monitors:audit-duplicate-urls')
            ->expectsOutputToContain('No duplicate normalised URLs found')
            ->assertExitCode(0);
    }

    /**
     * Scheme (http vs https) and www vs apex are deliberately allowed to
     * coexist as distinct monitors elsewhere in the app (redundant uptime
     * checking, collapsed per-site downstream — see
     * HealthDropDeduplicationTest / DispatchInsightsTest). The audit must
     * not flag them either, or it would push a human to "fix" an intentional
     * pattern.
     */
    public function test_does_not_flag_legitimate_scheme_or_www_variants(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'http://example.com']);
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://example.com']);
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://www.example.com']);

        $this->artisan('monitors:audit-duplicate-urls')
            ->expectsOutputToContain('No duplicate normalised URLs found')
            ->assertExitCode(0);
    }

    public function test_is_read_only_and_does_not_delete_or_merge_anything(): void
    {
        $this->dropUniqueUrlConstraintForThisTest();

        $team = Team::factory()->create();
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://example.com']);
        Monitor::factory()->for($team)->create(['type' => 'http', 'url' => 'https://example.com/']);

        $this->artisan('monitors:audit-duplicate-urls')->assertExitCode(0);

        $this->assertSame(2, Monitor::withoutGlobalScopes()->count());
    }

    public function test_team_option_restricts_the_audit(): void
    {
        $this->dropUniqueUrlConstraintForThisTest();

        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        Monitor::factory()->for($teamA)->create(['type' => 'http', 'url' => 'https://example.com']);
        Monitor::factory()->for($teamA)->create(['type' => 'http', 'url' => 'https://example.com/']);
        Monitor::factory()->for($teamB)->create(['type' => 'http', 'url' => 'https://other.com']);
        Monitor::factory()->for($teamB)->create(['type' => 'http', 'url' => 'https://other.com/']);

        $this->artisan('monitors:audit-duplicate-urls', ['--team' => $teamA->id])
            ->expectsOutputToContain('1 duplicate group(s) found, 2 monitor(s) involved')
            ->assertExitCode(0);
    }
}
