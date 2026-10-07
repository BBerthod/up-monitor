<?php

namespace Database\Factories;

use App\Enums\IncidentCause;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonitorIncidentFactory extends Factory
{
    protected $model = MonitorIncident::class;

    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'started_at' => now()->subMinutes(30),
            'cause' => IncidentCause::STATUS_CODE,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolved_at' => now(),
        ]);
    }
}
