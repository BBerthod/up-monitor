<?php

namespace Tests\Feature\Jobs;

use App\Events\LighthouseAuditCompleted;
use App\Jobs\RunLighthouseAudit;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\LighthouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RunLighthouseAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<mixed> */
    private array $fixtureData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureData = json_decode(
            file_get_contents(base_path('tests/Fixtures/psi-response.json')),
            true
        );
    }

    protected function tearDown(): void
    {
        // See LighthouseServiceTest::tearDown() — local dev containers share
        // real Redis with the live workers, so the 429-quota test below
        // leaves a real key-quota flag behind unless cleared here.
        Cache::forget('psi:quota:0:'.now()->format('Y-m-d'));
        Cache::forget('psi:disabled:0');

        parent::tearDown();
    }

    public function test_handle_creates_lighthouse_score_record(): void
    {
        Event::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake(['googleapis.com*' => Http::response($this->fixtureData, 200)]);

        (new RunLighthouseAudit($monitor))->handle(app(LighthouseService::class));

        $this->assertDatabaseHas('monitor_lighthouse_scores', [
            'monitor_id' => $monitor->id,
            'performance' => 92,
        ]);
    }

    public function test_handle_dispatches_lighthouse_audit_completed_event(): void
    {
        Event::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake(['googleapis.com*' => Http::response($this->fixtureData, 200)]);

        (new RunLighthouseAudit($monitor))->handle(app(LighthouseService::class));

        Event::assertDispatched(LighthouseAuditCompleted::class, function (LighthouseAuditCompleted $event) use ($monitor) {
            return $event->monitor->id === $monitor->id;
        });
    }

    public function test_handle_propagates_runtime_exception_on_api_error(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake(['googleapis.com*' => Http::response(['error' => 'quota'], 429)]);

        $this->expectException(RuntimeException::class);

        (new RunLighthouseAudit($monitor))->handle(app(LighthouseService::class));
    }

    public function test_handle_does_not_create_score_record_on_api_failure(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create(['url' => 'https://example.com']);

        Http::fake(['googleapis.com*' => Http::response('error', 500)]);

        try {
            (new RunLighthouseAudit($monitor))->handle(app(LighthouseService::class));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('monitor_lighthouse_scores', ['monitor_id' => $monitor->id]);
    }

    public function test_handle_does_not_throw_and_disables_lighthouse_on_400(): void
    {
        Event::fake();

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://fr.examplestore.com/api/health/quick',
            'lighthouse_enabled' => true,
        ]);

        Http::fake(['googleapis.com*' => Http::response(['error' => 'bad request'], 400)]);

        // Must complete without throwing — a 400 is a permanent failure and
        // must not be retried by the queue worker.
        (new RunLighthouseAudit($monitor))->handle(app(LighthouseService::class));

        $this->assertFalse($monitor->fresh()->lighthouse_enabled);
        $this->assertDatabaseMissing('monitor_lighthouse_scores', ['monitor_id' => $monitor->id]);
        Event::assertNotDispatched(LighthouseAuditCompleted::class);
    }
}
