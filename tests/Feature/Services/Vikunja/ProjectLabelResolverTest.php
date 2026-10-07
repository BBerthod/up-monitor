<?php

namespace Tests\Feature\Services\Vikunja;

use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\VikunjaTaskLink;
use App\Services\Vikunja\ProjectLabelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectLabelResolverTest extends TestCase
{
    use RefreshDatabase;

    private ProjectLabelResolver $resolver;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vikunja.enabled', true);
        config()->set('vikunja.token', 'tk_test');
        config()->set('vikunja.base_url', 'https://vikunja.test/api/v1');
        config()->set('vikunja.board_project_id', 900);
        config()->set('vikunja.project_label_prefix', 'projet: ');
        config()->set('vikunja.server_project_title', 'Infrastructure');

        Cache::flush();

        $this->resolver = app(ProjectLabelResolver::class);
        $this->team = Team::factory()->create();
    }

    /** @param array<int,array<string,mixed>> $labels */
    private function fakeLabels(array $labels): void
    {
        Http::fake([
            'vikunja.test/api/v1/labels*' => Http::response($labels),
            'vikunja.test/api/v1/projects/*' => Http::response(['id' => 24, 'title' => 'radiank.com']),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_it_resolves_the_label_by_the_sites_old_project_title(): void
    {
        // The site's pre-migration project resolves to "radiank.com" — its
        // exact title is the most specific candidate and must win even though
        // the site's own domain fields differ.
        $this->fakeLabels([
            ['id' => 77, 'title' => 'projet: radiank.com'],
        ]);

        $site = Site::factory()->create([
            'team_id' => $this->team->id,
            'primary_domain' => 'other-domain.test',
            'alias' => 'placeholder-alias',
            'vikunja_project_id' => 24,
        ]);

        $link = VikunjaTaskLink::factory()->create([
            'team_id' => $this->team->id,
            'site_id' => $site->id,
        ]);

        $this->assertSame(77, $this->resolver->labelIdFor($link));
    }

    public function test_it_falls_back_to_domain_candidates_when_no_old_project_is_set(): void
    {
        $this->fakeLabels([
            ['id' => 88, 'title' => 'projet: webcompare.fr'],
        ]);

        $site = Site::factory()->create([
            'team_id' => $this->team->id,
            'primary_domain' => 'webcompare.fr',
            'alias' => 'placeholder-alias',
            'vikunja_project_id' => null,
        ]);

        $link = VikunjaTaskLink::factory()->create([
            'team_id' => $this->team->id,
            'site_id' => $site->id,
        ]);

        $this->assertSame(88, $this->resolver->labelIdFor($link));
    }

    public function test_it_resolves_the_server_label_via_the_fixed_infrastructure_title(): void
    {
        $this->fakeLabels([
            ['id' => 99, 'title' => 'projet: Infrastructure'],
        ]);

        $server = Server::factory()->create(['team_id' => $this->team->id]);

        $link = VikunjaTaskLink::factory()->create([
            'team_id' => $this->team->id,
            'server_id' => $server->id,
            'site_id' => null,
        ]);

        $this->assertSame(99, $this->resolver->labelIdFor($link));
    }

    public function test_it_returns_null_without_throwing_when_no_label_matches(): void
    {
        $this->fakeLabels([
            ['id' => 1, 'title' => 'projet: something-else'],
        ]);

        $site = Site::factory()->create([
            'team_id' => $this->team->id,
            'primary_domain' => 'unmatched.test',
            'alias' => 'placeholder-alias',
            'vikunja_project_id' => null,
        ]);

        $link = VikunjaTaskLink::factory()->create([
            'team_id' => $this->team->id,
            'site_id' => $site->id,
        ]);

        $this->assertNull($this->resolver->labelIdFor($link));
    }
}
