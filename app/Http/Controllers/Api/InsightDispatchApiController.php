<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchInsights;
use Illuminate\Http\JsonResponse;

/**
 * Triggers an on-demand insight detection run (striking-distance, content-decay,
 * broken pages, affiliate leaks), instead of waiting for the scheduled 04:00 run.
 *
 * Useful right after a KPI collection (which refreshes page_metrics) to see the
 * copilot surface insights without waiting overnight.
 */
class InsightDispatchApiController extends Controller
{
    public function dispatch(): JsonResponse
    {
        // Same dispatcher the scheduler runs at 04:00. It iterates active
        // monitors and chains the per-monitor detectors on the queue.
        DispatchInsights::dispatch();

        return response()->json([
            'dispatched' => true,
            'message' => 'Insight detection queued. Insights will appear once the worker processes it.',
        ], 202);
    }
}
