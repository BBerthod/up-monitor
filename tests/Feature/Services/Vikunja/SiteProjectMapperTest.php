<?php

namespace Tests\Feature\Services\Vikunja;

use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Services\Vikunja\SiteProjectMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteProjectMapperTest extends TestCase
{
    use RefreshDatabase;

    private SiteProjectMapper $mapper;

    private Team $team;

    /** @var array<int,array<string,mixed>> Mutated in place so a single closure-based fake stays live across changes. */
    private array $labels = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vikunja.enabled', true);
        config()->set('vikunja.token', 'tk_test');
        config()->set('vikunja.base_url', 'https://vikunja.test/api/v1');
        config()->set('vikunja.board_project_id', 900);
        config()->set('vikunja.project_label_prefix', 'projet: ');

        Cache::flush();

        // A closure reading $this->labels, not a static fixture: repeated
        // Http::fake() calls merge their stubs and the EARLIEST one keeps
        // winning (see VikunjaTaskServiceTest), so a test that needs the
        // label set to change mid-run must mutate this array instead of
        // calling Http::fake() again.
        Http::fake([
            'vikunja.test/api/v1/labels*' => fn () => Http::response($this->labels),
            '*' => Http::response([], 200),
        ]);

        $this->mapper = app(SiteProjectMapper::class);
        $this->team = Team::factory()->create();
    }

    /** @param array<int,array<string,mixed>> $labels */
    private function setLabels(array $labels): void
    {
        $this->labels = $labels;
        // Labels are cached (see ProjectLabelResolver) — a change here must be
        // visible on the very next resolution, not after the TTL expires.
        Cache::forget('vikunja:labels:all');
    }

    public function test_board_mode_maps_a_site_to_the_board_when_its_label_exists(): void
    {
        $this->setLabels([
            ['id' => 77, 'title' => 'projet: radiank.com'],
        ]);

        $site = Site::factory()->create([
            'team_id' => $this->team->id,
            'primary_domain' => 'radiank.com',
            'alias' => 'radiank-alias',
            'vikunja_project_id' => null,
        ]);

        $this->mapper->mapOne($site);

        $this->assertSame(900, $site->fresh()->vikunja_project_id, 'a mapped site now points at the shared board');
    }

    public function test_board_mode_resolves_an_open_unmapped_insight_once_the_label_appears(): void
    {
        $this->setLabels([]);

        $site = Site::factory()->create([
            'team_id' => $this->team->id,
            'primary_domain' => 'radiank.com',
            'alias' => 'radiank-alias',
            'vikunja_project_id' => null,
        ]);

        $this->mapper->mapOne($site);
        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::VIKUNJA_UNMAPPED_SITE->value)
            ->where('payload->site_id', $site->id)
            ->firstOrFail();
        $this->assertNull($insight->acknowledged_at);

        $this->setLabels([
            ['id' => 77, 'title' => 'projet: radiank.com'],
        ]);

        $this->mapper->mapOne($site->fresh());

        $this->assertNotNull($insight->fresh()->acknowledged_at, 'the gap it reported no longer exists');
    }

    public function test_board_mode_raises_an_unmapped_insight_when_no_label_matches(): void
    {
        $this->setLabels([]);

        $site = Site::factory()->create([
            'team_id' => $this->team->id,
            'primary_domain' => 'unmatched.test',
            'alias' => 'unmatched-alias',
            'vikunja_project_id' => null,
        ]);

        $this->mapper->mapOne($site);

        $this->assertNull($site->fresh()->vikunja_project_id);
        $this->assertTrue(
            Insight::withoutGlobalScopes()
                ->where('type', InsightType::VIKUNJA_UNMAPPED_SITE->value)
                ->where('payload->site_id', $site->id)
                ->whereNull('acknowledged_at')
                ->exists(),
        );
    }
}
