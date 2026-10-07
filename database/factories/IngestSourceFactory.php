<?php

namespace Database\Factories;

use App\Models\IngestSource;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class IngestSourceFactory extends Factory
{
    protected $model = IngestSource::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);
        $token = IngestSource::generateToken();

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'token' => $token,
            'token_hash' => IngestSource::hashToken($token),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
