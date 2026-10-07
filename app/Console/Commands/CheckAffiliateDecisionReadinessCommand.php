<?php

namespace App\Console\Commands;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Models\Site;
use Illuminate\Console\Command;

class CheckAffiliateDecisionReadinessCommand extends Command
{
    protected $signature = 'affiliate:decision-check
                            {site : Site alias or primary domain}
                            {--max-age-hours=48 : Maximum acceptable age for every source}';

    protected $description = 'Refuse an affiliate diagnosis until fresh GSC, GA4 and tracking-ID-filtered Amazon evidence exists.';

    public function handle(): int
    {
        $site = Site::withoutGlobalScopes()
            ->where(fn ($query) => $query
                ->where('alias', (string) $this->argument('site'))
                ->orWhere('primary_domain', (string) $this->argument('site')))
            ->first();

        if ($site === null) {
            $this->error('Unknown site.');

            return self::FAILURE;
        }

        $maxAge = filter_var($this->option('max-age-hours'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 720],
        ]);

        if ($maxAge === false) {
            $this->error('--max-age-hours must be between 1 and 720.');

            return self::FAILURE;
        }

        $checks = [
            'GSC impressions' => $this->latestExact($site->primary_domain, KpiSource::GSC, 'impressions_28d'),
            'GSC clicks' => $this->latestExact($site->primary_domain, KpiSource::GSC, 'clicks_28d'),
            'GA4 users' => $this->latestExact($site->primary_domain, KpiSource::GA4, 'users_28d'),
            'Amazon clicks' => $this->latestPrefix($site->primary_domain, 'clicks_'),
            'Amazon earnings' => $this->latestPrefix($site->primary_domain, 'earnings_'),
        ];

        $ready = ! empty($site->amazon_tag);
        $rows = [];

        foreach ($checks as $label => $snapshot) {
            $reason = 'fresh';

            if ($snapshot === null) {
                $ready = false;
                $reason = 'missing';
            } elseif ($snapshot->captured_at->lt(now()->subHours($maxAge))) {
                $ready = false;
                $reason = 'stale';
            } elseif ($snapshot->source === KpiSource::AMAZON) {
                $trackingId = (string) data_get($snapshot->meta, 'tracking_id');

                if ($trackingId === '' || ! hash_equals(trim((string) $site->amazon_tag), $trackingId)) {
                    $ready = false;
                    $reason = 'wrong or unfiltered tracking ID';
                }
            }

            $rows[] = [
                $label,
                $snapshot?->value ?? '—',
                $snapshot?->captured_at?->toIso8601String() ?? '—',
                $reason,
            ];
        }

        $this->table(['Evidence', 'Value', 'Captured at', 'Status'], $rows);

        if (! $ready) {
            $this->error('NOT READY: do not conclude on SEO, traffic or conversion until every source is fresh and Amazon is filtered on the configured tracking ID.');

            return self::FAILURE;
        }

        $this->info('READY: GSC, GA4 and correctly scoped Amazon evidence are fresh enough for an affiliate diagnosis.');

        return self::SUCCESS;
    }

    private function latestExact(string $site, KpiSource $source, string $metric): ?KpiSnapshot
    {
        return KpiSnapshot::withoutGlobalScopes()
            ->where('site', $site)
            ->where('source', $source->value)
            ->where('metric', $metric)
            ->latest('captured_at')
            ->first();
    }

    private function latestPrefix(string $site, string $metricPrefix): ?KpiSnapshot
    {
        return KpiSnapshot::withoutGlobalScopes()
            ->where('site', $site)
            ->where('source', KpiSource::AMAZON->value)
            ->where('metric', 'like', $metricPrefix.'%')
            ->latest('captured_at')
            ->first();
    }
}
