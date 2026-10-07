<?php

namespace Database\Factories;

use App\Enums\ChannelType;
use App\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Test Email',
            'type' => ChannelType::EMAIL,
            'settings' => ['recipients' => fake()->email()],
            'is_active' => true,
        ];
    }

    public function webhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Test Webhook',
            'type' => ChannelType::WEBHOOK,
            'settings' => ['url' => fake()->url()],
        ]);
    }

    public function slack(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Test Slack',
            'type' => ChannelType::SLACK,
            'settings' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/XXX'],
        ]);
    }

    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Test Discord',
            'type' => ChannelType::DISCORD,
            'settings' => ['webhook_url' => 'https://discord.com/api/webhooks/00/XXX'],
        ]);
    }

    public function telegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Test Telegram',
            'type' => ChannelType::TELEGRAM,
            'settings' => ['bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11', 'chat_id' => '-1001234567890'],
        ]);
    }
}
