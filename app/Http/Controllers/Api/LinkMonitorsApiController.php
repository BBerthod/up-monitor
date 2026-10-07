<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * Triggers the sites:link-monitors command on demand, instead of waiting for
 * the scheduled 02:45 run.
 *
 * Useful right after adding monitors or sites to attribute monitors to their
 * site (sets monitor.site_id by matching the monitor host to a site's domains).
 */
class LinkMonitorsApiController extends Controller
{
    public function link(): JsonResponse
    {
        // Run synchronously — the command is fast (a handful of monitors) and the
        // caller wants the result immediately. Capture the textual output.
        Artisan::call('sites:link-monitors');

        return response()->json([
            'dispatched' => true,
            'output' => Artisan::output(),
        ], 200);
    }
}
