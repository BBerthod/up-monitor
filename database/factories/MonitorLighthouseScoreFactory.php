<?php

namespace Database\Factories;

use App\Models\Monitor;
use App\Models\MonitorLighthouseScore;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonitorLighthouseScoreFactory extends Factory
{
    protected $model = MonitorLighthouseScore::class;

    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'performance' => fake()->numberBetween(0, 100),
            'accessibility' => fake()->numberBetween(0, 100),
            'best_practices' => fake()->numberBetween(0, 100),
            'seo' => fake()->numberBetween(0, 100),
            'lcp' => fake()->randomFloat(1, 0.5, 10.0),
            'fcp' => fake()->randomFloat(1, 0.2, 5.0),
            'cls' => fake()->randomFloat(4, 0.0, 0.5),
            'tbt' => fake()->randomFloat(1, 0, 600),
            'speed_index' => fake()->randomFloat(1, 0.5, 10.0),
            'scored_at' => now(),
        ];
    }
}
