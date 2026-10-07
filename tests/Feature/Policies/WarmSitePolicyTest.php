<?php

namespace Tests\Feature\Policies;

use App\Models\Team;
use App\Models\User;
use App\Models\WarmSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarmSitePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(?Team $team = null): User
    {
        $team ??= Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    private function createWarmSite(?Team $team = null): WarmSite
    {
        $team ??= Team::factory()->create();

        return WarmSite::factory()->for($team)->create();
    }

    // ──────────────────────────────────────────────────
    // Same team — allowed
    // ──────────────────────────────────────────────────

    public function test_user_can_view_own_team_warm_site(): void
    {
        $user = $this->createUser();
        $site = $this->createWarmSite($user->team);

        $this->assertTrue($user->can('view', $site));
    }

    public function test_user_can_update_own_team_warm_site(): void
    {
        $user = $this->createUser();
        $site = $this->createWarmSite($user->team);

        $this->assertTrue($user->can('update', $site));
    }

    public function test_user_can_delete_own_team_warm_site(): void
    {
        $user = $this->createUser();
        $site = $this->createWarmSite($user->team);

        $this->assertTrue($user->can('delete', $site));
    }

    public function test_user_can_warm_now_own_team_warm_site(): void
    {
        $user = $this->createUser();
        $site = $this->createWarmSite($user->team);

        $this->assertTrue($user->can('warmNow', $site));
    }

    // ──────────────────────────────────────────────────
    // Different team — denied
    // ──────────────────────────────────────────────────

    public function test_user_cannot_view_other_team_warm_site(): void
    {
        $user = $this->createUser();
        $site = $this->createWarmSite();

        $this->assertFalse($user->can('view', $site));
    }

    public function test_user_cannot_update_other_team_warm_site(): void
    {
        $user = $this->createUser();
        $site = $this->createWarmSite();

        $this->assertFalse($user->can('update', $site));
    }

    public function test_user_cannot_delete_other_team_warm_site(): void
    {
        $user = $this->createUser();
        $site = $this->createWarmSite();

        $this->assertFalse($user->can('delete', $site));
    }

    public function test_user_cannot_warm_now_other_team_warm_site(): void
    {
        $user = $this->createUser();
        $site = $this->createWarmSite();

        $this->assertFalse($user->can('warmNow', $site));
    }
}
