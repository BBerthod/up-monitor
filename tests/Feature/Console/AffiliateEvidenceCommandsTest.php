<?php

namespace Tests\Feature\Console;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateEvidenceCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_amazon_report_is_recorded_only_for_the_configured_tracking_id(): void
    {
        $site = Site::factory()->create([
            'alias' => 'reviewsite',
            'primary_domain' => 'reviewsite.com',
            'amazon_tag' => 'reviewsite-20',
        ]);

        $this->artisan('affiliate:kpi-record', [
            'site' => $site->alias,
            '--tracking-id' => 'reviewsite-20',
            '--clicks' => '2000',
            '--orders' => '110',
            '--earnings' => '75.83',
            '--ordered-revenue' => '2351',
            '--currency' => 'USD',
            '--period-days' => '30',
        ])->assertSuccessful();

        $this->assertDatabaseCount('kpi_snapshots', 5);
        $this->assertDatabaseHas('kpi_snapshots', [
            'site' => 'reviewsite.com',
            'source' => KpiSource::AMAZON->value,
            'metric' => 'conversion_rate_30d',
            'value' => '5.5000',
        ]);

        $snapshot = KpiSnapshot::where('metric', 'earnings_30d')->firstOrFail();
        $this->assertSame('reviewsite-20', $snapshot->meta['tracking_id']);
        $this->assertSame('tracking_id', $snapshot->meta['report_scope']);
    }

    public function test_global_or_wrong_amazon_tracking_id_is_rejected(): void
    {
        Site::factory()->create([
            'alias' => 'reviewsite',
            'primary_domain' => 'reviewsite.com',
            'amazon_tag' => 'reviewsite-20',
        ]);

        $this->artisan('affiliate:kpi-record', [
            'site' => 'reviewsite',
            '--tracking-id' => 'global-20',
            '--clicks' => '10',
            '--orders' => '1',
            '--earnings' => '2',
        ])->assertFailed();

        $this->assertDatabaseCount('kpi_snapshots', 0);
    }

    public function test_masked_amazon_values_are_not_invented_as_zeroes(): void
    {
        Site::factory()->create([
            'alias' => 'examplestore',
            'primary_domain' => 'fr.examplestore.com',
            'amazon_tag' => 'examplestore-21',
        ]);

        $this->artisan('affiliate:kpi-record', [
            'site' => 'examplestore',
            '--tracking-id' => 'examplestore-21',
            '--clicks' => '15917',
            '--orders' => 'unknown',
            '--earnings' => '0',
            '--ordered-revenue' => 'unknown',
            '--currency' => 'EUR',
        ])->assertSuccessful();

        $this->assertDatabaseCount('kpi_snapshots', 2);
        $this->assertDatabaseMissing('kpi_snapshots', ['metric' => 'ordered_items_30d']);
        $this->assertDatabaseMissing('kpi_snapshots', ['metric' => 'conversion_rate_30d']);
    }

    public function test_decision_gate_passes_only_with_fresh_cross_source_evidence(): void
    {
        Site::factory()->create([
            'alias' => 'reviewsite',
            'primary_domain' => 'reviewsite.com',
            'amazon_tag' => 'reviewsite-20',
        ]);

        foreach ([
            [KpiSource::GSC, 'impressions_28d', 4000, null],
            [KpiSource::GSC, 'clicks_28d', 20, null],
            [KpiSource::GA4, 'users_28d', 100, null],
            [KpiSource::AMAZON, 'clicks_30d', 2000, ['tracking_id' => 'reviewsite-20']],
            [KpiSource::AMAZON, 'earnings_30d', 75.83, ['tracking_id' => 'reviewsite-20']],
        ] as [$source, $metric, $value, $meta]) {
            KpiSnapshot::create([
                'site' => 'reviewsite.com',
                'source' => $source->value,
                'metric' => $metric,
                'value' => $value,
                'period_days' => $source === KpiSource::AMAZON ? 30 : 28,
                'captured_at' => now()->subHour(),
                'meta' => $meta,
            ]);
        }

        $this->artisan('affiliate:decision-check', ['site' => 'reviewsite'])
            ->expectsOutputToContain('READY:')
            ->assertSuccessful();
    }

    public function test_decision_gate_fails_when_a_source_is_missing_or_amazon_is_unfiltered(): void
    {
        Site::factory()->create([
            'alias' => 'reviewsite',
            'primary_domain' => 'reviewsite.com',
            'amazon_tag' => 'reviewsite-20',
        ]);

        KpiSnapshot::create([
            'site' => 'reviewsite.com',
            'source' => KpiSource::AMAZON->value,
            'metric' => 'clicks_30d',
            'value' => 2000,
            'period_days' => 30,
            'captured_at' => now(),
            'meta' => ['tracking_id' => 'global-20'],
        ]);

        $this->artisan('affiliate:decision-check', ['site' => 'reviewsite'])
            ->expectsOutputToContain('NOT READY:')
            ->assertFailed();
    }
}
