<?php

namespace App\Http\Controllers;

use App\Enums\InsightType;
use App\Http\Presenters\InsightTriagePresenter;
use App\Http\Traits\FiltersBySiteScope;
use App\Models\Insight;
use App\Services\TriageService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Striking Distance page.
 *
 * Surfaces all open STRIKING_DISTANCE insights (all severities, including
 * INFO and OPPORTUNITY which are the typical severities for this type) sorted
 * by impact_score descending, paginated 25 per page.
 *
 * DESIGN NOTES
 * ─────────────────────────────────────────────────────────────────────────────
 * • All severities are included — STRIKING_DISTANCE insights are always set to
 *   OPPORTUNITY by StrikingDistanceService but we do not filter by severity here
 *   so that any future severity change does not silently hide items.
 *
 * • The site-scope lens (FiltersBySiteScope) is honoured: when a site is
 *   selected in the topbar we filter insights to that site_id.
 *
 * • counts() uses the TriageService standard badge counts (WARNING/CRITICAL only)
 *   so the badge in the nav stays consistent with the rest of the inbox.
 *
 * • withoutGlobalScopes() is not needed here because we are in an authenticated
 *   web request (auth() is valid). ScopedByTeam therefore applies automatically.
 *   We rely on TriageService::open() which explicitly calls withoutGlobalScopes()
 *   for consistency, but for the pagination query we use a plain Insight query
 *   scoped by team_id to leverage the same team isolation with one less query.
 */
class StrikingDistanceController extends Controller
{
    use FiltersBySiteScope;

    public function index(TriageService $triage): Response
    {
        $team = auth()->user()->team;

        if ($team === null) {
            return Inertia::render('StrikingDistance', [
                'items' => ['data' => [], 'links' => [], 'meta' => []],
                'counts' => ['total' => 0, 'critical' => 0, 'domains' => []],
            ]);
        }

        // ── Base query: open STRIKING_DISTANCE insights for this team ─────────
        // All severities included (OPPORTUNITY, INFO, WARNING, CRITICAL).
        $query = Insight::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('type', InsightType::STRIKING_DISTANCE->value)
            ->whereNull('acknowledged_at')
            ->where(fn ($q) => $q
                ->whereNull('snoozed_until')
                ->orWhere('snoozed_until', '<=', now())
            );

        // ── Apply site-scope lens ─────────────────────────────────────────────
        $scope = $this->currentSiteScope();

        if ($scope->isSite() && $scope->site !== null) {
            $query->where('site_id', $scope->site->id);
        } elseif ($scope->isUnassigned()) {
            $query->whereNull('site_id');
        }

        // ── Paginated result ──────────────────────────────────────────────────
        $paginator = (clone $query)
            ->orderByDesc('impact_score')
            ->with(['linkedSite', 'server', 'monitor'])
            ->paginate(25)
            ->withQueryString();

        $paginator->through(fn (Insight $i) => InsightTriagePresenter::present($i));

        return Inertia::render('StrikingDistance', [
            'items' => $paginator,
            'counts' => $triage->counts($team),
        ]);
    }
}
