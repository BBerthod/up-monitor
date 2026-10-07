<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Services\DigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for DigestController.
 *
 * DigestService is mocked where needed so tests do not trigger AI calls or
 * heavyweight service computation (WeeklyReportService, HealthScoreService,
 * WhatChangedService all hit the DB). The cache behaviour (second call must not
 * regenerate) is the primary behavioural contract tested here.
 *
 * MOCKING STRATEGY
 * All tests that fire multiple HTTP requests within the same test method bind a
 * SINGLE mock ONCE before any request.  Re-binding via app()->instance() between
 * two requests in the same test is unreliable: the container may return the
 * previously-resolved instance because the singleton HTTP kernel holds references
 * that are not updated by a mid-test rebind.  Using a single mock avoids this.
 */
class DigestControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    /** Build the canonical DigestService return payload. */
    private function digestPayload(array $overrides = []): array
    {
        return array_merge([
            'team_name' => 'Test Team',
            'period_start' => now()->subWeek(),
            'period_end' => now(),
            'narrative' => 'Mocked narrative',
            'health' => [],
            'what_changed' => [],
            'top_opportunities' => [],
            'server_health' => [],
            'report' => [
                'period_start' => now()->subWeek(),
                'period_end' => now(),
                'overall_uptime' => 100.0,
                'incident_count' => 0,
                'monitors' => [],
            ],
            'alerts' => ['core_update_suspected' => false, 'ga4_broken_sites' => []],
            'action_plan' => ['actions' => [], 'total_actionable' => 0, 'generated_at' => now()->toIso8601String()],
        ], $overrides);
    }

    private function bindMockDigestService(array $overrides = []): void
    {
        $mock = $this->createMock(DigestService::class);
        $mock->method('generateForTeam')->willReturn($this->digestPayload($overrides));
        $this->app->instance(DigestService::class, $mock);
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('digest.index'))->assertRedirect(route('login'));
    }

    // ── Payload structure ─────────────────────────────────────────────────────

    public function test_renders_digest_page_with_required_props(): void
    {
        $user = $this->createUserWithTeam();
        $this->bindMockDigestService();

        $this->actingAs($user)->get(route('digest.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Digest')
                ->has('digest')
                ->has('generated_at')
            );
    }

    public function test_digest_prop_contains_narrative_and_core_keys(): void
    {
        $user = $this->createUserWithTeam();
        $this->bindMockDigestService(['narrative' => 'Test narrative']);

        $this->actingAs($user)->get(route('digest.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('digest.team_name')
                ->has('digest.narrative')
                ->has('digest.health')
                ->has('digest.what_changed')
                ->has('digest.top_opportunities')
                ->has('digest.server_health')
                ->has('digest.alerts')
                ->has('digest.action_plan')
                ->where('digest.narrative', 'Test narrative')
            );
    }

    // ── Cache behaviour ───────────────────────────────────────────────────────

    public function test_second_call_does_not_regenerate_digest(): void
    {
        $user = $this->createUserWithTeam();

        // Single mock bound ONCE — generateForTeam must be called EXACTLY ONCE
        // across two page loads.  The second load must hit the cache.
        $mock = $this->createMock(DigestService::class);
        $mock->expects($this->once())
            ->method('generateForTeam')
            ->willReturn($this->digestPayload(['narrative' => 'Once']));

        $this->app->instance(DigestService::class, $mock);

        // First load — triggers generation and primes the cache.
        $this->actingAs($user)->get(route('digest.index'))->assertOk();

        // Second load — must serve from cache, NOT call generateForTeam again.
        $this->actingAs($user)->get(route('digest.index'))->assertOk();
    }

    public function test_cache_is_keyed_per_team(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        // Single mock bound ONCE handles both teams.
        // If cache keys were shared, generateForTeam would only be called ONCE
        // (second request hits the first team's cache entry).
        // expects($this->exactly(2)) catches that regression.
        $mock = $this->createMock(DigestService::class);
        $mock->expects($this->exactly(2))
            ->method('generateForTeam')
            ->willReturnCallback(fn (object $team) => $this->digestPayload([
                'team_name' => $team->name,
                'narrative' => "narrative for {$team->name}",
            ]));

        $this->app->instance(DigestService::class, $mock);

        // Team A — cache miss, generates and caches under digest:view:{teamA_id}.
        $responseA = $this->actingAs($userA)->get(route('digest.index'));
        $responseA->assertOk();

        // Team B — different cache key, generates again.
        $responseB = $this->actingAs($userB)->get(route('digest.index'));
        $responseB->assertOk();

        // Each team gets its own narrative (proves different generation, not cache hit).
        $responseA->assertInertia(fn ($p) => $p->where('digest.team_name', $userA->team->name));
        $responseB->assertInertia(fn ($p) => $p->where('digest.team_name', $userB->team->name));
    }

    // ── Error fallback ────────────────────────────────────────────────────────

    public function test_returns_minimal_digest_when_service_throws(): void
    {
        $user = $this->createUserWithTeam();

        $mock = $this->createMock(DigestService::class);
        $mock->method('generateForTeam')->willThrowException(new \RuntimeException('AI down'));
        $this->app->instance(DigestService::class, $mock);

        $this->actingAs($user)->get(route('digest.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Digest')
                ->has('digest')
                ->where('digest.narrative', null)
            );
    }

    public function test_error_result_is_not_cached(): void
    {
        $user = $this->createUserWithTeam();

        // Single mock bound ONCE: call 1 throws, call 2 returns 'Recovery'.
        // If the error were cached, call 2 would never happen and
        // expects($this->exactly(2)) would fail — catching the regression.
        $mock = $this->createMock(DigestService::class);
        $mock->expects($this->exactly(2))
            ->method('generateForTeam')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('AI down')),
                $this->returnValue($this->digestPayload(['narrative' => 'Recovery']))
            );

        $this->app->instance(DigestService::class, $mock);

        // First load — service throws, returns null narrative, NOT cached.
        $this->actingAs($user)->get(route('digest.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('digest.narrative', null));

        // Second load — cache is empty (error not cached), service called again,
        // this time successfully.
        $this->actingAs($user)->get(route('digest.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('digest.narrative', 'Recovery'));
    }

    // ── No team guard ─────────────────────────────────────────────────────────

    public function test_user_without_team_receives_null_digest(): void
    {
        $user = User::factory()->create(['team_id' => null]);

        $this->actingAs($user)->get(route('digest.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('digest', null)
                ->where('generated_at', null)
            );
    }
}
