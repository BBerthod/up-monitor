<?php

namespace Database\Factories;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

class KpiSnapshotFactory extends Factory
{
    protected $model = KpiSnapshot::class;

    public function definition(): array
    {
        return [
            'site' => fake()->domainName(),
            'source' => KpiSource::GSC->value,
            'metric' => fake()->randomElement(['impressions_28d', 'clicks_28d', 'ctr_28d', 'position_28d']),
            'value' => fake()->randomFloat(2, 100, 100_000),
            'period_days' => 28,
            'captured_at' => now(),
            'meta' => null,
        ];
    }

    public function ttfb(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => KpiSource::TTFB->value,
            'metric' => fake()->randomElement(['ttfb_p50_ms', 'ttfb_p95_ms']),
            'value' => fake()->randomFloat(2, 100, 5000),
            'period_days' => 1,
        ]);
    }
}
