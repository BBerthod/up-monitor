<?php

namespace Database\Factories;

use App\Enums\CheckStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonitorCheckFactory extends Factory
{
    protected $model = MonitorCheck::class;

    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'status' => CheckStatus::UP,
            'response_time_ms' => fake()->numberBetween(50, 2000),
            'status_code' => 200,
            'checked_at' => now(),
        ];
    }

    public function down(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CheckStatus::DOWN,
            'status_code' => 500,
        ]);
    }
}
