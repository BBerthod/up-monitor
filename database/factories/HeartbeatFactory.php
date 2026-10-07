<?php

namespace Database\Factories;

use App\Models\Heartbeat;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Heartbeat>
 */
class HeartbeatFactory extends Factory
{
    protected $model = Heartbeat::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'site_id' => null,
            'name' => $this->faker->words(3, true),
            'token_hash' => Heartbeat::hashToken(Str::random(48)),
            // Hourly with 10 minutes of grace: a common enough shape to make
            // the arithmetic in tests obvious.
            'expected_period_minutes' => 60,
            'grace_minutes' => 10,
            'last_ping_at' => now(),
            'alerted_at' => null,
            'is_active' => true,
        ];
    }

    /**
     * A heartbeat that has never reported — unstarted rather than overdue.
     */
    public function neverPinged(): static
    {
        return $this->state(fn (): array => ['last_ping_at' => null]);
    }

    /**
     * A heartbeat whose deadline has passed.
     */
    public function overdue(int $minutesLate = 30): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_ping_at' => now()
                ->subMinutes($attributes['expected_period_minutes'] + $attributes['grace_minutes'] + $minutesLate),
        ]);
    }
}
