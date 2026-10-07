<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInsightRequest;
use App\Models\Insight;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

/**
 * External ingestion endpoint for the /monitor Claude Code skill.
 *
 * Allows an authenticated Sanctum token to push a pre-computed Insight
 * (e.g. ssl_expiry, domain_expiry, uptime_incident) directly into Up without
 * waiting for the nightly detection run.
 *
 * source is always forced to 'monitor' server-side — the caller cannot override it.
 * detected_at defaults to now() when omitted.
 *
 * Multi-tenant isolation: site_id, when provided, is validated against the
 * authenticated team before insert (prevents cross-team data injection).
 *
 * Idempotency: simple create (no updateOrCreate). The /monitor skill is
 * responsible for deduplication at its end. Duplicate findings produce
 * duplicate insights — acceptable given the low write cadence of the skill.
 */
class InsightIngestionController extends Controller
{
    public function store(StoreInsightRequest $request): JsonResponse
    {
        $teamId = $request->user()->team_id;

        // Multi-tenant guard: reject site_id that belongs to another team.
        if ($request->filled('site_id')) {
            $siteExists = Site::where('id', $request->integer('site_id'))
                ->where('team_id', $teamId)
                ->exists();

            if (! $siteExists) {
                return response()->json([
                    'message' => 'The selected site_id does not belong to your team.',
                    'errors' => ['site_id' => ['Invalid site_id.']],
                ], 422);
            }
        }

        $insight = Insight::create([
            'team_id' => $teamId,
            'type' => $request->input('type'),
            'severity' => $request->input('severity'),
            'title' => $request->input('title'),
            'site' => $request->input('site'),
            'site_id' => $request->input('site_id'),
            'payload' => $request->input('payload'),
            'impact_score' => $request->input('impact_score', 0),
            'detected_at' => $request->date('detected_at') ?? now(),
            'source' => 'monitor',
        ]);

        return response()->json(['data' => $insight], 201);
    }
}
