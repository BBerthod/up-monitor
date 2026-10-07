<?php

namespace Tests\Feature\Http\Resources;

use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationChannelResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_bot_token_is_redacted_in_api_responses(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        Sanctum::actingAs($user);

        $secretToken = '7782663016:AAH4RGEqP1xP04FlUe1dSRyvkDMFDgaVZBs';
        NotificationChannel::factory()->for($team)->create([
            'type' => 'telegram',
            'settings' => [
                'bot_token' => $secretToken,
                'chat_id' => '8299922337',
            ],
        ]);

        $response = $this->getJson('/api/notification-channels');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString($secretToken, $body, 'bot_token must never appear in API response');
        $this->assertStringNotContainsString('AAH4RGEqP1xP04FlUe1dSRyvkDMFDgaVZBs', $body);
        // chat_id is operational metadata — kept visible
        $this->assertStringContainsString('8299922337', $body);
    }

    public function test_webhook_url_is_redacted(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        Sanctum::actingAs($user);

        $secretWebhook = 'https://hooks.slack.com/services/T0000/B0000/abc123def456ghi789jkl';
        NotificationChannel::factory()->for($team)->create([
            'type' => 'slack',
            'settings' => ['webhook_url' => $secretWebhook],
        ]);

        $response = $this->getJson('/api/notification-channels');

        $response->assertOk();
        $this->assertStringNotContainsString($secretWebhook, $response->getContent());
        $this->assertStringNotContainsString('abc123def456ghi789jkl', $response->getContent());
    }

    public function test_safe_settings_redacts_long_secrets_with_partial_visibility(): void
    {
        $channel = NotificationChannel::factory()->make([
            'type' => 'telegram',
            'settings' => ['bot_token' => '1234567890:AAH4RGEqP1xP04FlUe1dSRyvkDMFDgaVZBs', 'chat_id' => '999'],
        ]);

        $safe = $channel->safeSettings();

        $this->assertNotEquals($channel->settings['bot_token'], $safe['bot_token']);
        $this->assertStringStartsWith('1234', $safe['bot_token']);
        $this->assertStringEndsWith('VZBs', $safe['bot_token']);
        $this->assertEquals('999', $safe['chat_id']);
    }

    public function test_safe_settings_fully_redacts_short_secrets(): void
    {
        $channel = NotificationChannel::factory()->make([
            'type' => 'webhook',
            'settings' => ['secret' => 'short'],
        ]);

        $this->assertEquals('[redacted]', $channel->safeSettings()['secret']);
    }
}
