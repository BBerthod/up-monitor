<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'dokploy_server_id' => fake()->uuid(),
            // unique(): the servers table has a (team_id, name) unique constraint,
            // and domainWord() draws from a small vocabulary — without unique() a
            // count(3) batch on the same team can collide and fail intermittently.
            'name' => fake()->unique()->domainWord().'-server',
            'metrics_url' => 'https://example.com/metrics',
            'metrics_token' => fake()->sha256(),
            'is_active' => true,
        ];
    }

    /**
     * Server without monitoring credentials configured.
     * The collector will skip it gracefully via hasMonitoringConfigured().
     */
    public function withoutMonitoring(): static
    {
        return $this->state(fn (array $attributes) => [
            'metrics_url' => null,
            'metrics_token' => null,
        ]);
    }
}
