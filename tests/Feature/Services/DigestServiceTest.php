<?php

namespace Tests\Feature\Services;

use App\Enums\CheckStatus;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Services\DigestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for DigestService::generateForTeam().
 *
 * AI provider configuration: no 'services.ai.api_key' is set in phpunit.xml,
 * so GeminiProvider::isAvailable() returns false. DigestService catches any AI
 * exception and falls back to NullProvider, which always succeeds. Every test
 * here therefore exercises the full code path with NullProvider as the narrator.
 */
class DigestServiceTest extends TestCase
{
    use RefreshDatabase;

    private DigestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DigestService::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // generateForTeam — structure
    // ──────────────────────────────────────────────────────────────────────

    public function test_digest_generates_with_null_ai_provider(): void
    {
        // Ensure no AI key is configured — NullProvider fallback must be used.
        config(['services.ai.api_key' => null]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::UP,
            'response_time_ms' => 250,
            'status_code' => 200,
            'checked_at' => now()->subHours(2),
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertIsArray($digest);
        $this->assertNotEmpty($digest['narrative']);
        $this->assertIsString($digest['narrative']);
    }

    public function test_digest_contains_all_required_keys(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertArrayHasKey('team_name', $digest);
        $this->assertArrayHasKey('period_start', $digest);
        $this->assertArrayHasKey('period_end', $digest);
        $this->assertArrayHasKey('narrative', $digest);
        $this->assertArrayHasKey('health', $digest);
        $this->assertArrayHasKey('what_changed', $digest);
        $this->assertArrayHasKey('top_opportunities', $digest);
        $this->assertArrayHasKey('report', $digest);
        $this->assertArrayHasKey('alerts', $digest);
    }

    public function test_period_start_and_end_are_carbon_instances(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertInstanceOf(Carbon::class, $digest['period_start']);
        $this->assertInstanceOf(Carbon::class, $digest['period_end']);
    }

    public function test_team_name_is_set_correctly(): void
    {
        $team = Team::factory()->create(['name' => 'Acme Corp']);
        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertEquals('Acme Corp', $digest['team_name']);
    }

    public function test_narrative_is_non_empty_string(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertNotEmpty($digest['narrative']);
    }

    public function test_digest_does_not_throw_on_team_with_no_kpi_data(): void
    {
        // Team with an active monitor but zero KPI snapshots — should not throw.
        $team = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertIsArray($digest);
    }

    public function test_alerts_key_contains_expected_sub_keys(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertArrayHasKey('core_update_suspected', $digest['alerts']);
        $this->assertArrayHasKey('ga4_broken_sites', $digest['alerts']);
        $this->assertIsBool($digest['alerts']['core_update_suspected']);
        $this->assertIsArray($digest['alerts']['ga4_broken_sites']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // availability_alerts — SSL_EXPIRY, DOMAIN_EXPIRY, UPTIME_INCIDENT
    // ──────────────────────────────────────────────────────────────────────

    public function test_availability_alerts_appear_when_ssl_expiry_insight_exists(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create(['url' => 'https://example.com', 'team_id' => $team->id]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::SSL_EXPIRY,
            'severity' => InsightSeverity::WARNING,
            'title' => 'SSL certificate expiring soon',
            'payload' => ['days_remaining' => 12],
            'acknowledged_at' => null,
            'snoozed_until' => null,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertArrayHasKey('availability_alerts', $digest);
        $this->assertNotEmpty($digest['availability_alerts']);
        $this->assertEquals('SSL certificate expiring soon', $digest['availability_alerts'][0]['title']);
        $this->assertEquals(12, $digest['availability_alerts'][0]['days_remaining']);
    }

    public function test_availability_alerts_empty_when_insight_is_acknowledged(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create(['url' => 'https://example.com', 'team_id' => $team->id]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::DOMAIN_EXPIRY,
            'severity' => InsightSeverity::WARNING,
            'payload' => ['days_remaining' => 5],
            'acknowledged_at' => now(),
            'snoozed_until' => null,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertArrayHasKey('availability_alerts', $digest);
        $this->assertEmpty($digest['availability_alerts']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // perf_alerts — PERF_REGRESSION, HEALTH_DROP
    // ──────────────────────────────────────────────────────────────────────

    public function test_perf_alerts_appear_when_perf_regression_insight_exists(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create(['url' => 'https://example.com', 'team_id' => $team->id]);

        Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::PERF_REGRESSION,
            'severity' => InsightSeverity::WARNING,
            'title' => 'TTFB degraded on example.com',
            'payload' => ['metric' => 'ttfb', 'delta' => 420],
            'acknowledged_at' => null,
            'snoozed_until' => null,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertArrayHasKey('perf_alerts', $digest);
        $this->assertNotEmpty($digest['perf_alerts']);
        $this->assertEquals('TTFB degraded on example.com', $digest['perf_alerts'][0]['title']);
        $this->assertEquals('ttfb', $digest['perf_alerts'][0]['metric']);
        $this->assertEquals(420, $digest['perf_alerts'][0]['delta']);
    }

    public function test_perf_alerts_empty_when_no_matching_insights_exist(): void
    {
        $team = Team::factory()->create();
        Monitor::factory()->create(['url' => 'https://example.com', 'team_id' => $team->id]);

        // STRIKING_DISTANCE is a different type — must not bleed into perf_alerts.
        Insight::factory()->create([
            'team_id' => $team->id,
            'type' => InsightType::STRIKING_DISTANCE,
            'severity' => InsightSeverity::INFO,
            'payload' => [],
            'acknowledged_at' => null,
            'snoozed_until' => null,
        ]);

        $digest = $this->service->generateForTeam($team);

        $this->assertArrayHasKey('perf_alerts', $digest);
        $this->assertEmpty($digest['perf_alerts']);
    }
}
