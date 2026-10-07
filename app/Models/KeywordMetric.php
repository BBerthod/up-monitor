<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Per-keyword GSC metrics captured at each collection run.
 *
 * One row = one (query, page) pair on one site at one point in time. Keeping
 * the page alongside the query is what makes cannibalisation visible: two pages
 * ranking for the same query on the same day are two rows to compare.
 *
 * Append-only, like PageMetric: rows are never updated, and a pruning job trims
 * the tail. No team_id — keyed by site hostname, with the team resolved through
 * the Monitor that owns the site, matching page_metrics and kpi_snapshots.
 */
class KeywordMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'site',
        'query',
        'page',
        'clicks',
        'impressions',
        'ctr',
        'position',
        'captured_at',
    ];

    protected $casts = [
        'clicks' => 'decimal:2',
        'impressions' => 'decimal:2',
        'ctr' => 'decimal:4',
        'position' => 'decimal:2',
        'captured_at' => 'datetime',
    ];

    /**
     * Distinct capture timestamps for a site, most recent first.
     *
     * @return Collection<int, string>
     */
    public static function captureTimestamps(string $site, int $limit = 30): Collection
    {
        return static::query()
            ->toBase()
            ->select('captured_at')
            ->where('site', $site)
            ->distinct()
            ->orderByDesc('captured_at')
            ->limit($limit)
            ->pluck('captured_at');
    }
}
