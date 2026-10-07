<?php

namespace Database\Factories;

use App\Enums\WarmRunStatus;
use App\Models\WarmRun;
use App\Models\WarmSite;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarmRunFactory extends Factory
{
    protected $model = WarmRun::class;

    public function definition(): array
    {
        $urlsTotal = fake()->numberBetween(10, 50);
        $urlsHit = fake()->numberBetween(0, $urlsTotal);
        $urlsMiss = fake()->numberBetween(0, $urlsTotal - $urlsHit);
        $urlsError = $urlsTotal - $urlsHit - $urlsMiss;
        $startedAt = fake()->dateTimeBetween('-1 hour', '-5 minutes');

        return [
            'warm_site_id' => WarmSite::factory(),
            'urls_total' => $urlsTotal,
            'urls_hit' => $urlsHit,
            'urls_miss' => $urlsMiss,
            'urls_error' => $urlsError,
            'avg_response_ms' => fake()->numberBetween(50, 800),
            'status' => WarmRunStatus::COMPLETED,
            'error_message' => null,
            'started_at' => $startedAt,
            'completed_at' => fake()->dateTimeBetween($startedAt, 'now'),
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => WarmRunStatus::RUNNING,
            'urls_total' => 0,
            'urls_hit' => 0,
            'urls_miss' => 0,
            'urls_error' => 0,
            'avg_response_ms' => 0,
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => WarmRunStatus::FAILED,
            'error_message' => fake()->sentence(),
            'completed_at' => fake()->dateTimeBetween($attrs['started_at'] ?? '-1 hour', 'now'),
        ]);
    }
}
