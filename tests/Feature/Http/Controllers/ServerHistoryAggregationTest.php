<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The server detail history is resolved server-side per period: raw 5-minute
 * points for 24h, hourly / 6-hourly SQL averages for 7d/30d. It used to ship
 * 30 raw days (~8 640 rows) on every payload and filter in the browser.
 */
class ServerHistoryAggregationTest extends TestCase
{
    use RefreshDatabase;

    private function makeServerWithMetrics(User $user, int $days, int $stepMinutes = 60): Server
    {
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        $rows = [];
        $cursor = now()->subDays($days);

        while ($cursor->lessThan(now())) {
            $rows[] = [
                'server_id' => $server->id,
                'cpu_percent' => 50,
                'ram_percent' => 50,
                'disk_percent' => 50,
                'captured_at' => $cursor->copy(),
                'created_at' => $cursor->copy(),
            ];
            $cursor->addMinutes($stepMinutes);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ServerMetric::insert($chunk);
        }

        return $server;
    }

    private function user(): User
    {
        return User::factory()->create(['team_id' => Team::factory()->create()->id]);
    }

    public function test_default_period_is_24h_and_only_ships_that_window(): void
    {
        $user = $this->user();
        $server = $this->makeServerWithMetrics($user, days: 10);

        $this->actingAs($user)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertInertia(function ($p) {
                $p->where('period', '24h');
                $history = $p->toArray()['props']['history'];

                // 10 days exist at hourly cadence (240 rows); only the last
                // ~24 hours may ship (±1 for the window boundary).
                $this->assertLessThanOrEqual(25, count($history));
                $this->assertGreaterThanOrEqual(23, count($history));
            });
    }

    public function test_30d_period_is_aggregated_not_raw(): void
    {
        $user = $this->user();
        $server = $this->makeServerWithMetrics($user, days: 30);

        $response = $this->actingAs($user)->get(route('servers.show', ['server' => $server, 'period' => '30d']));

        $response->assertOk()->assertInertia(function ($p) {
            $p->where('period', '30d');
            $history = $p->toArray()['props']['history'];

            // 30 days at hourly cadence = 720 raw rows; 6-hour buckets = ~120.
            $this->assertLessThanOrEqual(125, count($history));
            $this->assertGreaterThan(100, count($history));

            // Averages survive: every seeded value is 50.
            $this->assertEqualsWithDelta(50.0, $history[0]['cpu'], 0.01);
        });
    }

    public function test_invalid_period_falls_back_to_24h(): void
    {
        $user = $this->user();
        $server = $this->makeServerWithMetrics($user, days: 2);

        $this->actingAs($user)
            ->get(route('servers.show', ['server' => $server, 'period' => 'evil']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('period', '24h'));
    }
}
