<?php

namespace Tests\Feature\Console;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Heartbeat;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for heartbeats:check.
 *
 * The gap this closes: Up had a dead-man switch for servers and for its own
 * scheduler, but none for the jobs that keep the monitored sites correct —
 * wp-cron, nightly rebuilds, sitemap regeneration. That is how sitemaps in this
 * fleet went stale for months: nothing was down, nothing failed, a scheduled
 * task had simply stopped.
 */
class CheckHeartbeatsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function insights(): int
    {
        return Insight::withoutGlobalScopes()
            ->where('type', InsightType::HEARTBEAT_MISSED->value)
            ->count();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Overdue heartbeat trips the switch
    // ──────────────────────────────────────────────────────────────────────

    public function test_overdue_heartbeat_raises_an_insight(): void
    {
        $heartbeat = Heartbeat::factory()->overdue()->create(['name' => 'nightly rebuild']);

        $this->artisan('heartbeats:check')->assertExitCode(0);

        $this->assertSame(1, $this->insights());

        $insight = Insight::withoutGlobalScopes()->first();

        $this->assertStringContainsString('nightly rebuild', $insight->title);
        $this->assertSame($heartbeat->id, $insight->payload['heartbeat_id']);

        // Stamped so the next sweep stays quiet.
        $this->assertNotNull($heartbeat->fresh()->alerted_at);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. A heartbeat within its window is silent
    // ──────────────────────────────────────────────────────────────────────

    public function test_recent_ping_creates_no_insight(): void
    {
        Heartbeat::factory()->create(['last_ping_at' => now()->subMinutes(5)]);

        $this->artisan('heartbeats:check')->assertExitCode(0);

        $this->assertSame(0, $this->insights());
    }

    public function test_grace_period_is_respected(): void
    {
        // 60-minute period + 10 grace: 65 minutes late is still inside the window.
        Heartbeat::factory()->create(['last_ping_at' => now()->subMinutes(65)]);

        $this->artisan('heartbeats:check')->assertExitCode(0);

        $this->assertSame(0, $this->insights());
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Never pinged means unstarted — but only for so long
    // ──────────────────────────────────────────────────────────────────────

    public function test_heartbeat_that_never_pinged_is_not_alerted_while_still_being_wired_up(): void
    {
        Heartbeat::factory()->neverPinged()->create(['created_at' => now()->subDay()]);

        // Alerting here would fire the moment someone creates the record and
        // before they have wired the task up — which trains people to ignore
        // the alert, the one thing a dead-man switch cannot survive.
        $this->artisan('heartbeats:check')->assertExitCode(0);

        $this->assertSame(0, $this->insights());
    }

    public function test_heartbeat_that_never_pinged_is_alerted_once_the_wiring_grace_expires(): void
    {
        // Past the grace, "unstarted" stops being a plausible reading: three
        // sitemap heartbeats sat silent for eleven days in production because
        // nothing ever expired that excuse.
        Heartbeat::factory()->neverPinged()->create([
            'name' => 'sitemap regeneration',
            'created_at' => now()->subDays(11),
        ]);

        $this->artisan('heartbeats:check')->assertExitCode(0);

        $this->assertSame(1, $this->insights());

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::HEARTBEAT_MISSED->value)
            ->firstOrFail();

        // The wording has to point at the cron, not at the service: nothing is
        // down, the task was simply never instrumented.
        $this->assertStringContainsString('has never reported', $insight->title);
        $this->assertTrue($insight->payload['never_pinged']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. One insight per outage, not one per sweep
    // ──────────────────────────────────────────────────────────────────────

    public function test_repeated_sweeps_do_not_duplicate_the_insight(): void
    {
        Heartbeat::factory()->overdue()->create();

        $this->artisan('heartbeats:check');
        $this->artisan('heartbeats:check');
        $this->artisan('heartbeats:check');

        $this->assertSame(1, $this->insights());
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4b. A continuing outage is refreshed, not frozen at first detection
    // ──────────────────────────────────────────────────────────────────────

    public function test_alerted_at_is_preserved_while_age_and_severity_are_recomputed(): void
    {
        Carbon::setTestNow('2026-08-01 00:00:00');

        // 80 minutes late on a 60+10 window: WARNING (below the 130-minute
        // "two full periods" critical threshold).
        $heartbeat = Heartbeat::factory()->create([
            'expected_period_minutes' => 60,
            'grace_minutes' => 10,
            'last_ping_at' => now()->subMinutes(80),
        ]);

        $this->artisan('heartbeats:check');

        $firstInsight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::HEARTBEAT_MISSED->value)
            ->where('payload->heartbeat_id', $heartbeat->id)
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::WARNING, $firstInsight->severity);
        $this->assertSame(80, $firstInsight->payload['minutes_since_last_ping']);

        $firstAlertedAt = $heartbeat->fresh()->alerted_at;
        $firstDetectedAt = $firstInsight->detected_at;

        // 3 hours later, still no ping: 260 minutes late clears the 130-minute
        // critical threshold. Nothing about the heartbeat row itself changes
        // except the passage of time.
        Carbon::setTestNow('2026-08-01 03:00:00');

        $this->artisan('heartbeats:check');

        // Still exactly one insight — no duplicate raised for the continuing outage.
        $this->assertSame(1, $this->insights());

        $refreshed = Insight::withoutGlobalScopes()
            ->where('type', InsightType::HEARTBEAT_MISSED->value)
            ->where('payload->heartbeat_id', $heartbeat->id)
            ->firstOrFail();

        // First-detection stamps are untouched...
        $this->assertTrue($heartbeat->fresh()->alerted_at->equalTo($firstAlertedAt),
            'alerted_at must stay at the first detection, not reset on every sweep.');
        $this->assertTrue($refreshed->detected_at->equalTo($firstDetectedAt),
            'The insight detected_at must stay at the first detection.');

        // ...but the displayed age and severity must not stay frozen at their
        // first-seen values while the outage is still live.
        $this->assertEquals(InsightSeverity::CRITICAL, $refreshed->severity);
        $this->assertSame(260, $refreshed->payload['minutes_since_last_ping']);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Severity scales with periods missed, not absolute lateness
    // ──────────────────────────────────────────────────────────────────────

    public function test_long_outage_relative_to_period_is_critical(): void
    {
        // Two full periods past due.
        Heartbeat::factory()->create([
            'expected_period_minutes' => 60,
            'grace_minutes' => 10,
            'last_ping_at' => now()->subMinutes(200),
        ]);

        $this->artisan('heartbeats:check');

        $this->assertEquals(
            InsightSeverity::CRITICAL,
            Insight::withoutGlobalScopes()->first()->severity,
        );
    }

    public function test_slightly_late_is_only_a_warning(): void
    {
        Heartbeat::factory()->create([
            'expected_period_minutes' => 60,
            'grace_minutes' => 10,
            'last_ping_at' => now()->subMinutes(80),
        ]);

        $this->artisan('heartbeats:check');

        $this->assertEquals(
            InsightSeverity::WARNING,
            Insight::withoutGlobalScopes()->first()->severity,
        );
    }

    public function test_an_hour_late_means_different_things_on_different_periods(): void
    {
        // Same absolute lateness, opposite verdicts: an hour is nothing on a
        // daily job and a catastrophe on a five-minute one.
        Heartbeat::factory()->create([
            'name' => 'daily digest',
            'expected_period_minutes' => 1440,
            'grace_minutes' => 60,
            'last_ping_at' => now()->subMinutes(1500),
        ]);

        Heartbeat::factory()->create([
            'name' => 'queue drain',
            'expected_period_minutes' => 5,
            'grace_minutes' => 2,
            'last_ping_at' => now()->subMinutes(60),
        ]);

        $this->artisan('heartbeats:check');

        $daily = Insight::withoutGlobalScopes()->where('title', 'like', '%daily digest%')->first();
        $frequent = Insight::withoutGlobalScopes()->where('title', 'like', '%queue drain%')->first();

        $this->assertEquals(InsightSeverity::WARNING, $daily->severity);
        $this->assertEquals(InsightSeverity::CRITICAL, $frequent->severity);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Inactive heartbeats are ignored
    // ──────────────────────────────────────────────────────────────────────

    public function test_inactive_heartbeat_is_skipped(): void
    {
        Heartbeat::factory()->overdue()->create(['is_active' => false]);

        $this->artisan('heartbeats:check')->assertExitCode(0);

        $this->assertSame(0, $this->insights());
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. A site-attributed heartbeat carries its site
    // ──────────────────────────────────────────────────────────────────────

    public function test_insight_is_attributed_to_the_site_when_one_is_set(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => 'example.com',
        ]);

        Heartbeat::factory()->overdue()->create([
            'team_id' => $team->id,
            'site_id' => $site->id,
        ]);

        $this->artisan('heartbeats:check');

        $insight = Insight::withoutGlobalScopes()->first();

        $this->assertSame($site->id, $insight->site_id);
        $this->assertSame('example.com', $insight->site);
    }

    public function test_fleet_wide_heartbeat_has_no_site(): void
    {
        Heartbeat::factory()->overdue()->create(['site_id' => null]);

        $this->artisan('heartbeats:check');

        $insight = Insight::withoutGlobalScopes()->first();

        $this->assertNull($insight->site_id);
        $this->assertSame('portfolio', $insight->site);
    }
}
