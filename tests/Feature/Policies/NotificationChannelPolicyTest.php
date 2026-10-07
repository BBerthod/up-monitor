<?php

namespace Tests\Feature\Policies;

use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationChannelPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(?Team $team = null): User
    {
        $team ??= Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    private function createChannel(?Team $team = null): NotificationChannel
    {
        $team ??= Team::factory()->create();

        return NotificationChannel::factory()->for($team)->create();
    }

    // ──────────────────────────────────────────────────
    // Same team — all verbs allowed
    // ──────────────────────────────────────────────────

    public function test_user_can_view_own_team_channel(): void
    {
        $user = $this->createUser();
        $channel = $this->createChannel($user->team);

        $this->assertTrue($user->can('view', $channel));
    }

    public function test_user_can_update_own_team_channel(): void
    {
        $user = $this->createUser();
        $channel = $this->createChannel($user->team);

        $this->assertTrue($user->can('update', $channel));
    }

    public function test_user_can_delete_own_team_channel(): void
    {
        $user = $this->createUser();
        $channel = $this->createChannel($user->team);

        $this->assertTrue($user->can('delete', $channel));
    }

    public function test_user_can_test_own_team_channel(): void
    {
        $user = $this->createUser();
        $channel = $this->createChannel($user->team);

        $this->assertTrue($user->can('test', $channel));
    }

    // ──────────────────────────────────────────────────
    // Different team — all verbs denied
    // ──────────────────────────────────────────────────

    public function test_user_cannot_view_other_team_channel(): void
    {
        $user = $this->createUser();
        $channel = $this->createChannel(); // different team

        $this->assertFalse($user->can('view', $channel));
    }

    public function test_user_cannot_update_other_team_channel(): void
    {
        $user = $this->createUser();
        $channel = $this->createChannel();

        $this->assertFalse($user->can('update', $channel));
    }

    public function test_user_cannot_delete_other_team_channel(): void
    {
        $user = $this->createUser();
        $channel = $this->createChannel();

        $this->assertFalse($user->can('delete', $channel));
    }

    public function test_user_cannot_test_other_team_channel(): void
    {
        $user = $this->createUser();
        $channel = $this->createChannel();

        $this->assertFalse($user->can('test', $channel));
    }
}
