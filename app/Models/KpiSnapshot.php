<?php

namespace App\Models;

use App\Enums\KpiSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'site',
        'source',
        'metric',
        'value',
        'period_days',
        'captured_at',
        'meta',
    ];

    protected $casts = [
        'source' => KpiSource::class,
        'value' => 'decimal:4',
        'period_days' => 'integer',
        'captured_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * Latest snapshot for a given (site, source, metric) tuple.
     */
    public static function latestFor(string $site, KpiSource $source, string $metric): ?self
    {
        return static::where('site', $site)
            ->where('source', $source->value)
            ->where('metric', $metric)
            ->latest('captured_at')
            ->first();
    }

    /**
     * Latest value for every (site, source, metric) combination requested, in
     * a single query.
     *
     * latestFor() costs one query per tuple, which a portfolio page multiplies
     * by sites × metrics: the KPI & Trends page issued 28 of them for four
     * sites and would issue ninety for a portfolio of fifteen. DISTINCT ON
     * walks the (site, source, metric, captured_at) index once instead.
     *
     * The result is keyed by site, then by "{source}|{metric}". A combination
     * that was never captured is absent rather than null.
     *
     * @param  list<string>  $sites
     * @param  list<array{KpiSource, string}>  $metrics  [source, metric] pairs
     * @return array<string, array<string, float|null>>
     */
    public static function latestValuesFor(array $sites, array $metrics): array
    {
        if ($sites === [] || $metrics === []) {
            return [];
        }

        $rows = static::query()
            ->selectRaw('DISTINCT ON (site, source, metric) site, source, metric, value')
            ->whereIn('site', $sites)
            ->where(function ($query) use ($metrics) {
                foreach ($metrics as [$source, $metric]) {
                    $query->orWhere(fn ($clause) => $clause
                        ->where('source', $source->value)
                        ->where('metric', $metric));
                }
            })
            // Must lead with the DISTINCT ON columns; captured_at DESC then
            // picks the most recent row within each group.
            ->orderByRaw('site, source, metric, captured_at DESC')
            ->get();

        $values = [];

        foreach ($rows as $row) {
            $source = $row->source instanceof KpiSource ? $row->source->value : (string) $row->source;
            $values[$row->site][$source.'|'.$row->metric] = $row->value !== null
                ? (float) $row->value
                : null;
        }

        return $values;
    }

    /**
     * Average value for a site/source/metric over the last N days.
     * Returns null when there are no snapshots in the window.
     */
    public static function averageOver(string $site, KpiSource $source, string $metric, int $days): ?float
    {
        $result = static::where('site', $site)
            ->where('source', $source->value)
            ->where('metric', $metric)
            ->where('captured_at', '>=', now()->subDays($days))
            ->avg('value');

        return $result !== null ? (float) $result : null;
    }

    /**
     * Average value over a custom day window [now - $fromDaysAgo, now - $toDaysAgo].
     *
     * Use this to query a shifted window rather than "the last N days". Typical
     * use-case: compare this week (averageOver 7) against last week (averageBetween 14, 7).
     *
     * Returns null when there are no snapshots in the window.
     */
    public static function averageBetween(
        string $site,
        KpiSource $source,
        string $metric,
        int $fromDaysAgo,
        int $toDaysAgo,
    ): ?float {
        $result = static::where('site', $site)
            ->where('source', $source->value)
            ->where('metric', $metric)
            ->whereBetween('captured_at', [
                now()->subDays($fromDaysAgo),
                now()->subDays($toDaysAgo),
            ])
            ->avg('value');

        return $result !== null ? (float) $result : null;
    }
}
