<?php

namespace Database\Seeders;

use App\Enums\CheckStatus;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\MonitorType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fills a local environment with a realistic portfolio so the UI can be
 * developed and reviewed against non-empty screens.
 *
 * Local only — refuses to run outside the local environment.
 */
class DemoSeeder extends Seeder
{
    /**
     * Sites mirroring a small multi-site portfolio.
     *
     * `probe` is the URL live checks hit. The scheduler runs against these for
     * real, so they point at reachable hosts — otherwise every demo monitor
     * flips DOWN within a minute of seeding. `charlie` is deliberately
     * unreachable so DOWN states and incidents stay reviewable.
     */
    private const SITES = [
        ['alias' => 'alpha', 'domain' => 'alpha.example.com', 'locale' => 'fr', 'probe' => 'https://example.com'],
        ['alias' => 'bravo', 'domain' => 'bravo.example.com', 'locale' => 'fr', 'probe' => 'https://example.org'],
        ['alias' => 'charlie', 'domain' => 'charlie.example.org', 'locale' => 'en', 'probe' => 'https://offline.invalid'],
        ['alias' => 'delta', 'domain' => 'delta.example.net', 'locale' => 'de', 'probe' => 'https://example.net'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('DemoSeeder only runs in the local environment.');

            return;
        }

        $user = User::query()->orderBy('id')->first();

        if (! $user) {
            $this->call(AdminSeeder::class);
            $user = User::query()->orderBy('id')->firstOrFail();
        }

        $team = Team::findOrFail($user->team_id);

        $server = Server::query()->firstWhere(['team_id' => $team->id, 'name' => 'hetzner-demo'])
            ?? Server::factory()->for($team)->create(['name' => 'hetzner-demo']);

        $this->seedServerMetrics($server);

        foreach (self::SITES as $index => $definition) {
            $site = Site::query()->firstWhere(['team_id' => $team->id, 'alias' => $definition['alias']])
                ?? Site::factory()->for($team)->create([
                    'alias' => $definition['alias'],
                    'primary_domain' => $definition['domain'],
                    'domains' => [$definition['domain']],
                    'primary_locale' => $definition['locale'],
                    'server_id' => $server->id,
                ]);

            $this->seedMonitors($team, $site, $index, $definition['probe']);
            $this->seedInsights($team, $site, $index);
        }

        $this->command?->info('Demo portfolio seeded: '.count(self::SITES).' sites.');
    }

    /** One HTTPS monitor per site plus a ping monitor, with 7 days of checks. */
    private function seedMonitors(Team $team, Site $site, int $index, string $probe): void
    {
        if (Monitor::query()->where('site_id', $site->id)->exists()) {
            return;
        }

        $monitor = Monitor::factory()->for($team)->create([
            'site_id' => $site->id,
            'name' => $site->alias.' — homepage',
            'type' => MonitorType::HTTP,
            'url' => $probe,
        ]);

        // The third site is deliberately degraded so DOWN states are reviewable.
        $this->seedChecks($monitor, degraded: $index === 2);
    }

    /** 7 days of checks at 30-minute resolution. */
    private function seedChecks(Monitor $monitor, bool $degraded): void
    {
        $rows = [];
        $cursor = now()->subDays(7);
        $baseline = fake()->numberBetween(120, 400);

        while ($cursor->lessThan(now())) {
            $isDown = $degraded && $cursor->greaterThan(now()->subHours(6)) && $cursor->lessThan(now()->subHours(4));

            $rows[] = [
                'monitor_id' => $monitor->id,
                'status' => ($isDown ? CheckStatus::DOWN : CheckStatus::UP)->value,
                'response_time_ms' => $isDown ? 0 : $baseline + fake()->numberBetween(-60, 240),
                'status_code' => $isDown ? 503 : 200,
                'error_message' => $isDown ? 'Connection timed out' : null,
                'checked_at' => $cursor->copy(),
            ];

            $cursor->addMinutes(30);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MonitorCheck::insert($chunk);
        }
    }

    /** A spread of insight types and severities so every filter has results. */
    private function seedInsights(Team $team, Site $site, int $index): void
    {
        if (Insight::query()->where('site', $site->primary_domain)->exists()) {
            return;
        }

        $severities = [InsightSeverity::CRITICAL, InsightSeverity::WARNING, InsightSeverity::INFO];
        $types = [InsightType::cases()[$index % count(InsightType::cases())], InsightType::cases()[($index + 1) % count(InsightType::cases())]];

        foreach ($types as $offset => $type) {
            Insight::factory()->for($team)->create([
                'site' => $site->primary_domain,
                'type' => $type->value,
                'severity' => $severities[($index + $offset) % count($severities)]->value,
                'title' => ucfirst($type->value).' detected on '.$site->alias,
                'impact_score' => fake()->randomFloat(2, 20, 95),
                'detected_at' => now()->subHours(fake()->numberBetween(1, 72)),
                'acknowledged_at' => null,
            ]);
        }
    }

    /** 24 hours of server metrics at 5-minute resolution. */
    private function seedServerMetrics(Server $server): void
    {
        if (ServerMetric::query()->where('server_id', $server->id)->exists()) {
            return;
        }

        $rows = [];
        $cursor = now()->subDay();

        while ($cursor->lessThan(now())) {
            $rows[] = [
                'server_id' => $server->id,
                'cpu_percent' => fake()->randomFloat(2, 5, 65),
                'ram_percent' => fake()->randomFloat(2, 35, 80),
                'disk_percent' => fake()->randomFloat(2, 40, 62),
                'load_avg_1' => fake()->randomFloat(2, 0.2, 4),
                'load_avg_5' => fake()->randomFloat(2, 0.2, 3.5),
                'load_avg_15' => fake()->randomFloat(2, 0.2, 3),
                'ram_used_mb' => 24_000,
                'ram_total_mb' => 64_000,
                'disk_used_gb' => 210,
                'disk_total_gb' => 400,
                'captured_at' => $cursor->copy(),
                'created_at' => $cursor->copy(),
            ];

            $cursor->addMinutes(5);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ServerMetric::insert($chunk);
        }
    }
}
