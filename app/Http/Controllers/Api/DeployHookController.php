<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunPostDeploySmokeTests;
use App\Models\DeployEvent;
use App\Models\Monitor;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeployHookController extends Controller
{
    public function dokploy(Request $request): JsonResponse
    {
        // Validate shared secret from header
        $secret = $request->header('X-Dokploy-Secret');
        $expected = config('services.dokploy.webhook_secret');

        if (empty($expected) || ! hash_equals($expected, (string) $secret)) {
            Log::warning('DeployHookController: invalid or missing X-Dokploy-Secret', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'applicationId' => ['required', 'string', 'max:255'],
            'applicationName' => ['sometimes', 'string', 'max:255'],
            'commitSha' => ['nullable', 'string', 'max:40'],
            'deployedAt' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
        ]);

        $deployEvent = DeployEvent::create([
            'application_id' => $validated['applicationId'],
            'application_name' => $validated['applicationName'] ?? $validated['applicationId'],
            'commit_sha' => $validated['commitSha'] ?? null,
            'deployed_at' => $validated['deployedAt'] ?? now(),
            'smoke_test_status' => 'pending',
        ]);

        RunPostDeploySmokeTests::dispatch($deployEvent);

        $until = now()->addMinutes(config('monitoring.deploy_silence_minutes', 10));
        $siteIds = Site::withoutGlobalScopes()
            ->where('dokploy_app_id', $validated['applicationId'])
            ->pluck('id');
        if ($siteIds->isNotEmpty()) {
            Monitor::withoutGlobalScopes()
                ->whereIn('site_id', $siteIds)
                ->update(['deploying_until' => $until]);
        }

        Log::info('DeployHookController: deploy event received', [
            'deploy_event_id' => $deployEvent->id,
            'application_id' => $deployEvent->application_id,
        ]);

        return response()->json(['id' => $deployEvent->id], 200);
    }
}
