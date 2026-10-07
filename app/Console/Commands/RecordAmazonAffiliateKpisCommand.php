<?php

namespace App\Console\Commands;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecordAmazonAffiliateKpisCommand extends Command
{
    protected $signature = 'affiliate:kpi-record
                            {site : Site alias or primary domain}
                            {--tracking-id= : Exact Amazon Associates tracking ID used to filter the report}
                            {--clicks= : Clicks for the selected tracking ID and period}
                            {--orders=unknown : Ordered items, or unknown when Amazon masks low-volume data}
                            {--earnings= : Affiliate earnings for the selected tracking ID and period}
                            {--ordered-revenue=unknown : Ordered product revenue, or unknown when Amazon masks it}
                            {--currency=USD : ISO currency of the monetary values}
                            {--period-days=30 : Report window length}
                            {--captured-at= : Observation timestamp; defaults to now}';

    protected $description = 'Record a tracking-ID-filtered Amazon Associates report as auditable KPI snapshots.';

    public function handle(): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));

        if ($site === null) {
            $this->error('Unknown site. Use its exact alias or primary domain.');

            return self::FAILURE;
        }

        $trackingId = trim((string) $this->option('tracking-id'));

        if ($trackingId === '') {
            $this->error('--tracking-id is required. Global/unfiltered Amazon reports are rejected.');

            return self::FAILURE;
        }

        if (empty($site->amazon_tag)) {
            $this->error("Site {$site->alias} has no configured amazon_tag; configure it before importing revenue.");

            return self::FAILURE;
        }

        if (! hash_equals(trim((string) $site->amazon_tag), $trackingId)) {
            $this->error("Tracking ID {$trackingId} does not match the configured tag for {$site->alias}.");

            return self::FAILURE;
        }

        $clicks = $this->nonNegativeNumber('clicks');
        [$ordersValid, $orders] = $this->optionalNonNegativeNumber('orders');
        $earnings = $this->nonNegativeNumber('earnings');
        [$orderedRevenueValid, $orderedRevenue] = $this->optionalNonNegativeNumber('ordered-revenue');
        $periodDays = filter_var($this->option('period-days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 366],
        ]);

        if ($clicks === null || ! $ordersValid || $earnings === null || ! $orderedRevenueValid || $periodDays === false) {
            $this->error('Clicks and earnings must be non-negative numbers. Orders and ordered revenue may also be "unknown" when Amazon masks them; period-days must be 1-366.');

            return self::FAILURE;
        }

        try {
            $capturedAt = $this->option('captured-at')
                ? CarbonImmutable::parse((string) $this->option('captured-at'))
                : CarbonImmutable::now();
        } catch (Throwable) {
            $this->error('--captured-at is not a valid date/time.');

            return self::FAILURE;
        }

        $currency = strtoupper(trim((string) $this->option('currency')));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->error('--currency must be a three-letter ISO code such as EUR or USD.');

            return self::FAILURE;
        }

        $suffix = $periodDays.'d';
        $meta = [
            'tracking_id' => $trackingId,
            'currency' => $currency,
            'report_scope' => 'tracking_id',
            'imported_by' => self::class,
        ];
        $conversionRate = $orders !== null ? ($clicks > 0 ? ($orders / $clicks) * 100 : 0.0) : null;

        DB::transaction(function () use ($site, $periodDays, $capturedAt, $suffix, $meta, $clicks, $orders, $earnings, $orderedRevenue, $conversionRate): void {
            $metrics = [
                "clicks_{$suffix}" => $clicks,
                "earnings_{$suffix}" => $earnings,
            ];
            if ($orders !== null) {
                $metrics["ordered_items_{$suffix}"] = $orders;
                $metrics["conversion_rate_{$suffix}"] = round((float) $conversionRate, 4);
            }
            if ($orderedRevenue !== null) {
                $metrics["ordered_revenue_{$suffix}"] = $orderedRevenue;
            }
            foreach ($metrics as $metric => $value) {
                KpiSnapshot::create([
                    'site' => $site->primary_domain,
                    'source' => KpiSource::AMAZON->value,
                    'metric' => $metric,
                    'value' => $value,
                    'period_days' => $periodDays,
                    'captured_at' => $capturedAt,
                    'meta' => $meta,
                ]);
            }
        });

        $this->info(sprintf(
            'Recorded %s: %.0f clicks, %s ordered items, %.2f %s earnings, %s conversion (%d days, tag %s).',
            $site->primary_domain,
            $clicks,
            $orders === null ? 'unknown' : sprintf('%.0f', $orders),
            $earnings,
            $currency,
            $conversionRate === null ? 'unknown' : sprintf('%.2f%%', $conversionRate),
            $periodDays,
            $trackingId,
        ));

        return self::SUCCESS;
    }

    private function resolveSite(string $value): ?Site
    {
        return Site::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('alias', $value)->orWhere('primary_domain', $value))
            ->first();
    }

    private function nonNegativeNumber(string $option): ?float
    {
        $raw = $this->option($option);

        if ($raw === null || $raw === '' || ! is_numeric($raw) || (float) $raw < 0) {
            return null;
        }

        return (float) $raw;
    }

    /** @return array{bool, float|null} */
    private function optionalNonNegativeNumber(string $option): array
    {
        $raw = strtolower(trim((string) $this->option($option)));

        if ($raw === 'unknown' || $raw === '-') {
            return [true, null];
        }

        if ($raw === '' || ! is_numeric($raw) || (float) $raw < 0) {
            return [false, null];
        }

        return [true, (float) $raw];
    }
}
