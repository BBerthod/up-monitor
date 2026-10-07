<?php

namespace App\Http\Controllers;

use App\Models\DeployEvent;
use App\Models\SmokeTestConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentController extends Controller
{
    public function index(Request $request): Response
    {
        $deployEvents = DeployEvent::query()
            ->when($request->input('application_id'), fn ($q, $v) => $q->where('application_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('smoke_test_status', $v))
            ->orderByDesc('deployed_at')
            ->paginate(20)
            ->withQueryString();

        $smokeTestConfigs = SmokeTestConfig::orderBy('application_name')->get();

        $applicationIds = DeployEvent::distinct()->pluck('application_id');

        return Inertia::render('Deployments/Index', [
            'deployEvents' => $deployEvents,
            'smokeTestConfigs' => $smokeTestConfigs,
            'applicationIds' => $applicationIds,
            'filters' => $request->only(['application_id', 'status']),
        ]);
    }

    public function storeSmokeTestConfig(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'application_id' => ['required', 'string', 'max:255'],
            'application_name' => ['required', 'string', 'max:255'],
            'tests' => ['required', 'array', 'min:1'],
            'tests.*.url' => ['required', 'url'],
            'tests.*.expected_status' => ['required', 'integer', 'min:100', 'max:599'],
            'tests.*.expected_keyword' => ['nullable', 'string', 'max:500'],
            'tests.*.timeout_ms' => ['nullable', 'integer', 'min:100', 'max:30000'],
            'is_active' => ['boolean'],
        ]);

        SmokeTestConfig::updateOrCreate(
            ['application_id' => $validated['application_id']],
            $validated
        );

        return back()->with('success', 'Smoke test configuration saved.');
    }

    public function destroySmokeTestConfig(SmokeTestConfig $config): RedirectResponse
    {
        $config->delete();

        return back()->with('success', 'Smoke test configuration deleted.');
    }
}
