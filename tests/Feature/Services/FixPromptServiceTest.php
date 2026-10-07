<?php

namespace Tests\Feature\Services;

use App\Enums\IncidentCause;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Team;
use App\Services\DokployRepoResolver;
use App\Services\FixPromptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for FixPromptService.
 *
 * DokployRepoResolver is mocked so no real HTTP calls are made.
 * The mock is registered in the container before resolving FixPromptService,
 * which causes Laravel's auto-wiring to inject the mock instance.
 */
class FixPromptServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REPO = 'git@github.com:X/Y.git';

    private const HOST = 'fr.examplestore.com';

    private function mockResolver(?array $returnValue = null): void
    {
        $this->mock(DokployRepoResolver::class, function ($mock) use ($returnValue) {
            $mock->shouldReceive('resolveRepoForHost')
                ->andReturn($returnValue ?? [
                    'repo' => self::REPO,
                    'app_name' => 'y',
                    'app_id' => '1',
                    'project_name' => 'X',
                    'branch' => 'main',
                    'all_domains' => [self::HOST],
                ]);
        });
    }

    private function makeService(): FixPromptService
    {
        return app(FixPromptService::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // forIncident
    // ──────────────────────────────────────────────────────────────────────

    public function test_incident_prompt_contains_site_repo_cause(): void
    {
        $this->mockResolver();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'team_id' => $team->id,
            'url' => 'https://'.self::HOST,
        ]);

        // Seed a few recent checks so "Recent checks" section is populated.
        MonitorCheck::factory()->count(3)->for($monitor)->create();

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
        ]);

        $prompt = $this->makeService()->forIncident($incident);

        $this->assertStringContainsString(self::HOST, $prompt);
        $this->assertStringContainsString(self::REPO, $prompt);
        // Human cause label for TIMEOUT
        $this->assertStringContainsString('timeout', strtolower($prompt));
        $this->assertStringContainsString('Recent checks', $prompt);
    }

    public function test_incident_prompt_handles_null_repo(): void
    {
        // Resolver returns null → prompt must use the fallback wording.
        $this->mockResolver(null);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'team_id' => $team->id,
            'url' => 'https://'.self::HOST,
        ]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
        ]);

        $prompt = $this->makeService()->forIncident($incident);

        $this->assertStringContainsString(self::HOST, $prompt);
        // When repo is null the service falls back to "unknown — resolve manually"
        $this->assertStringContainsString('unknown', $prompt);
        // Must not throw — prompt is a non-empty string.
        $this->assertNotEmpty($prompt);
    }

    // ──────────────────────────────────────────────────────────────────────
    // forInsight
    // ──────────────────────────────────────────────────────────────────────

    public function test_insight_prompt_for_revenue_at_risk(): void
    {
        $this->mockResolver();

        $team = Team::factory()->create();

        $insight = Insight::factory()->create([
            'team_id' => $team->id,
            'site' => 'https://'.self::HOST,
            'type' => InsightType::REVENUE_AT_RISK->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'title' => 'Revenue page broken',
            'payload' => [
                'page' => '/products',
                'status_code' => 404,
                'clicks' => 1200,
            ],
        ]);

        $prompt = $this->makeService()->forInsight($insight);

        $this->assertStringContainsString('/products', $prompt);
        $this->assertStringContainsString(self::REPO, $prompt);
        // Should mention the site host
        $this->assertStringContainsString(self::HOST, $prompt);
    }

    public function test_insight_prompt_generic_traffic_change(): void
    {
        $this->mockResolver();

        $team = Team::factory()->create();

        $insight = Insight::factory()->create([
            'team_id' => $team->id,
            'site' => 'https://'.self::HOST,
            'type' => InsightType::TRAFFIC_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'Traffic dropped 30%',
            'payload' => [],
        ]);

        $prompt = $this->makeService()->forInsight($insight);

        // Title and site must appear; must not throw.
        $this->assertStringContainsString('Traffic dropped 30%', $prompt);
        $this->assertStringContainsString(self::HOST, $prompt);
        $this->assertNotEmpty($prompt);
    }

    public function test_striking_distance_headline_includes_query_and_rounded_position(): void
    {
        $headline = $this->strikingDistanceHeadline([
            'page' => '/widgets',
            'query' => 'best widgets',
            'position' => 11.44,
        ]);

        $this->assertSame(
            'Optimize "/widgets" to rank higher for \'best widgets\' (currently position 11.4).',
            $headline,
        );
    }

    public function test_striking_distance_headline_omits_missing_position(): void
    {
        $headline = $this->strikingDistanceHeadline([
            'page' => '/widgets',
            'query' => 'best widgets',
            'position' => null,
        ]);

        $this->assertSame(
            'Optimize "/widgets" to rank higher for \'best widgets\'.',
            $headline,
        );
    }

    public function test_striking_distance_headline_uses_generic_copy_without_query(): void
    {
        $headline = $this->strikingDistanceHeadline([
            'page' => '/widgets',
            'query' => '   ',
            'position' => 12,
        ]);

        $this->assertSame(
            'Optimize "/widgets" to rank higher for its near-page-one queries.',
            $headline,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function strikingDistanceHeadline(array $payload): string
    {
        $this->mockResolver();

        $team = Team::factory()->create();
        $insight = Insight::factory()->create([
            'team_id' => $team->id,
            'site' => 'https://'.self::HOST,
            'type' => InsightType::STRIKING_DISTANCE->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'A striking-distance opportunity',
            'payload' => $payload,
        ]);

        return explode("\n", $this->makeService()->forInsight($insight))[0];
    }
}
