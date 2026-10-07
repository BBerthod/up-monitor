<?php

namespace Database\Factories;

use App\Models\WarmRun;
use App\Models\WarmRunUrl;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarmRunUrlFactory extends Factory
{
    protected $model = WarmRunUrl::class;

    public function definition(): array
    {
        return [
            'warm_run_id' => WarmRun::factory(),
            'url' => fake()->url(),
            'status_code' => 200,
            'cache_status' => fake()->randomElement(['hit', 'miss', 'unknown']),
            'response_time_ms' => fake()->numberBetween(50, 800),
            'error_message' => null,
        ];
    }

    public function error(): static
    {
        return $this->state(fn () => [
            'status_code' => 0,
            'cache_status' => 'unknown',
            'error_message' => 'Connection timed out',
        ]);
    }
}
