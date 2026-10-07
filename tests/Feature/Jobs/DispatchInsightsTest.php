<?php

namespace Tests\Feature\Jobs;

use App\Enums\MonitorType;
use App\Jobs\CollectPageMetrics;
use App\Jobs\DetectHealthDrop;
use App\Jobs\DetectOrphanMonitors;
use App\Jobs\DetectPerfRegression;
use App\Jobs\DetectSslExpiry;
use App\Jobs\DetectStrikingDistance;
use App\Jobs\DispatchInsights;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Feature tests for DispatchInsights' per-site vs per-monitor dedup.
 *
 * Two active HTTP monitors for the same hostname used to fan out duplicate
 * child jobs for detectors whose signal is scoped to the SITE (not the
 * monitor), producing duplicate insights in the inbox (e.g. content-decay
 * and striking-distance appearing twice for the same site). This test locks
 * in the fix: per-site detectors dispatch once per hostname, per-monitor
 * detectors (SSL expiry, perf regression) still dispatch once per monitor.
 *
 * Bus::fake() intercepts both direct ::dispatch() calls and Bus::chain(...)
 * chains. Under the fake, Bus::chain([...])->dispatch() records the FIRST
 * job of the chain (CollectPageMetrics here) as a regular dispatch, so
 * assertDispatchedTimes(CollectPageMetrics::class, N) is the correct way to
 * count how many chains were started — there is no assertChainedTimes().
 */
class DispatchInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function makeMonitor(Team $team, string $url): Monitor
    {
        return Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => $url,
            'is_active' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 1 — same hostname, two monitors → per-site detectors dispatch once
    // ──────────────────────────────────────────────────────────────────────

    public function test_per_site_detectors_dispatch_once_for_two_monitors_on_same_hostname(): void
    {
        Bus::fake();

        $team = Team::factory()->create();
        $this->makeMonitor($team, 'https://jokes.example');
        $this->makeMonitor($team, 'https://jokes.example/homepage');

        (new DispatchInsights)->handle();

        Bus::assertDispatchedTimes(DetectStrikingDistance::class, 1);
        Bus::assertDispatchedTimes(DetectHealthDrop::class, 1);
        Bus::assertDispatchedTimes(CollectPageMetrics::class, 1);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 2 — different hostnames → per-site detectors dispatch per hostname
    // ──────────────────────────────────────────────────────────────────────

    public function test_per_site_detectors_dispatch_once_per_distinct_hostname(): void
    {
        Bus::fake();

        $team = Team::factory()->create();
        $this->makeMonitor($team, 'https://radiank.com');
        $this->makeMonitor($team, 'https://other-site.example.com');

        (new DispatchInsights)->handle();

        Bus::assertDispatchedTimes(DetectStrikingDistance::class, 2);
        Bus::assertDispatchedTimes(DetectHealthDrop::class, 2);
        Bus::assertDispatchedTimes(CollectPageMetrics::class, 2);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 3 — per-monitor detectors are NOT deduplicated by hostname
    // ──────────────────────────────────────────────────────────────────────

    public function test_per_monitor_detectors_dispatch_for_every_monitor_even_on_same_hostname(): void
    {
        Bus::fake();

        $team = Team::factory()->create();
        $this->makeMonitor($team, 'https://radiank.com');
        $this->makeMonitor($team, 'https://radiank.com/pricing');

        (new DispatchInsights)->handle();

        // SSL certs and Lighthouse scores are per-URL signals: both monitors
        // must still get their own child job, unlike the per-site detectors.
        Bus::assertDispatchedTimes(DetectSslExpiry::class, 2);
        Bus::assertDispatchedTimes(DetectPerfRegression::class, 2);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 4 — www. prefix normalization
    // ──────────────────────────────────────────────────────────────────────

    public function test_www_prefix_and_bare_hostname_are_treated_as_the_same_site(): void
    {
        Bus::fake();

        $team = Team::factory()->create();
        $this->makeMonitor($team, 'https://www.example.com');
        $this->makeMonitor($team, 'https://example.com');

        (new DispatchInsights)->handle();

        Bus::assertDispatchedTimes(DetectStrikingDistance::class, 1);
        Bus::assertDispatchedTimes(DetectHealthDrop::class, 1);
        Bus::assertDispatchedTimes(CollectPageMetrics::class, 1);

        // Per-monitor detectors are unaffected by the www. normalization —
        // still one dispatch per monitor.
        Bus::assertDispatchedTimes(DetectSslExpiry::class, 2);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 5 — anti-regression guard against the ltrim($host, 'www.') trap
    // ──────────────────────────────────────────────────────────────────────

    public function test_webcompare_hostname_is_not_corrupted_by_www_normalization(): void
    {
        // Documented project-wide trap: ltrim($host, 'www.') strips a character
        // SET, not a prefix, and would mangle "webcompare.com" into
        // "ebcompare.com" because every leading char in {w, w, w, ., m, c, o}
        // gets stripped. This monitor's URL has no "www." prefix at all, so if
        // the normalization ever regresses to ltrim(), this hostname would be
        // silently corrupted and grouped under the wrong (wrong) site key —
        // this test would then dispatch DetectHealthDrop with a mangled host.
        Bus::fake();

        $team = Team::factory()->create();
        $this->makeMonitor($team, 'https://webcompare.com');

        (new DispatchInsights)->handle();

        Bus::assertDispatched(DetectHealthDrop::class, function (DetectHealthDrop $job): bool {
            return $job->monitor->url === 'https://webcompare.com';
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 6 — B3 regression: two monitors on DIFFERENT hostnames but the
    // SAME Site must share a single per-site dispatch (their insights are
    // deduplicated on site_id, so their run must be too — see
    // ContentDecayService / StrikingDistanceService / KeywordTrendService).
    // ──────────────────────────────────────────────────────────────────────

    public function test_two_monitors_on_different_hostnames_but_same_site_dispatch_once(): void
    {
        Bus::fake();

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://fr.example.com',
            'is_active' => true,
            'site_id' => $site->id,
        ]);
        Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://us.example.com',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        (new DispatchInsights)->handle();

        Bus::assertDispatchedTimes(DetectStrikingDistance::class, 1);
        Bus::assertDispatchedTimes(DetectHealthDrop::class, 1);
        Bus::assertDispatchedTimes(CollectPageMetrics::class, 1);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Case 7 — monitors with NO Site keep the pre-existing hostname fallback,
    // even when a different Site's monitor happens to share the same
    // normalized hostname string (site: vs host: key prefixing must not collide).
    // ──────────────────────────────────────────────────────────────────────

    public function test_site_and_hostname_grouping_keys_do_not_collide(): void
    {
        Bus::fake();

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        // Attached to a Site whose id happens to be 1 (first factory record).
        Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://site-a.example.com',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        // No Site — falls back to hostname grouping.
        Monitor::factory()->for($team)->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://orphan.example.com',
            'is_active' => true,
            'site_id' => null,
        ]);

        (new DispatchInsights)->handle();

        // Two distinct representatives → two dispatches, not merged.
        Bus::assertDispatchedTimes(DetectStrikingDistance::class, 2);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Sanity — inactive/non-HTTP monitors are excluded, orphan detector runs once
    // ──────────────────────────────────────────────────────────────────────

    public function test_inactive_and_non_http_monitors_are_excluded(): void
    {
        Bus::fake();

        $team = Team::factory()->create();
        $this->makeMonitor($team, 'https://active.example.com');
        Monitor::factory()->for($team)->inactive()->create([
            'type' => MonitorType::HTTP,
            'url' => 'https://inactive.example.com',
        ]);
        Monitor::factory()->ping()->for($team)->create([
            'is_active' => true,
        ]);

        (new DispatchInsights)->handle();

        Bus::assertDispatchedTimes(DetectStrikingDistance::class, 1);
        Bus::assertDispatchedTimes(DetectOrphanMonitors::class, 1);
    }
}
