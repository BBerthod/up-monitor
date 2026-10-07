<?php

namespace Tests\Feature\Jobs;

use App\Enums\InsightType;
use App\Enums\WarmRunStatus;
use App\Jobs\RunWarmSite;
use App\Models\Insight;
use App\Models\Team;
use App\Models\User;
use App\Models\WarmRun;
use App\Models\WarmSite;
use App\Services\WarmingService;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RunWarmSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake DNS: example.com resolves to a public IP so SSRF guard passes.
        UrlSafetyValidator::setResolver(static fn (string $host, int $type): array => match ($type) {
            DNS_A => [['ip' => '93.184.216.34']],
            default => [],
        });
    }

    protected function tearDown(): void
    {
        UrlSafetyValidator::setResolver(null);
        parent::tearDown();
    }

    public function test_creates_warm_run_with_stats(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('OK', 200, [
                'cf-cache-status' => 'HIT',
            ]),
            'https://example.com/about' => Http::response('About', 200, [
                'cf-cache-status' => 'MISS',
            ]),
        ]);

        $site = WarmSite::factory()->create([
            'domain' => 'example.com',
            'urls' => [
                'https://example.com/',
                'https://example.com/about',
            ],
            'max_urls' => 50,
            'last_warmed_at' => null,
        ]);

        (new RunWarmSite($site))->handle(app(WarmingService::class));

        $this->assertDatabaseCount('warm_runs', 1);

        $run = WarmRun::first();
        $this->assertEquals(WarmRunStatus::COMPLETED, $run->status);
        $this->assertEquals(2, $run->urls_total);
        $this->assertEquals(1, $run->urls_hit);
        $this->assertEquals(1, $run->urls_miss);
        $this->assertEquals(0, $run->urls_error);
        $this->assertNotNull($run->completed_at);
        $this->assertNull($run->error_message);

        $site->refresh();
        $this->assertNotNull($site->last_warmed_at);
    }

    public function test_handles_errors_gracefully(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('OK', 200, [
                'cf-cache-status' => 'HIT',
            ]),
            'https://example.com/slow' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $site = WarmSite::factory()->create([
            'domain' => 'example.com',
            'urls' => [
                'https://example.com/',
                'https://example.com/slow',
            ],
            'max_urls' => 50,
            'last_warmed_at' => null,
        ]);

        (new RunWarmSite($site))->handle(app(WarmingService::class));

        $run = WarmRun::first();
        $this->assertEquals(WarmRunStatus::COMPLETED, $run->status);
        $this->assertEquals(2, $run->urls_total);
        $this->assertEquals(1, $run->urls_hit);
        $this->assertEquals(0, $run->urls_miss);
        $this->assertEquals(1, $run->urls_error);
    }

    public function test_creates_warm_run_url_records(): void
    {
        Http::fake([
            'https://example.com/page1' => Http::response('ok', 200, ['cf-cache-status' => 'HIT']),
            'https://example.com/page2' => Http::response('ok', 200, ['cf-cache-status' => 'MISS']),
        ]);

        $site = WarmSite::factory()->create([
            'domain' => 'example.com',
            'mode' => 'urls',
            'urls' => ['https://example.com/page1', 'https://example.com/page2'],
        ]);

        (new RunWarmSite($site))->handle(app(WarmingService::class));

        $this->assertDatabaseCount('warm_run_urls', 2);
        $this->assertDatabaseHas('warm_run_urls', [
            'url' => 'https://example.com/page1',
            'cache_status' => 'hit',
            'status_code' => 200,
        ]);
        $this->assertDatabaseHas('warm_run_urls', [
            'url' => 'https://example.com/page2',
            'cache_status' => 'miss',
        ]);
    }

    public function test_notifies_on_consecutive_errors(): void
    {
        Cache::flush();
        Notification::fake();

        Http::fake([
            'https://example.com/page1' => function () {
                throw new ConnectionException('timeout');
            },
            'https://example.com/page2' => function () {
                throw new ConnectionException('timeout');
            },
            'https://example.com/page3' => function () {
                throw new ConnectionException('timeout');
            },
        ]);

        $team = Team::factory()->create();
        $user = User::factory()->for($team)->create();
        $site = WarmSite::factory()->for($team)->create([
            'domain' => 'example.com',
            'mode' => 'urls',
            'urls' => ['https://example.com/page1', 'https://example.com/page2', 'https://example.com/page3'],
        ]);

        (new RunWarmSite($site))->handle(app(WarmingService::class));

        $run = WarmRun::where('warm_site_id', $site->id)->first();
        $this->assertNotNull($run->error_message);
        $this->assertStringContainsString('consecutive errors', $run->error_message);

        Notification::assertSentTo($user, \App\Notifications\WarmRunFailedNotification::class);
    }

    public function test_successful_run_resolves_open_warming_disabled_insight(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('OK', 200, ['cf-cache-status' => 'HIT']),
        ]);

        $team = Team::factory()->create();
        // "Warm Now" does not gate on is_active, so a still-disabled site can
        // complete a successful run — that must resolve the insight too.
        $site = WarmSite::factory()->for($team)->create([
            'domain' => 'example.com',
            'urls' => ['https://example.com/'],
            'is_active' => false,
        ]);

        $insight = Insight::factory()->for($team)->create([
            'type' => InsightType::WARMING_DISABLED->value,
            'payload' => ['warm_site_id' => $site->id, 'already_notified_directly' => true],
            'acknowledged_at' => null,
        ]);

        (new RunWarmSite($site))->handle(app(WarmingService::class));

        $this->assertEquals(WarmRunStatus::COMPLETED, WarmRun::where('warm_site_id', $site->id)->first()->status);

        $insight->refresh();
        $this->assertNotNull($insight->acknowledged_at);
    }

    public function test_skips_if_lock_unavailable(): void
    {
        $site = WarmSite::factory()->create();

        // Acquire the lock manually to simulate another process holding it
        $lock = Cache::lock("warming:{$site->id}", 120);
        $lock->get();

        try {
            (new RunWarmSite($site))->handle(app(WarmingService::class));

            $this->assertDatabaseCount('warm_runs', 0);
        } finally {
            $lock->release();
        }
    }
}
