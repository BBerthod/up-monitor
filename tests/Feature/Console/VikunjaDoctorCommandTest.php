<?php

namespace Tests\Feature\Console;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VikunjaDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vikunja.enabled', true);
        config()->set('vikunja.token', 'tk_test');
        config()->set('vikunja.base_url', 'https://vikunja.test/api/v1');
        config()->set('vikunja.buckets.todo', 'À faire');
        Cache::flush();

        // SiteObserver dispatches MapSiteToVikunjaProject on Site::created, and
        // QUEUE_CONNECTION=sync in tests would run it inline — pre-mapping every
        // fixture site below before the command under test even runs. These
        // tests are about vikunja:doctor's own mapping logic; the automatic
        // observer path has its own tests (see MapSiteToVikunjaProjectTest).
        Queue::fake();
    }

    private function fakeBoard(): void
    {
        Http::fake([
            'vikunja.test/api/v1/projects/*/views/*/buckets' => Http::response([
                ['id' => 53, 'title' => 'À faire'],
            ]),
            'vikunja.test/api/v1/projects/*/views' => Http::response([
                ['id' => 56, 'view_kind' => 'kanban', 'title' => 'Kanban'],
            ]),
            'vikunja.test/api/v1/projects*' => Http::response([
                // Sphere container, listed FIRST — it must not win.
                ['id' => 2, 'title' => 'Radiank'],
                ['id' => 24, 'title' => 'radiank.com'],
                // Saved filters come back with negative ids and cannot hold tasks.
                ['id' => -3, 'title' => 'Radiank'],
            ]),
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * Regression: the sphere container project "Radiank" holds no tasks by
     * convention, yet it matched the site's alias ("radiank") before the more
     * specific "radiank.com" project was ever considered. Candidates must be
     * tried most-specific-first, across all projects, not the other way round.
     */
    public function test_mapping_prefers_the_site_project_over_the_sphere_container(): void
    {
        $this->fakeBoard();

        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'alias' => 'radiank',
            'primary_domain' => 'radiank.com',
            'vikunja_project_id' => null,
        ]);

        $this->artisan('vikunja:doctor --map --apply')->assertSuccessful();

        $this->assertSame(24, $site->fresh()->vikunja_project_id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->fakeBoard();

        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'alias' => 'radiank',
            'primary_domain' => 'radiank.com',
            'vikunja_project_id' => null,
        ]);

        $this->artisan('vikunja:doctor --map')->assertSuccessful();

        $this->assertNull($site->fresh()->vikunja_project_id);
    }

    public function test_it_fails_loudly_when_the_integration_is_disabled(): void
    {
        config()->set('vikunja.enabled', false);

        $this->artisan('vikunja:doctor')->assertFailed();
    }

    public function test_board_mode_reports_the_board_and_each_sites_project_label(): void
    {
        config()->set('vikunja.board_project_id', 900);
        config()->set('vikunja.labels.sphere', 45);
        config()->set('vikunja.project_label_prefix', 'projet: ');

        Http::fake([
            'vikunja.test/api/v1/projects/*/views/*/buckets' => Http::response([
                ['id' => 53, 'title' => 'À faire'],
            ]),
            'vikunja.test/api/v1/projects/*/views' => Http::response([
                ['id' => 56, 'view_kind' => 'kanban', 'title' => 'Kanban'],
            ]),
            'vikunja.test/api/v1/labels*' => Http::response([
                ['id' => 77, 'title' => 'projet: radiank.com'],
            ]),
            'vikunja.test/api/v1/projects*' => Http::response([
                ['id' => 900, 'title' => 'Board'],
            ]),
            '*' => Http::response([], 200),
        ]);

        $team = Team::factory()->create();
        Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'radiank.com',
            'alias' => 'radiank',
            'vikunja_project_id' => null,
        ]);

        $this->artisan('vikunja:doctor')
            ->expectsOutputToContain('900')
            ->expectsOutputToContain('45')
            ->assertSuccessful();
    }
}
