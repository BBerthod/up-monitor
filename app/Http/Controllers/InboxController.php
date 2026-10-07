<?php

namespace App\Http\Controllers;

use App\Enums\InsightDomain;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Http\Presenters\InsightTriagePresenter;
use App\Models\Insight;
use App\Models\Monitor;
use App\Services\TriageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    /**
     * Valid severity values accepted by the filter.
     *
     * INFO and OPPORTUNITY are merged into a single 'info' bucket for the
     * filter parameter so the URL stays clean.  Both are mapped to the two
     * enum values on the query side.
     */
    private const SEVERITY_FILTER_MAP = [
        'critical' => [InsightSeverity::CRITICAL->value],
        'warning' => [InsightSeverity::WARNING->value],
        'info' => [InsightSeverity::INFO->value, InsightSeverity::OPPORTUNITY->value],
    ];

    /** Valid group values. */
    private const VALID_GROUPS = ['severity', 'domain', 'site'];

    public function index(Request $request, TriageService $triage): Response
    {
        $team = auth()->user()->team;

        if (! $team) {
            return Inertia::render('Inbox', [
                'items' => ['data' => [], 'links' => [], 'meta' => []],
                'counts' => ['total' => 0, 'critical' => 0, 'domains' => []],
                'filters' => [],
                'group' => 'severity',
                'groupCounts' => [],
                'severityCounts' => ['critical' => 0, 'warning' => 0, 'info' => 0],
                'suggestions' => null,
            ]);
        }

        // ── 1. Validate filters ────────────────────────────────────────────────

        $validated = $request->validate([
            'severity' => ['nullable', 'string', 'in:critical,warning,info'],
            'domain' => ['nullable', 'string', 'in:'.implode(',', array_column(InsightDomain::cases(), 'value'))],
            'site' => ['nullable', 'integer'],
            'group' => ['nullable', 'string', 'in:'.implode(',', self::VALID_GROUPS)],
        ]);

        $filterSeverity = $validated['severity'] ?? null;
        $filterDomain = $validated['domain'] ?? null;
        $filterSite = isset($validated['site']) ? (int) $validated['site'] : null;
        $group = $validated['group'] ?? 'severity';

        // ── 2. Base open query (unacknowledged, not snoozed) ──────────────────

        $baseQuery = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNull('acknowledged_at')
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));

        // ── 3. Apply composable filters ───────────────────────────────────────

        if ($filterSeverity !== null) {
            $severityValues = self::SEVERITY_FILTER_MAP[$filterSeverity];
            $baseQuery->whereIn('severity', $severityValues);
        }

        if ($filterDomain !== null) {
            $domain = InsightDomain::from($filterDomain);
            $types = InsightDomain::typesForDomain($domain);
            $baseQuery->whereIn('type', $types);
        }

        if ($filterSite !== null) {
            $baseQuery->where('site_id', $filterSite);
        }

        // ── 4. Paginated list ─────────────────────────────────────────────────

        $paginator = (clone $baseQuery)
            ->orderByDesc('impact_score')
            ->with(['linkedSite', 'server', 'monitor'])
            ->paginate(25)
            ->withQueryString();

        $paginator->through(fn (Insight $i) => InsightTriagePresenter::present($i));

        // ── 5. groupCounts + severityCounts — both over the FULL open set ───────
        //     severityCounts is always computed (pills need it regardless of group).
        //     When group=severity, reuse the same DB result for groupCounts.

        [$groupCounts, $severityCounts] = $this->buildGroupAndSeverityCounts($team->id, $group);

        // ── 6. Active filters array ───────────────────────────────────────────

        $activeFilters = array_filter([
            'severity' => $filterSeverity,
            'domain' => $filterDomain,
            'site' => $filterSite,
        ], fn ($v) => $v !== null);

        // ── 7. Suggestions (only when inbox is completely empty) ──────────────

        $suggestions = null;

        // Count ALL open insights for this team (no filters applied).
        $totalOpen = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNull('acknowledged_at')
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->count();

        if ($totalOpen === 0) {
            $suggestions = $this->buildSuggestions($team->id);
        }

        return Inertia::render('Inbox', [
            'items' => $paginator,
            'counts' => $triage->counts($team),
            'filters' => $activeFilters,
            'group' => $group,
            'groupCounts' => $groupCounts,
            'severityCounts' => $severityCounts,
            'suggestions' => $suggestions,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build groupCounts and severityCounts in the minimum number of queries.
     *
     * severityCounts is ALWAYS needed (filter pills show it regardless of group).
     * When group=severity, the severity GROUP BY query feeds both outputs so we
     * avoid a redundant query.  For other group values we run one query for
     * severityCounts and one for the chosen group.
     *
     * severityCounts shape: {critical: N, warning: N, info: N}
     *   • info merges InsightSeverity::INFO and InsightSeverity::OPPORTUNITY so
     *     the frontend never needs to know about the internal OPPORTUNITY value.
     *
     * groupCounts shape:
     *   group=severity → [{key, label, count}] for all InsightSeverity cases
     *   group=domain   → [{key, label, count}] for all InsightDomain cases
     *   group=site     → [{id, name, count}] sorted desc by count
     *
     * @return array{0: array, 1: array{critical: int, warning: int, info: int}}
     */
    private function buildGroupAndSeverityCounts(int $teamId, string $group): array
    {
        $openBase = Insight::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNull('acknowledged_at')
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));

        // ── Always run the severity GROUP BY (cheap; feeds severityCounts) ─────
        $sevRows = (clone $openBase)
            ->selectRaw('severity, COUNT(*) as cnt')
            ->groupBy('severity')
            ->toBase()
            ->get();

        // Build severityCounts {critical, warning, info} — zero-filled.
        $severityCounts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($sevRows as $row) {
            $sev = $row->severity;
            if ($sev === InsightSeverity::CRITICAL->value) {
                $severityCounts['critical'] += (int) $row->cnt;
            } elseif ($sev === InsightSeverity::WARNING->value) {
                $severityCounts['warning'] += (int) $row->cnt;
            } elseif (
                $sev === InsightSeverity::INFO->value
                || $sev === InsightSeverity::OPPORTUNITY->value
            ) {
                // INFO and OPPORTUNITY both map to the 'info' filter bucket.
                $severityCounts['info'] += (int) $row->cnt;
            }
        }

        // ── groupCounts: reuse sevRows when group=severity ────────────────────
        if ($group === 'severity') {
            $result = [];
            foreach (InsightSeverity::cases() as $sev) {
                $result[$sev->value] = [
                    'key' => $sev->value,
                    'label' => $sev->label(),
                    'count' => 0,
                ];
            }
            foreach ($sevRows as $row) {
                if (isset($result[$row->severity])) {
                    $result[$row->severity]['count'] = (int) $row->cnt;
                }
            }

            return [array_values($result), $severityCounts];
        }

        if ($group === 'domain') {
            $rows = (clone $openBase)
                ->selectRaw('type, COUNT(*) as cnt')
                ->groupBy('type')
                ->toBase()
                ->get();

            $domainCounts = [];
            foreach (InsightDomain::cases() as $domain) {
                $domainCounts[$domain->value] = [
                    'key' => $domain->value,
                    'label' => $domain->label(),
                    'count' => 0,
                ];
            }

            foreach ($rows as $row) {
                try {
                    $domainKey = InsightType::from($row->type)->domain()->value;
                    $domainCounts[$domainKey]['count'] += (int) $row->cnt;
                } catch (\ValueError) {
                    // Unknown type — skip.
                }
            }

            return [array_values($domainCounts), $severityCounts];
        }

        // group === 'site': two-step aggregate then resolve primary_domain.
        $rows = (clone $openBase)
            ->selectRaw('site_id, COUNT(*) as cnt')
            ->groupBy('site_id')
            ->toBase()
            ->get();

        $siteIds = $rows->pluck('site_id')->filter()->unique()->values()->all();
        // Site has no `name` column — primary_domain is the display label.
        $sitesById = $siteIds
            ? \App\Models\Site::withoutGlobalScopes()
                ->whereIn('id', $siteIds)
                ->pluck('primary_domain', 'id')
            : collect();

        $result = [];
        foreach ($rows as $row) {
            $siteId = $row->site_id ? (int) $row->site_id : null;
            $result[] = [
                'id' => $siteId,
                'name' => $siteId ? ($sitesById[$siteId] ?? '(unknown)') : '(no site)',
                'count' => (int) $row->cnt,
            ];
        }

        usort($result, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [$result, $severityCounts];
    }

    /**
     * Build proactive suggestions shown when the inbox is entirely empty.
     *
     * Three quick signals (max 3 DB queries total):
     *   (a) quick_wins   — open STRIKING_DISTANCE insights (INFO/OPPORTUNITY)
     *   (b) digest       — DigestService has no persistent rows; always null
     *   (c) stale_lh     — monitors whose last Lighthouse score is > 7 days old
     *
     * Returns an array usable directly as the 'suggestions' Inertia prop.
     */
    private function buildSuggestions(int $teamId): array
    {
        // (a) Quick wins: STRIKING_DISTANCE insights regardless of severity.
        $quickWinsCount = Insight::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNull('acknowledged_at')
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->count();

        // (b) Digest: DigestService has no persistent model/table — nothing to
        // suggest until digests are stored.

        // (c) Monitors with stale Lighthouse scores (last scored > 7 days ago).
        $staleLhCount = Monitor::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereDoesntHave('lighthouseScores', fn ($q) => $q->where('scored_at', '>=', now()->subDays(7)))
            ->count();

        // Shape matters: the page renders `v-for="s in suggestions"` over
        // {label, url, count} objects. The previous associative map
        // {quick_wins: {...}, last_digest: null, ...} serialised to a JSON
        // object whose values had neither label nor url, so the "Next steps"
        // block rendered empty rows (or nothing) whenever the inbox was clear.
        $suggestions = [];

        if ($quickWinsCount > 0) {
            $suggestions[] = [
                'label' => 'Review quick wins (striking distance)',
                'url' => route('inbox.index', ['severity' => 'info']),
                'count' => $quickWinsCount,
            ];
        }

        if ($staleLhCount > 0) {
            $suggestions[] = [
                'label' => 'Re-run stale Lighthouse audits',
                'url' => route('monitors.index'),
                'count' => $staleLhCount,
            ];
        }

        return $suggestions;
    }
}
