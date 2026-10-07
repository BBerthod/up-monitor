<?php

namespace Database\Factories;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class InsightFactory extends Factory
{
    protected $model = Insight::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'site' => fake()->domainName(),
            'monitor_id' => null,
            'type' => fake()->randomElement(InsightType::cases())->value,
            'severity' => fake()->randomElement(InsightSeverity::cases())->value,
            'title' => fake()->sentence(6),
            'payload' => [],
            'impact_score' => fake()->randomFloat(2, 0, 100),
            'detected_at' => now(),
        ];
    }

    public function withMonitor(): static
    {
        return $this->state(fn (array $attributes) => [
            'monitor_id' => Monitor::factory()->state(['team_id' => $attributes['team_id']]),
        ]);
    }

    public function unacknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'acknowledged_at' => null,
        ]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'acknowledged_at' => now(),
        ]);
    }

    public function snoozed(?\DateTimeInterface $until = null): static
    {
        return $this->state(fn (array $attributes) => [
            'snoozed_until' => $until ?? now()->addHour(),
        ]);
    }

    public function ofType(InsightType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type->value,
        ]);
    }

    public function withSeverity(InsightSeverity $severity): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => $severity->value,
        ]);
    }
}
