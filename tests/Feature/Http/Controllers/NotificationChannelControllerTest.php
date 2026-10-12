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

    public function test_ntfy_channel_can_be_created_with_on_call_flag(): void
    {
        $user = $this->createAuthenticatedUser();

        $response = $this->actingAs($user)->post(route('channels.store'), [
            'name' => 'Astreinte',
            'type' => 'ntfy',
            'settings' => ['topic' => 'up-alerts_1', 'oncall' => true],
            'is_active' => true,
        ]);

        $response->assertRedirect(route('channels.index'));
        $channel = NotificationChannel::where('name', 'Astreinte')->firstOrFail();
        $this->assertSame(ChannelType::NTFY, $channel->type);
        $this->assertTrue($channel->settings['oncall']);
    }

    public function test_ntfy_channel_rejects_invalid_topic(): void
    {
        $user = $this->createAuthenticatedUser();

        $response = $this->actingAs($user)->post(route('channels.store'), [
            'name' => 'Astreinte',
            'type' => 'ntfy',
            'settings' => ['topic' => 'bad topic/../x'],
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('settings.topic');
    }

    public function test_channels_index_lists_ntfy_channels(): void
    {
        $user = $this->createAuthenticatedUser();
        NotificationChannel::factory()->ntfy(oncall: true)->for($user->team)->create();

        $this->actingAs($user)->get(route('channels.index'))->assertStatus(200);
    }
}
