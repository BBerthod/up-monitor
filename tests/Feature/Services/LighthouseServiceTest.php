<?php

namespace Tests\Feature\Services;

use App\Models\Monitor;
use App\Models\MonitorLighthouseScore;
use App\Models\Team;
use App\Services\LighthouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class LighthouseServiceTest extends TestCase
{
    use RefreshDatabase;

    private LighthouseService $service;

    /** @var array<mixed> */
    private array $fixtureData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LighthouseService::class);
        $this->fixtureData = json_decode(
            file_get_contents(base_path('tests/Fixtures/psi-response.json')),
            true
        );
    }

    protected function tearDown(): void
    {
        // Local dev containers resolve CACHE_STORE to the real shared Redis
        // (phpunit.xml's forced `array` override isn't honored there), so the
        // 403/429 tests below leave real key-disable/quota-exhausted flags
        // behind that leak into later tests run in the same file. Clear the
        // exact keys LighthouseService writes (see its quotaKey()/disabledKey()
        // helpers) so this suite stays order-independent.
        Cache::forget('psi:quota:0:'.now()->format('Y-m-d'));
        Cache::forget('psi:disabled:0');

        parent::tearDown();
    }

    // ──────────────────────────────────────────────────
    // Happy path
    // ──────────────────────────────────────────────────

    public function test_parses_full_psi_response_and_creates_score_record(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake([
            'googleapis.com*' => Http::response($this->fixtureData, 200),
        ]);

        $score = $this->service->audit($monitor);

        $this->assertInstanceOf(MonitorLighthouseScore::class, $score);
        $this->assertEquals(92, $score->performance);
        $this->assertEquals(87, $score->accessibility);
        $this->assertEquals(75, $score->best_practices);
        $this->assertEquals(98, $score->seo);

        // Numeric values rounded to 1 decimal (parseAudit precision=1 for these).
        $this->assertEquals(1234.5, $score->fcp);
        $this->assertEquals(2345.6, $score->lcp);
        $this->assertEquals(1500.0, $score->speed_index);
        $this->assertEquals(120.0, $score->tbt);
        // CLS rounded to 4 decimal places.
        $this->assertEquals(0.0512, $score->cls);

        $this->assertDatabaseHas('monitor_lighthouse_scores', [
            'monitor_id' => $monitor->id,
            'performance' => 92,
            'accessibility' => 87,
            'best_practices' => 75,
            'seo' => 98,
        ]);
    }

    public function test_scored_at_is_set_on_created_record(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake([
            'googleapis.com*' => Http::response($this->fixtureData, 200),
        ]);

        $score = $this->service->audit($monitor);

        $this->assertNotNull($score->scored_at);
        $this->assertEqualsWithDelta(now()->timestamp, $score->scored_at->timestamp, 5);
    }

    public function test_persists_observed_lcp_and_benchmark_index_from_psi_response(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        $data = $this->fixtureData;
        data_set($data, 'lighthouseResult.audits.metrics.details.items.0.observedLargestContentfulPaint', 1623.44);
        data_set($data, 'lighthouseResult.environment.benchmarkIndex', 402.34);

        Http::fake([
            'googleapis.com*' => Http::response($data, 200),
        ]);

        $score = $this->service->audit($monitor);

        $this->assertEquals(1623.4, $score->lcp_observed);
        $this->assertEquals(402.3, $score->benchmark_index);

        $this->assertDatabaseHas('monitor_lighthouse_scores', [
            'monitor_id' => $monitor->id,
            'lcp_observed' => 1623.4,
            'benchmark_index' => 402.3,
        ]);
    }

    public function test_missing_audit_metrics_stored_as_null(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        // Strip all audit entries from the response.
        $partial = $this->fixtureData;
        $partial['lighthouseResult']['audits'] = [];

        Http::fake([
            'googleapis.com*' => Http::response($partial, 200),
        ]);

        $score = $this->service->audit($monitor);

        $this->assertNull($score->lcp);
        $this->assertNull($score->fcp);
        $this->assertNull($score->cls);
        $this->assertNull($score->tbt);
        $this->assertNull($score->speed_index);
        $this->assertNull($score->lcp_observed);
        $this->assertNull($score->benchmark_index);
    }

    public function test_missing_category_score_stored_as_zero(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        $partial = $this->fixtureData;
        $partial['lighthouseResult']['categories'] = [];

        Http::fake([
            'googleapis.com*' => Http::response($partial, 200),
        ]);

        $score = $this->service->audit($monitor);

        $this->assertEquals(0, $score->performance);
        $this->assertEquals(0, $score->accessibility);
        $this->assertEquals(0, $score->best_practices);
        $this->assertEquals(0, $score->seo);
    }

    // ──────────────────────────────────────────────────
    // Error paths
    // ──────────────────────────────────────────────────

    public function test_handles_400_bad_request_throws_runtime_exception(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com/api/health/quick']);

        Http::fake([
            'googleapis.com*' => Http::response(['error' => 'invalid url'], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/400/');

        $this->service->audit($monitor);
    }

    public function test_handles_429_quota_exceeded_throws_runtime_exception(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake([
            'googleapis.com*' => Http::response(['error' => 'quota exceeded'], 429),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/429/');

        $this->service->audit($monitor);
    }

    public function test_handles_403_invalid_key_throws_runtime_exception(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake([
            'googleapis.com*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/403/');

        $this->service->audit($monitor);
    }

    public function test_handles_500_server_error_throws_runtime_exception(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake([
            'googleapis.com*' => Http::response('Internal Server Error', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/500/');

        $this->service->audit($monitor);
    }

    public function test_handles_missing_lighthouse_result_key_throws_runtime_exception(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake([
            'googleapis.com*' => Http::response(['unexpectedKey' => 'data'], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid Lighthouse/i');

        $this->service->audit($monitor);
    }
}
