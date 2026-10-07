<?php

namespace Database\Factories;

use App\Models\PageMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

class PageMetricFactory extends Factory
{
    protected $model = PageMetric::class;

    public function definition(): array
    {
        $impressions = fake()->randomFloat(2, 50, 50_000);
        $clicks = fake()->randomFloat(2, 0, $impressions * 0.20);
        $ctr = $impressions > 0 ? round($clicks / $impressions * 100, 4) : 0;

        return [
            'site' => fake()->domainName(),
            'page' => 'https://'.fake()->domainName().'/'.fake()->slug(),
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $ctr,
            'position' => fake()->randomFloat(2, 1, 50),
            'captured_at' => now(),
        ];
    }

    /**
     * Snapshot captured N days ago — useful for building decay fixtures.
     */
    public function daysAgo(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'captured_at' => now()->subDays($days),
        ]);
    }

    /**
     * A "healthy" page: high impressions, solid CTR.
     */
    public function healthy(): static
    {
        return $this->state(function (array $attributes): array {
            $impressions = fake()->randomFloat(2, 5_000, 50_000);
            $clicks = fake()->randomFloat(2, $impressions * 0.05, $impressions * 0.15);

            return [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => round($clicks / $impressions * 100, 4),
            ];
        });
    }

    /**
     * A "decayed" page: low impressions and clicks, poor CTR.
     */
    public function decayed(): static
    {
        return $this->state(function (array $attributes): array {
            $impressions = fake()->randomFloat(2, 50, 500);
            $clicks = fake()->randomFloat(2, 0, $impressions * 0.03);

            return [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => round($clicks / $impressions * 100, 4),
            ];
        });
    }
}
