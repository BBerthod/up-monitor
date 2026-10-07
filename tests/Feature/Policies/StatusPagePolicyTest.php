<?php

namespace Tests\Feature\Policies;

use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusPagePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(?Team $team = null): User
    {
        $team ??= Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    private function createStatusPage(?Team $team = null): StatusPage
    {
        $team ??= Team::factory()->create();

        return StatusPage::factory()->for($team)->create();
    }

    // ──────────────────────────────────────────────────
    // Same team — allowed
    // ──────────────────────────────────────────────────

    public function test_user_can_view_own_team_status_page(): void
    {
        $user = $this->createUser();
        $page = $this->createStatusPage($user->team);

        $this->assertTrue($user->can('view', $page));
    }

    public function test_user_can_update_own_team_status_page(): void
    {
        $user = $this->createUser();
        $page = $this->createStatusPage($user->team);

        $this->assertTrue($user->can('update', $page));
    }

    public function test_user_can_delete_own_team_status_page(): void
    {
        $user = $this->createUser();
        $page = $this->createStatusPage($user->team);

        $this->assertTrue($user->can('delete', $page));
    }

    // ──────────────────────────────────────────────────
    // Different team — denied
    // ──────────────────────────────────────────────────

    public function test_user_cannot_view_other_team_status_page(): void
    {
        $user = $this->createUser();
        $page = $this->createStatusPage();

        $this->assertFalse($user->can('view', $page));
    }

    public function test_user_cannot_update_other_team_status_page(): void
    {
        $user = $this->createUser();
        $page = $this->createStatusPage();

        $this->assertFalse($user->can('update', $page));
    }

    public function test_user_cannot_delete_other_team_status_page(): void
    {
        $user = $this->createUser();
        $page = $this->createStatusPage();

        $this->assertFalse($user->can('delete', $page));
    }
}
