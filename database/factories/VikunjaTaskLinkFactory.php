<?php

namespace Database\Factories;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Team;
use App\Models\VikunjaTaskLink;
use Illuminate\Database\Eloquent\Factories\Factory;

class VikunjaTaskLinkFactory extends Factory
{
    protected $model = VikunjaTaskLink::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'scope_key' => 'site:'.fake()->numberBetween(1, 999).'|content_decay',
            'insight_type' => InsightType::CONTENT_DECAY->value,
            'site_id' => null,
            'server_id' => null,
            'monitor_id' => null,
            'insight_id' => null,
            'title' => fake()->sentence(5),
            'severity' => InsightSeverity::CRITICAL->value,
            'vikunja_task_id' => null,
            'vikunja_project_id' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'promoted_at' => null,
            'closed_at' => null,
            'close_reason' => null,
        ];
    }

    /** A problem that already has a card on the board. */
    public function promoted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'vikunja_task_id' => fake()->numberBetween(100, 9999),
            'vikunja_project_id' => 13,
            'promoted_at' => now(),
        ]);
    }

    /** A problem observed long enough to be eligible for promotion. */
    public function persistent(int $hours = 24): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_seen_at' => now()->subHours($hours),
            'last_seen_at' => now(),
        ]);
    }
}
