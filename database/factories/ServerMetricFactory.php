<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerMetricFactory extends Factory
{
    protected $model = ServerMetric::class;

    public function definition(): array
    {
        $ramTotalMb = 8192;
        $ramPercent = fake()->randomFloat(2, 20, 70);
        $diskTotalGb = 100;
        $diskPercent = fake()->randomFloat(2, 30, 70);

        return [
            'server_id' => Server::factory(),
            'cpu_percent' => fake()->randomFloat(2, 5, 60),
            'ram_percent' => $ramPercent,
            'ram_used_mb' => (int) round($ramTotalMb * $ramPercent / 100),
            'ram_total_mb' => $ramTotalMb,
            'disk_percent' => $diskPercent,
            'disk_used_gb' => (int) round($diskTotalGb * $diskPercent / 100),
            'disk_total_gb' => $diskTotalGb,
            'captured_at' => now(),
            'created_at' => now(),
        ];
    }

    /**
     * Simulate a disk nearly full (95% used).
     */
    public function highDisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'disk_percent' => 95.00,
            'disk_used_gb' => (int) round(($attributes['disk_total_gb'] ?? 100) * 0.95),
        ]);
    }

    /**
     * Simulate RAM pressure (96% used).
     */
    public function highRam(): static
    {
        return $this->state(fn (array $attributes) => [
            'ram_percent' => 96.00,
            'ram_used_mb' => (int) round(($attributes['ram_total_mb'] ?? 8192) * 0.96),
        ]);
    }

    /**
     * Simulate CPU saturation (95%).
     */
    public function highCpu(): static
    {
        return $this->state(fn (array $attributes) => [
            'cpu_percent' => 95.00,
        ]);
    }
}
