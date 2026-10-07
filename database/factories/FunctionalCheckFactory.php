<?php

namespace Database\Factories;

use App\Enums\FunctionalCheckStatus;
use App\Enums\FunctionalCheckType;
use App\Models\FunctionalCheck;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

class FunctionalCheckFactory extends Factory
{
    protected $model = FunctionalCheck::class;

    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'name' => fake()->words(3, true),
            'url' => '/'.fake()->slug(),
            'type' => FunctionalCheckType::CONTENT,
            'rules' => [
                ['type' => 'text_absent', 'value' => 'Fatal error'],
            ],
            'check_interval' => 60,
            'last_status' => FunctionalCheckStatus::PENDING,
            'is_enabled' => true,
        ];
    }

    public function content(): static
    {
        return $this->state(fn () => ['type' => FunctionalCheckType::CONTENT]);
    }

    public function redirect(): static
    {
        return $this->state(fn () => ['type' => FunctionalCheckType::REDIRECT]);
    }

    public function sitemap(): static
    {
        return $this->state(fn () => [
            'type' => FunctionalCheckType::SITEMAP,
            'url' => '/sitemap.xml',
            'check_interval' => 1440,
        ]);
    }

    public function robotsTxt(): static
    {
        return $this->state(fn () => [
            'type' => FunctionalCheckType::ROBOTS_TXT,
            'url' => '/robots.txt',
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_enabled' => false]);
    }
}
