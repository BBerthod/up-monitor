<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsightIngestionTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    private function validPayload(): array
    {
        return [
            'type' => InsightType::SSL_EXPIRY->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'title' => 'SSL certificate expires in 7 days',
            'site' => 'example.com',
        ];
    }

    // ──────────────────────────────────────────────────
    // Happy path
    // ──────────────────────────────────────────────────

    public function test_creates_insight_with_source_monitor(): void
    {
        $user = $this->createUserWithTeam();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.insights.store'), $this->validPayload());

        $response->assertCreated();
        $response->assertJsonPath('data.source', 'monitor');
        $response->assertJsonPath('data.type', InsightType::SSL_EXPIRY->value);

        $this->assertDatabaseHas('insights', [
            'team_id' => $user->team_id,
            'type' => InsightType::SSL_EXPIRY->value,
            'source' => 'monitor',
        ]);
    }

    public function test_team_id_is_taken_from_authenticated_user(): void
    {
        $user = $this->createUserWithTeam();

        Sanctum::actingAs($user);

        $this->postJson(route('api.insights.store'), $this->validPayload());

        $this->assertDatabaseHas('insights', ['team_id' => $user->team_id]);
    }

    public function test_detected_at_defaults_to_now_when_omitted(): void
    {
        $user = $this->createUserWithTeam();

        Sanctum::actingAs($user);

        $this->postJson(route('api.insights.store'), $this->validPayload());

        $insight = Insight::first();
        $this->assertNotNull($insight->detected_at);
    }

    // ──────────────────────────────────────────────────
    // Validation errors
    // ──────────────────────────────────────────────────

    public function test_rejects_invalid_type(): void
    {
        $user = $this->createUserWithTeam();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.insights.store'), array_merge(
            $this->validPayload(),
            ['type' => 'not_a_real_type']
        ));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_rejects_missing_required_fields(): void
    {
        $user = $this->createUserWithTeam();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.insights.store'), []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['type', 'severity', 'title', 'site']);
    }

    // ──────────────────────────────────────────────────
    // Authentication
    // ──────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson(route('api.insights.store'), $this->validPayload());

        $response->assertUnauthorized();
    }

    // ──────────────────────────────────────────────────
    // Multi-tenant: site_id from another team is rejected
    // ──────────────────────────────────────────────────

    public function test_rejects_site_id_from_another_team(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $siteB = Site::factory()->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->postJson(route('api.insights.store'), array_merge(
            $this->validPayload(),
            ['site_id' => $siteB->id]
        ));

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.site_id.0', 'Invalid site_id.');
    }

    public function test_accepts_site_id_from_own_team(): void
    {
        $user = $this->createUserWithTeam();
        $site = Site::factory()->for($user->team)->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.insights.store'), array_merge(
            $this->validPayload(),
            ['site_id' => $site->id]
        ));

        $response->assertCreated();
        $this->assertDatabaseHas('insights', [
            'team_id' => $user->team_id,
            'site_id' => $site->id,
        ]);
    }
}
