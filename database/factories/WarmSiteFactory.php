<?php

namespace Database\Factories;

use App\Enums\WarmSiteMode;
use App\Models\Team;
use App\Models\WarmSite;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarmSiteFactory extends Factory
{
    protected $model = WarmSite::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->company().' Cache',
            'domain' => fake()->domainName(),
            'mode' => WarmSiteMode::URLS,
            'sitemap_url' => null,
            'urls' => [
                'https://'.fake()->domainName().'/',
                'https://'.fake()->domainName().'/about',
                'https://'.fake()->domainName().'/contact',
            ],
            'frequency_minutes' => fake()->randomElement([30, 60, 120, 240]),
            'max_urls' => fake()->randomElement([25, 50, 100]),
            'is_active' => true,
            'last_warmed_at' => null,
        ];
    }

    public function sitemap(): static
    {
        return $this->state(fn (array $attrs) => [
            'mode' => WarmSiteMode::SITEMAP,
            'sitemap_url' => 'https://'.$attrs['domain'].'/sitemap.xml',
            'urls' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function dueForWarming(): static
    {
        return $this->state(fn () => [
            'is_active' => true,
            'last_warmed_at' => now()->subHours(3),
            'frequency_minutes' => 60,
        ]);
    }
}
