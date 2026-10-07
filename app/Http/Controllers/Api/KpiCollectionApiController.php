<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchKpiCollection;
use Illuminate\Http\JsonResponse;

/**
 * Triggers an on-demand KPI collection run (TTFB + GSC + GA4 per site),
 * instead of waiting for the scheduled 03:00 UTC run.
 *
 * Useful right after adding/editing sites or credentials to see the copilot
 * populate without waiting overnight.
 */
class KpiCollectionApiController extends Controller
{
    public function collect(): JsonResponse
    {
        // Dispatch the same dispatcher the scheduler runs. It iterates active
        // Sites for GSC/GA4/TTFB and falls back to TTFB for orphan monitors.
        DispatchKpiCollection::dispatch();

        return response()->json([
            'dispatched' => true,
            'message' => 'KPI collection queued. Snapshots and insights will appear once the worker processes it.',
        ], 202);
    }
}
