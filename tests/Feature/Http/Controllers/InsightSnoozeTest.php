<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\SeoAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InsightSnoozeTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Basic snooze durations
    // ─────────────────────────────────────────────────────────────────────────

    public function test_snooze_1h_stamps_snoozed_until_one_hour_ahead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        $user = $this->createUserWithTeam();
        $insight = Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
        ]);

        $this->actingAs($user)
            ->post(route('insights.snooze', $insight), ['duration' => '1h'])
            ->assertRedirect();

        $insight->refresh();

        $this->assertNotNull($insight->snoozed_until);
        $this->assertEquals(
            Carbon::parse('2026-01-01 13:00:00')->toDateTimeString(),
            $insight->snoozed_until->toDateTimeString()
        );

        Carbon::setTestNow();
    }

    public function test_snooze_1d_stamps_snoozed_until_one_day_ahead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        $user = $this->createUserWithTeam();
        $insight = Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
        ]);

        $this->actingAs($user)
            ->post(route('insights.snooze', $insight), ['duration' => '1d'])
            ->assertRedirect();

        $insight->refresh();

        $this->assertEquals(
            Carbon::parse('2026-01-02 12:00:00')->toDateTimeString(),
            $insight->snoozed_until->toDateTimeString()
        );

        Carbon::setTestNow();
    }

    public function test_snooze_7d_stamps_snoozed_until_seven_days_ahead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        $user = $this->createUserWithTeam();
        $insight = Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
        ]);

        $this->actingAs($user)
            ->post(route('insights.snooze', $insight), ['duration' => '7d'])
            ->assertRedirect();

        $insight->refresh();

        $this->assertEquals(
            Carbon::parse('2026-01-08 12:00:00')->toDateTimeString(),
            $insight->snoozed_until->toDateTimeString()
        );

        Carbon::setTestNow();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. until_recovery semantics
    // ─────────────────────────────────────────────────────────────────────────

    public function test_until_recovery_allowed_for_server_health(): void
    {
        $user = $this->createUserWithTeam();
        $insight = Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::SERVER_HEALTH->value,
        ]);

        $this->actingAs($user)
            ->post(route('insights.snooze', $insight), ['duration' => 'until_recovery'])
            ->assertRedirect();

        $insight->refresh();

        $this->assertNotNull($insight->snoozed_until);
        // until_recovery sets snoozed_until ~1 year ahead — verify it is
        // future and reasonably far away (> 364 days from now).
        $this->assertTrue($insight->snoozed_until->isFuture());
        // Carbon 3: diffInDays is signed — diff from now() towards the future
        // date to get a positive value.
        $this->assertTrue(now()->diffInDays($insight->snoozed_until) >= 364);
    }

    public function test_until_recovery_rejected_for_non_server_health_type(): void
    {
        $user = $this->createUserWithTeam();
        $insight = Insight::factory()->unacknowledged()->create([
            'team_id' => $user->team_id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
        ]);

        $response = $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('insights.snooze', $insight), ['duration' => 'until_recovery']);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHasErrors('duration');

        $this->assertNull(
            Insight::withoutGlobalScopes()->find($insight->id)->snoozed_until
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Authorization
    // ─────────────────────────────────────────────────────────────────────────

    public function test_user_from_different_team_gets_404(): void
    {
        $userA = $this->createUserWithTeam();

        $teamB = Team::factory()->create();
        $insightB = Insight::factory()->unacknowledged()->create(['team_id' => $teamB->id]);

        $this->actingAs($userA)
            ->post(route('insights.snooze', $insightB->id), ['duration' => '1h'])
            ->assertStatus(404);

        $this->assertNull(
            Insight::withoutGlobalScopes()->find($insightB->id)->snoozed_until
        );
    }

    public function test_snooze_requires_authentication(): void
    {
        $insight = Insight::factory()->unacknowledged()->create();

        $this->post(route('insights.snooze', $insight), ['duration' => '1h'])
            ->assertRedirect(route('login'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. SeoAlertService ignores snoozed insights
    // ─────────────────────────────────────────────────────────────────────────

    public function test_seo_alert_service_skips_snoozed_insight(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        NotificationChannel::factory()->slack()->create(['team_id' => $team->id]);

        // Snoozed insight — should NOT trigger a notification.
        Insight::factory()->snoozed(now()->addHour())->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => null,
            'acknowledged_at' => null,
        ]);

        $dispatched = app(SeoAlertService::class)->dispatchForTeam($team);

        $this->assertEquals(0, $dispatched);
        Http::assertNothingSent();
    }

    public function test_seo_alert_service_dispatches_non_snoozed_insight(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $team = Team::factory()->create();
        NotificationChannel::factory()->slack()->create(['team_id' => $team->id]);

        // Active (non-snoozed) insight — should be dispatched.
        Insight::factory()->unacknowledged()->create([
            'team_id' => $team->id,
            'severity' => InsightSeverity::WARNING->value,
            'notified_at' => null,
            'snoozed_until' => null,
        ]);

        $dispatched = app(SeoAlertService::class)->dispatchForTeam($team);

        $this->assertEquals(1, $dispatched);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Scope: expired snooze re-admits the insight
    // ─────────────────────────────────────────────────────────────────────────

    public function test_not_snoozed_scope_reintegrates_insight_after_expiry(): void
    {
        $team = Team::factory()->create();

        // Insight whose snooze expired one second ago.
        $expired = Insight::factory()->create([
            'team_id' => $team->id,
            'snoozed_until' => now()->subSecond(),
        ]);

        // Insight still actively snoozed.
        $active = Insight::factory()->create([
            'team_id' => $team->id,
            'snoozed_until' => now()->addHour(),
        ]);

        // Insight never snoozed.
        $never = Insight::factory()->create([
            'team_id' => $team->id,
            'snoozed_until' => null,
        ]);

        $ids = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->notSnoozed()
            ->pluck('id');

        $this->assertContains($expired->id, $ids->all(), 'Expired snooze should pass through notSnoozed scope');
        $this->assertNotContains($active->id, $ids->all(), 'Active snooze should be filtered by notSnoozed scope');
        $this->assertContains($never->id, $ids->all(), 'Null snoozed_until should pass through notSnoozed scope');
    }
}
