<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists per-page GSC metrics at each collection run.
 *
 * Each row represents ONE page on ONE site at ONE point in time.
 * Accumulating these snapshots over weeks enables ContentDecayService to
 * compare the current state against an earlier baseline and spot sustainable
 * traffic loss — as opposed to normal week-to-week noise.
 *
 * Design note: no team_id column — pages are tied to a site hostname, exactly
 * like kpi_snapshots.  The relationship to a team is resolved through the
 * Monitor that owns the site.
 */
class PageMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'site',
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
     * Return the most recently captured snapshot for a given site+page pair.
     * Returns null when no data has been collected yet.
     */
    public static function latestFor(string $site, string $page): ?self
    {
        return static::where('site', $site)
            ->where('page', $page)
            ->latest('captured_at')
            ->first();
    }

    /**
     * Return the OLDEST snapshot for a site+page within the given look-back window.
     *
     * "Oldest in the window" is the baseline we compare the current snapshot against.
     * Using the oldest rather than the newest baseline maximises the span of
     * comparison, which makes the decay signal more reliable (a 30 % drop over
     * 10 weeks is more meaningful than the same drop over 2 weeks).
     */
    public static function oldestSince(string $site, string $page, int $days): ?self
    {
        return static::where('site', $site)
            ->where('page', $page)
            ->where('captured_at', '>=', now()->subDays($days))
            ->oldest('captured_at')
            ->first();
    }
}
