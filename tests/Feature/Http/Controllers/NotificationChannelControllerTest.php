<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\ChannelType;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationChannelControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedUser(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    public function test_channels_index_returns_200_with_email_recipients_as_array(): void
    {
        $user = $this->createAuthenticatedUser();

        NotificationChannel::factory()->for($user->team)->create([
            'type' => ChannelType::EMAIL,
            'settings' => ['recipients' => ['alice@example.com', 'bob@example.com']],
        ]);

        $response = $this->actingAs($user)->get(route('channels.index'));

        $response->assertStatus(200);
    }

    public function test_channels_index_returns_200_with_email_recipients_as_string(): void
    {
        $user = $this->createAuthenticatedUser();

        NotificationChannel::factory()->for($user->team)->create([
            'type' => ChannelType::EMAIL,
            'settings' => ['recipients' => 'alice@example.com, bob@example.com'],
        ]);

        $response = $this->actingAs($user)->get(route('channels.index'));

        $response->assertStatus(200);
    }
}
