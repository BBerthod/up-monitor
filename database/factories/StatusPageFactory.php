<?php

namespace Database\Factories;

use App\Models\StatusPage;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StatusPageFactory extends Factory
{
    protected $model = StatusPage::class;

    public function definition(): array
    {
        $name = $this->faker->company().' Status';

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'theme' => 'dark',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function light(): static
    {
        return $this->state(fn () => ['theme' => 'light']);
    }
}
