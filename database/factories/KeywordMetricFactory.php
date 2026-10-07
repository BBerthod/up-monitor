<?php

namespace Database\Factories;

use App\Models\KeywordMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeywordMetric>
 */
class KeywordMetricFactory extends Factory
{
    protected $model = KeywordMetric::class;

    public function definition(): array
    {
        $impressions = $this->faker->numberBetween(10, 5000);
        $clicks = $this->faker->numberBetween(0, (int) ($impressions * 0.1));

        return [
            'site' => 'example.com',
            'query' => $this->faker->unique()->words(3, true),
            'page' => 'https://example.com/'.$this->faker->slug(),
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 4) : 0,
            'position' => $this->faker->randomFloat(2, 1, 60),
            'captured_at' => now(),
        ];
    }
}
