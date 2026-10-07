<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Per-page audit bookkeeping, so capped audits rotate through a site's
 * catalogue instead of re-testing the same head pages on every run.
 *
 * WHY THIS EXISTS
 * ───────────────
 * BrokenPageService and AffiliateAuditService both cap work at 30 pages per
 * run and both select those pages by traffic, descending. On a site with
 * hundreds of earning pages that means the same 30 are probed forever and the
 * rest are never looked at — silently, since the cap is not reported anywhere.
 * One site in this fleet had ~40% of its product pages rendering empty
 * placeholders; the audit could not have found it, because those pages were
 * never in the top 30.
 *
 * HOW ROTATION WORKS
 * ──────────────────
 * Candidates are ordered by "least recently audited first" (never-audited
 * pages lead), then truncated to the cap. Every run therefore advances through
 * the catalogue and full coverage is reached in ceil(pages / cap) runs, which
 * a random shuffle cannot guarantee.
 *
 * Traffic still matters, but as a filter rather than an ordering: the caller
 * decides which pages qualify (minimum clicks, minimum impressions), and
 * rotation then decides which of the qualifying pages are due.
 */
class PageAuditState extends Model
{
    /** Audit type discriminators. */
    public const TYPE_BROKEN_PAGE = 'broken_page';

    public const TYPE_AFFILIATE = 'affiliate';

    protected $fillable = [
        'site',
        'page',
        'audit_type',
        'last_audited_at',
    ];

    protected $casts = [
        'last_audited_at' => 'datetime',
    ];

    /**
     * Order a set of candidate pages so the least recently audited come first,
     * and return at most $limit of them.
     *
     * Pages with no state row yet are treated as never audited and sort ahead
     * of everything else, so a newly discovered page is covered on the next run
     * rather than waiting for the rotation to come round.
     *
     * @param  Collection<int, string>|array<int, string>  $pages  Candidate page URLs.
     * @return array<int, string> The subset due for auditing, in rotation order.
     */
    public static function selectDue(
        string $site,
        string $auditType,
        Collection|array $pages,
        int $limit,
    ): array {
        $pages = $pages instanceof Collection ? $pages->all() : $pages;
        $pages = array_values(array_unique($pages));

        if ($pages === [] || $limit <= 0) {
            return [];
        }

        // Fetch existing state for the candidates only — the catalogue may be
        // far larger than the set we are about to consider.
        $states = static::query()
            ->where('site', $site)
            ->where('audit_type', $auditType)
            ->whereIn('page', $pages)
            ->pluck('last_audited_at', 'page');

        usort($pages, function (string $a, string $b) use ($states): int {
            $aAt = $states[$a] ?? null;
            $bAt = $states[$b] ?? null;

            // Never audited sorts first; ties keep a stable, deterministic order
            // so two runs over identical data pick the same pages.
            if ($aAt === null && $bAt === null) {
                return strcmp($a, $b);
            }

            if ($aAt === null) {
                return -1;
            }

            if ($bAt === null) {
                return 1;
            }

            return $aAt <=> $bAt ?: strcmp($a, $b);
        });

        return array_slice($pages, 0, $limit);
    }

    /**
     * Stamp the given pages as audited now.
     *
     * Called after the audit completes, whether or not it found anything: the
     * point is that the page has been looked at. Uses upsert so a page's first
     * audit creates its state row.
     *
     * @param  array<int, string>  $pages
     */
    public static function markAudited(string $site, string $auditType, array $pages): void
    {
        if ($pages === []) {
            return;
        }

        $now = now();

        $rows = array_map(fn (string $page): array => [
            'site' => $site,
            'page' => $page,
            'audit_type' => $auditType,
            'last_audited_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_unique($pages)));

        static::query()->upsert(
            $rows,
            ['site', 'page', 'audit_type'],
            ['last_audited_at', 'updated_at'],
        );
    }
}
