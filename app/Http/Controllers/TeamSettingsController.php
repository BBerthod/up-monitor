<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTeamRequest;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorLighthouseScore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class TeamSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->team;
        $members = $team->users()->get(['id', 'name', 'email', 'created_at']);
        $tokens = $request->user()->tokens()->latest()->get(['id', 'name', 'created_at', 'last_used_at']);

        $weeklyReportEnabled = (bool) $request->user()->weekly_report_enabled;

        return Inertia::render('Settings/Index', compact('team', 'members', 'tokens', 'weeklyReportEnabled'));
    }

    public function updateTeam(UpdateTeamRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $attributes = array_key_exists('sla_target', $validated)
            ? ['sla_target' => $validated['sla_target']]
            : ['name' => $validated['name']];

        $request->user()->team->update($attributes);

        return back()->with('success', 'Team updated.');
    }

    public function createToken(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:255']);
        $token = $request->user()->createToken($validated['name']);

        return back()->with([
            'success' => 'API Token created successfully.',
            'newToken' => $token->plainTextToken,
        ]);
    }

    public function deleteToken(Request $request, int $tokenId): RedirectResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->delete();

        return back()->with('success', 'Token revoked.');
    }

    public function updateWeeklyReport(Request $request): RedirectResponse
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);
        $request->user()->update(['weekly_report_enabled' => $validated['enabled']]);

        return back()->with('success', 'Weekly report preference updated.');
    }

    public function purgeAll(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target' => ['required', 'string', 'in:checks,incidents,lighthouse'],
            'period' => ['required', 'string', 'in:all,30d,90d,1y'],
        ]);

        $before = match ($validated['period']) {
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => null,
        };

        $monitorIds = $request->user()->team->monitors()->pluck('id');

        match ($validated['target']) {
            'checks' => MonitorCheck::whereIn('monitor_id', $monitorIds)
                ->when($before, fn ($q) => $q->where('checked_at', '<', $before))
                ->delete(),
            'incidents' => MonitorIncident::whereIn('monitor_id', $monitorIds)
                ->when($before, fn ($q) => $q->where('started_at', '<', $before))
                ->delete(),
            'lighthouse' => MonitorLighthouseScore::whereIn('monitor_id', $monitorIds)
                ->when($before, fn ($q) => $q->where('scored_at', '<', $before))
                ->delete(),
        };

        foreach ($monitorIds as $id) {
            Cache::forget("monitor:{$id}:uptime");
            Cache::forget("monitor:{$id}:heatmap");
            Cache::forget("monitor:{$id}:incident_stats");
        }

        return back()->with('success', 'Data purged successfully.');
    }
}
