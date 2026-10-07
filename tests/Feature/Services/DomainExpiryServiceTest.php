<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Services\DomainExpiryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for DomainExpiryService::checkDomain() and detectForSite().
 *
 * RDAP calls are always mocked via Http::fake() — no real network calls.
 * URL pattern matched: https://rdap.org/domain/{domain}
 *
 * Site convention: primary_domain = 'example.com', active, team-scoped.
 * Insight idempotence: delete-before-insert on (site_id, type) for
 * unacknowledged rows only; acknowledged insights are never touched.
 */
class DomainExpiryServiceTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function rdapFake(Carbon $expiresAt): array
    {
        return [
            'rdap.org/*' => Http::response([
                'events' => [
                    ['eventAction' => 'expiration', 'eventDate' => $expiresAt->toIso8601String()],
                ],
            ], 200),
        ];
    }

    private function rdapNoExpiryEvent(): array
    {
        return [
            'rdap.org/*' => Http::response([
                'events' => [
                    ['eventAction' => 'registration', 'eventDate' => now()->subYears(3)->toIso8601String()],
                ],
            ], 200),
        ];
    }

    private function rdapError(): array
    {
        return ['rdap.org/*' => Http::response(null, 500)];
    }

    private function makeSite(Team $team, string $domain = 'example.com'): Site
    {
        return Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => $domain,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Expiry in 5 days → CRITICAL
    // -------------------------------------------------------------------------

    public function test_expiry_in_5_days_creates_critical_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);

        Http::fake($this->rdapFake(now()->addDays(5)));

        $count = (new DomainExpiryService)->detectForSite($site);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::DOMAIN_EXPIRY->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertEquals($team->id, $insight->team_id);
        $this->assertEqualsWithDelta(5, $insight->payload['days_remaining'], 1);
    }

    // -------------------------------------------------------------------------
    // Expiry in 20 days → WARNING
    // -------------------------------------------------------------------------

    public function test_expiry_in_20_days_creates_warning_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);

        Http::fake($this->rdapFake(now()->addDays(20)));

        $count = (new DomainExpiryService)->detectForSite($site);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::DOMAIN_EXPIRY->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
    }

    // -------------------------------------------------------------------------
    // Healthy domain (200 days) → 0 insights, timestamps stamped
    // -------------------------------------------------------------------------

    public function test_healthy_domain_creates_no_insight_but_stamps_timestamps(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);

        Http::fake($this->rdapFake(now()->addDays(200)));

        $count = (new DomainExpiryService)->detectForSite($site);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);

        $site->refresh();
        $this->assertNotNull($site->domain_expiry_checked_at);
        $this->assertNotNull($site->domain_expires_at);
    }

    // -------------------------------------------------------------------------
    // Expired domain (-3 days) → CRITICAL, title contains EXPIRED
    // -------------------------------------------------------------------------

    public function test_expired_domain_creates_critical_insight_with_expired_title(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);

        Http::fake($this->rdapFake(now()->subDays(3)));

        $count = (new DomainExpiryService)->detectForSite($site);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::DOMAIN_EXPIRY->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertStringContainsStringIgnoringCase('EXPIRED', $insight->title);
    }

    // -------------------------------------------------------------------------
    // RDAP returns no expiration event → 0 insights, checked_at stamped, expires_at null
    // -------------------------------------------------------------------------

    public function test_rdap_no_expiry_event_returns_zero_and_leaves_expires_at_null(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);

        Http::fake($this->rdapNoExpiryEvent());

        $count = (new DomainExpiryService)->detectForSite($site);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);

        $site->refresh();
        $this->assertNotNull($site->domain_expiry_checked_at);
        $this->assertNull($site->domain_expires_at);
    }

    // -------------------------------------------------------------------------
    // RDAP HTTP 500 → 0 insights, no crash
    // -------------------------------------------------------------------------

    public function test_rdap_http_error_returns_zero_without_crash(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);

        Http::fake($this->rdapError());

        $count = (new DomainExpiryService)->detectForSite($site);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('insights', 0);
    }

    // -------------------------------------------------------------------------
    // Idempotence: 2 runs with same 5d data → 1 unacknowledged insight
    // -------------------------------------------------------------------------

    public function test_idempotent_two_runs_produce_one_unacknowledged_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);

        Http::fake($this->rdapFake(now()->addDays(5)));
        (new DomainExpiryService)->detectForSite($site);

        Http::fake($this->rdapFake(now()->addDays(5)));
        (new DomainExpiryService)->detectForSite($site);

        $count = Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::DOMAIN_EXPIRY->value)
            ->whereNull('acknowledged_at')
            ->count();

        $this->assertSame(1, $count, 'Expected exactly 1 unacknowledged DOMAIN_EXPIRY insight after 2 runs.');
    }

    // -------------------------------------------------------------------------
    // Recovery: alert created (5d), then healthy (200d) → unacknowledged insight removed
    //
    // Http::fake() with the same key does NOT replace an existing fake mid-test.
    // Use Http::sequence() to return different responses for sequential calls.
    // -------------------------------------------------------------------------

    public function test_recovery_removes_unacknowledged_insight(): void
    {
        $team = Team::factory()->create();
        $site = $this->makeSite($team);

        // Sequence: first call returns 5d, second call returns 200d.
        Http::fake([
            'rdap.org/*' => Http::sequence()
                ->push(
                    ['events' => [['eventAction' => 'expiration', 'eventDate' => now()->addDays(5)->toIso8601String()]]],
                    200
                )
                ->push(
                    ['events' => [['eventAction' => 'expiration', 'eventDate' => now()->addDays(200)->toIso8601String()]]],
                    200
                ),
        ]);

        $service = new DomainExpiryService;

        // Run 1: 5 days → CRITICAL insight created.
        $service->detectForSite($site);

        $this->assertSame(1, Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::DOMAIN_EXPIRY->value)
            ->whereNull('acknowledged_at')->count());

        // Run 2: 200 days → healthy, the previous unacknowledged insight must be deleted.
        $service->detectForSite($site);

        $this->assertSame(0, Insight::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('type', InsightType::DOMAIN_EXPIRY->value)
            ->whereNull('acknowledged_at')->count(),
            'Unacknowledged DOMAIN_EXPIRY insight must be removed after recovery.');
    }
}
