<?php

namespace Tests\Unit\Models;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * latestValuesFor() replaces a per-tuple latestFor() loop on the portfolio
 * pages, so it has to return exactly what that loop returned.
 */
class KpiSnapshotLatestValuesTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(string $site, KpiSource $source, string $metric, float $value, string $capturedAt): void
    {
        KpiSnapshot::create([
            'site' => $site,
            'source' => $source->value,
            'metric' => $metric,
            'value' => $value,
            'period_days' => 28,
            'captured_at' => $capturedAt,
        ]);
    }

    public function test_it_returns_the_most_recent_value_per_site_source_metric(): void
    {
        $this->snapshot('a.example.com', KpiSource::GSC, 'clicks_28d', 10.0, '2026-01-01 00:00:00');
        $this->snapshot('a.example.com', KpiSource::GSC, 'clicks_28d', 42.0, '2026-01-05 00:00:00');
        $this->snapshot('a.example.com', KpiSource::GSC, 'position_28d', 7.5, '2026-01-05 00:00:00');
        $this->snapshot('b.example.com', KpiSource::GSC, 'clicks_28d', 3.0, '2026-01-05 00:00:00');

        $values = KpiSnapshot::latestValuesFor(
            ['a.example.com', 'b.example.com'],
            [[KpiSource::GSC, 'clicks_28d'], [KpiSource::GSC, 'position_28d']],
        );

        $this->assertSame(42.0, $values['a.example.com']['gsc|clicks_28d']);
        $this->assertSame(7.5, $values['a.example.com']['gsc|position_28d']);
        $this->assertSame(3.0, $values['b.example.com']['gsc|clicks_28d']);
    }

    public function test_it_agrees_with_latest_for_on_every_requested_tuple(): void
    {
        $metrics = [
            [KpiSource::GSC, 'clicks_28d'],
            [KpiSource::GSC, 'position_28d'],
            [KpiSource::BING, 'bing_clicks_28d'],
            [KpiSource::TTFB, 'ttfb_p95_ms'],
        ];

        $sites = ['a.example.com', 'b.example.com', 'c.example.com'];

        foreach ($sites as $siteIndex => $site) {
            foreach ($metrics as $metricIndex => [$source, $metric]) {
                // Two snapshots per tuple so "latest" is a real choice, and a
                // gap on one site so absent tuples are covered too.
                if ($siteIndex === 2 && $metricIndex === 0) {
                    continue;
                }

                $this->snapshot($site, $source, $metric, 1.0 + $metricIndex, '2026-01-01 00:00:00');
                $this->snapshot($site, $source, $metric, 90.0 + $metricIndex, '2026-02-01 00:00:00');
            }
        }

        $batched = KpiSnapshot::latestValuesFor($sites, $metrics);

        foreach ($sites as $site) {
            foreach ($metrics as [$source, $metric]) {
                $expected = KpiSnapshot::latestFor($site, $source, $metric)?->value;
                $expected = $expected !== null ? (float) $expected : null;

                $this->assertSame(
                    $expected,
                    $batched[$site][$source->value.'|'.$metric] ?? null,
                    "Mismatch for {$site} {$source->value} {$metric}",
                );
            }
        }
    }

    public function test_it_costs_a_single_query_regardless_of_site_count(): void
    {
        foreach (['a.example.com', 'b.example.com', 'c.example.com'] as $site) {
            $this->snapshot($site, KpiSource::GSC, 'clicks_28d', 1.0, '2026-01-01 00:00:00');
            $this->snapshot($site, KpiSource::GA4, 'users_28d', 2.0, '2026-01-01 00:00:00');
        }

        DB::enableQueryLog();

        KpiSnapshot::latestValuesFor(
            ['a.example.com', 'b.example.com', 'c.example.com'],
            [[KpiSource::GSC, 'clicks_28d'], [KpiSource::GA4, 'users_28d']],
        );

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_it_short_circuits_on_empty_input(): void
    {
        $this->assertSame([], KpiSnapshot::latestValuesFor([], [[KpiSource::GSC, 'clicks_28d']]));
        $this->assertSame([], KpiSnapshot::latestValuesFor(['a.example.com'], []));
    }
}
