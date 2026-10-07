<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\MonitorType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Services\SslExpiryDetector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for SslExpiryDetector::detectForMonitor().
 *
 * Thresholds:
 *   ≤3 days remaining (or already expired) → CRITICAL
 *   ≤14 days                               → WARNING
 *   >14 days                               → silent
 *
 * Only HTTP monitors with an https:// URL are evaluated.
 */
class SslExpiryDetectorTest extends TestCase
{
    use RefreshDatabase;

    private SslExpiryDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(SslExpiryDetector::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function httpsMonitor(?Team $team = null): Monitor
    {
        $team ??= Team::factory()->create();

        return Monitor::factory()->create([
            'team_id' => $team->id,
            'type' => MonitorType::HTTP->value,
            'url' => 'https://example.com',
        ]);
    }

    private function addCheck(Monitor $monitor, mixed $sslExpiresAt, mixed $checkedAt = null): MonitorCheck
    {
        return MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'ssl_expires_at' => $sslExpiresAt,
            'checked_at' => $checkedAt ?? now(),
            'status' => 'up',
        ]);
    }

    // ── CRITICAL — ≤3 days remaining ─────────────────────────────────────

    public function test_creates_critical_insight_when_ssl_expires_in_2_days(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->httpsMonitor($team);
        $this->addCheck($monitor, now()->addDays(2));

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::SSL_EXPIRY->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertEquals(InsightType::SSL_EXPIRY, $insight->type);
        $this->assertEquals($team->id, $insight->team_id);
    }

    // ── WARNING — ≤14 days remaining ─────────────────────────────────────

    public function test_creates_warning_insight_when_ssl_expires_in_10_days(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->httpsMonitor($team);
        $this->addCheck($monitor, now()->addDays(10));

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::SSL_EXPIRY->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    // ── Silent — >14 days remaining ──────────────────────────────────────

    public function test_creates_no_insight_when_ssl_expires_in_60_days(): void
    {
        $monitor = $this->httpsMonitor();
        $this->addCheck($monitor, now()->addDays(60));

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ── CRITICAL — already expired ────────────────────────────────────────

    public function test_creates_critical_insight_when_ssl_already_expired(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->httpsMonitor($team);
        $this->addCheck($monitor, now()->subDay());

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::SSL_EXPIRY->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertStringContainsStringIgnoringCase('EXPIRED', $insight->title);
    }

    // ── Guard — plain HTTP monitor skipped ───────────────────────────────

    public function test_creates_no_insight_for_plain_http_monitor(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'team_id' => $team->id,
            'type' => MonitorType::HTTP->value,
            'url' => 'http://example.com',
        ]);

        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'ssl_expires_at' => now()->addDays(2),
            'checked_at' => now(),
            'status' => 'up',
        ]);

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ── Guard — no ssl_expires_at on any check ────────────────────────────

    public function test_creates_no_insight_when_no_check_has_ssl_expires_at(): void
    {
        $monitor = $this->httpsMonitor();

        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'ssl_expires_at' => null,
            'checked_at' => now(),
        ]);

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // ── Idempotence — second run → exactly 1 unacknowledged insight ──────

    public function test_two_consecutive_runs_produce_exactly_one_unacknowledged_insight(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->httpsMonitor($team);
        $this->addCheck($monitor, now()->addDays(2));

        $this->detector->detectForMonitor($monitor);
        $this->detector->detectForMonitor($monitor);

        $count = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::SSL_EXPIRY->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(1, $count, 'Expected exactly 1 unacknowledged SSL_EXPIRY insight after 2 runs.');
    }

    public function test_detected_at_is_preserved_across_runs_while_severity_and_payload_are_recomputed(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->httpsMonitor($team);

        Carbon::setTestNow('2026-08-01 08:00:00');

        $this->addCheck($monitor, now()->addDays(10));

        $this->detector->detectForMonitor($monitor);

        $firstInsight = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::SSL_EXPIRY->value)
            ->whereNull('acknowledged_at')
            ->firstOrFail();

        $this->assertEquals(InsightSeverity::WARNING, $firstInsight->severity);
        $this->assertSame(10, $firstInsight->payload['days_remaining']);
        $firstDetectedAt = $firstInsight->detected_at;

        Carbon::setTestNow('2026-08-08 08:00:00');

        $this->addCheck($monitor, now()->addDays(2));

        $this->detector->detectForMonitor($monitor);

        $refreshed = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::SSL_EXPIRY->value)
            ->whereNull('acknowledged_at')
            ->get();

        $this->assertCount(1, $refreshed);

        $refreshedInsight = $refreshed->first();

        $this->assertTrue($refreshedInsight->detected_at->equalTo($firstDetectedAt));
        $this->assertEquals(InsightSeverity::CRITICAL, $refreshedInsight->severity);
        $this->assertSame(2, $refreshedInsight->payload['days_remaining']);

        Carbon::setTestNow();
    }

    // ── Idempotence — previously acknowledged insight is preserved ────────

    public function test_acknowledged_insight_from_previous_run_is_preserved(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->httpsMonitor($team);

        $acknowledged = Insight::factory()
            ->acknowledged()
            ->ofType(InsightType::SSL_EXPIRY)
            ->create(['team_id' => $team->id, 'monitor_id' => $monitor->id]);

        $this->addCheck($monitor, now()->addDays(2));

        $this->detector->detectForMonitor($monitor);
        $this->detector->detectForMonitor($monitor);

        $this->assertDatabaseHas('insights', ['id' => $acknowledged->id]);

        $unacknowledgedCount = Insight::withoutGlobalScopes()
            ->where('monitor_id', $monitor->id)
            ->where('type', InsightType::SSL_EXPIRY->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(1, $unacknowledgedCount);
    }

    // ── Latest check wins ────────────────────────────────────────────────

    public function test_uses_most_recent_check_ssl_date(): void
    {
        $team = Team::factory()->create();
        $monitor = $this->httpsMonitor($team);

        // Older check has a critical expiry.
        $this->addCheck($monitor, now()->addDays(2), now()->subHour());
        // Newer check has a healthy expiry — must win.
        $this->addCheck($monitor, now()->addDays(60), now());

        $count = $this->detector->detectForMonitor($monitor);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }
}
