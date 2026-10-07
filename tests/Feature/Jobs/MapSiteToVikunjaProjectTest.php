<?php

namespace Tests\Feature\Jobs;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Jobs\MapSiteToVikunjaProject;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers both the queued job and the SiteObserver that dispatches it: with
 * QUEUE_CONNECTION=sync (forced in phpunit.xml), Site::factory()->create()
 * runs this job inline, so exercising Site creation IS exercising the
 * observer wiring end to end. See also VikunjaDoctorCommandTest, which fakes
 * the queue precisely to avoid this side effect while testing the command in
 * isolation.
 */
class MapSiteToVikunjaProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vikunja.enabled', true);
        config()->set('vikunja.token', 'tk_test');
        config()->set('vikunja.base_url', 'https://vikunja.test/api/v1');
        Cache::flush();
    }

    private function fakeBoard(): void
    {
        Http::fake([
            'vikunja.test/api/v1/projects*' => Http::response([
                ['id' => 2, 'title' => 'Radiank'],
                ['id' => 24, 'title' => 'fashionshopp.fr'],
                ['id' => -3, 'title' => 'Radiank'],
            ]),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_site_created_with_a_matching_domain_is_mapped_automatically(): void
    {
        $this->fakeBoard();

        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'fashionshopp.fr',
            'vikunja_project_id' => null,
        ]);

        $this->assertSame(24, $site->fresh()->vikunja_project_id);
    }

    public function test_site_created_with_no_matching_project_raises_an_unmapped_insight(): void
    {
        $this->fakeBoard();

        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'canyon-corte.example',
            'vikunja_project_id' => null,
        ]);

        $this->assertNull($site->fresh()->vikunja_project_id);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::VIKUNJA_UNMAPPED_SITE->value)
            ->where('payload->site_id', $site->id)
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame($team->id, $insight->team_id);
        $this->assertSame(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertNull($insight->acknowledged_at);
    }

    public function test_it_does_not_duplicate_an_open_unmapped_insight_on_retry(): void
    {
        $this->fakeBoard();

        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'canyon-corte.example',
            'vikunja_project_id' => null,
        ]);

        // Simulate the daily vikunja:doctor sweep retrying the same unmapped site.
        MapSiteToVikunjaProject::dispatchSync($site->id);

        $this->assertSame(
            1,
            Insight::withoutGlobalScopes()
                ->where('type', InsightType::VIKUNJA_UNMAPPED_SITE->value)
                ->where('payload->site_id', $site->id)
                ->count()
        );
    }

    public function test_mapping_the_site_later_resolves_the_unmapped_insight(): void
    {
        // Http::fake() stubs stack rather than replace (the first matching
        // one always wins), so a plain second fake() call for the projects
        // endpoint later in this test would keep returning the first board.
        // fakeSequence() instead advances one response per call: the first
        // GET /projects (fired by the observer at Site::factory()->create())
        // sees no match, the second (the simulated daily sweep) sees the
        // site's project having since appeared on the board.
        Http::fakeSequence('vikunja.test/api/v1/projects*')
            ->push([['id' => 2, 'title' => 'Radiank']])
            ->push([['id' => 31, 'title' => 'canyon-corte.example']]);
        Http::fake(['*' => Http::response([], 200)]);

        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'canyon-corte.example',
            'vikunja_project_id' => null,
        ]);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::VIKUNJA_UNMAPPED_SITE->value)
            ->where('payload->site_id', $site->id)
            ->firstOrFail();
        $this->assertNull($insight->acknowledged_at);

        // Simulate the daily vikunja:doctor sweep retrying this still-unmapped site.
        MapSiteToVikunjaProject::dispatchSync($site->id);

        $this->assertSame(31, $site->fresh()->vikunja_project_id);
        $this->assertNotNull($insight->fresh()->acknowledged_at);
    }

    public function test_it_does_nothing_when_the_site_is_already_mapped(): void
    {
        $this->fakeBoard();

        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'vikunja_project_id' => 99,
        ]);

        // No HTTP call should even be attempted: the job bails out before
        // fetching projects.
        MapSiteToVikunjaProject::dispatchSync($site->id);

        $this->assertSame(99, $site->fresh()->vikunja_project_id);
    }

    /**
     * The observer must not even dispatch when the integration is off — not
     * just have the job no-op once it runs. Vikunja is disabled by default
     * (local/dev, most of the suite), and a site is created constantly
     * throughout the codebase's tests; dispatching a job unconditionally would
     * trip every unrelated Queue::assertNothingPushed() elsewhere.
     */
    public function test_the_observer_does_not_dispatch_when_vikunja_is_disabled(): void
    {
        config()->set('vikunja.enabled', false);

        Queue::fake();

        $team = Team::factory()->create();
        Site::factory()->create(['team_id' => $team->id, 'vikunja_project_id' => null]);

        Queue::assertNothingPushed();
    }

    public function test_the_job_itself_is_a_no_op_when_vikunja_is_disabled(): void
    {
        // Exercises the job's own guard directly (defence in depth), in case
        // config changes between dispatch and processing on a delayed queue.
        // Vikunja is already off before the site exists, so the observer
        // itself does not dispatch — this asserts the job's guard, not the
        // observer's (see test_the_observer_does_not_dispatch_when_vikunja_is_disabled).
        config()->set('vikunja.enabled', false);

        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'fashionshopp.fr',
            'vikunja_project_id' => null,
        ]);

        Http::fake(['*' => Http::response([], 500)]);

        MapSiteToVikunjaProject::dispatchSync($site->id);

        $this->assertNull($site->fresh()->vikunja_project_id);
    }

    public function test_site_observer_dispatches_the_job_on_creation(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $site = Site::factory()->create(['team_id' => $team->id, 'vikunja_project_id' => null]);

        Queue::assertPushed(
            MapSiteToVikunjaProject::class,
            fn (MapSiteToVikunjaProject $job): bool => $job->siteId === $site->id,
        );
    }
}
