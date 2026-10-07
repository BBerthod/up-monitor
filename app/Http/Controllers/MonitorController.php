<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMonitorRequest;
use App\Http\Requests\UpdateMonitorRequest;
use App\Http\Traits\FiltersBySiteScope;
use App\Jobs\RunLighthouseAudit;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\NotificationChannel;
use App\Services\MonitorShowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MonitorController extends Controller
{
    use FiltersBySiteScope;

    public function __construct(private MonitorShowService $monitorShowService) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');

        $query = Monitor::query()
            ->with(['checks' => fn ($q) => $q->latest('checked_at')->limit(1)])
            ->withCount(['incidents as active_incidents_count' => fn ($q) => $q->whereNull('resolved_at')])
            ->addSelect([
                'uptime_24h' => MonitorCheck::selectRaw(MonitorCheck::uptimeRaw(1))
                    ->whereColumn('monitor_checks.monitor_id', 'monitors.id')
                    ->where('checked_at', '>=', now()->subDay()),
            ]);

        // Apply the active site-scope lens (set by the topbar site-switcher).
        $this->applySiteScope($query);

        if ($status === 'paused') {
            $query->inactive();
        } elseif ($status === 'up') {
            $query->where('is_active', true)
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('monitor_checks as mc_filter')
                        ->whereColumn('mc_filter.monitor_id', 'monitors.id')
                        ->where('mc_filter.status', 'up')
                        ->whereRaw('mc_filter.id = (SELECT MAX(id) FROM monitor_checks WHERE monitor_id = monitors.id)');
                });
        } elseif ($status === 'down') {
            $query->where('is_active', true)
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('monitor_checks as mc_filter')
                        ->whereColumn('mc_filter.monitor_id', 'monitors.id')
                        ->where('mc_filter.status', '!=', 'up')
                        ->whereRaw('mc_filter.id = (SELECT MAX(id) FROM monitor_checks WHERE monitor_id = monitors.id)');
                });
        }

        $monitors = $query->orderByRaw('uptime_24h ASC NULLS LAST')->get()->map(function ($monitor) {
            $latestCheck = $monitor->checks->first();

            return [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'url' => $monitor->url,
                'is_active' => $monitor->is_active,
                'latest_check' => $latestCheck ? [
                    'status' => $latestCheck->status->value,
                    'response_time_ms' => $latestCheck->response_time_ms,
                    'checked_at' => $latestCheck->checked_at?->toIso8601String(),
                ] : null,
                'uptime_24h' => $monitor->uptime_24h !== null ? (float) $monitor->uptime_24h : null,
                'active_incidents_count' => $monitor->active_incidents_count,
            ];
        });

        return Inertia::render('Monitors/Index', [
            'monitors' => $monitors->values(),
            'filters' => ['status' => $status],
        ]);
    }

    public function show(Request $request, Monitor $monitor): Response
    {
        $this->authorize('view', $monitor);

        return Inertia::render('Monitors/Show', $this->monitorShowService->buildPayload($monitor, $request));
    }

    public function create(): Response
    {
        $scope = $this->currentSiteScope();

        return Inertia::render('Monitors/Create', [
            'notificationChannels' => NotificationChannel::where('is_active', true)
                ->get(['id', 'name', 'type']),
            // Pre-fill the site_id field when the topbar is scoped to a specific site.
            'defaultSiteId' => $scope->isSite() ? $scope->site?->id : null,
        ]);
    }

    public function store(StoreMonitorRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $channels = $validated['notification_channels'] ?? [];
        unset($validated['notification_channels']);

        $monitor = Monitor::create($validated);
        $monitor->notificationChannels()->sync($channels);

        return to_route('monitors.show', $monitor);
    }

    public function edit(Monitor $monitor): Response
    {
        $this->authorize('update', $monitor);

        return Inertia::render('Monitors/Edit', [
            'monitor' => array_merge($monitor->toArray(), [
                'notification_channel_ids' => $monitor->notificationChannels()->pluck('notification_channels.id'),
            ]),
            'notificationChannels' => NotificationChannel::where('is_active', true)
                ->get(['id', 'name', 'type']),
        ]);
    }

    public function update(UpdateMonitorRequest $request, Monitor $monitor): RedirectResponse
    {
        $this->authorize('update', $monitor);

        $validated = $request->validated();

        $channels = $validated['notification_channels'] ?? [];
        unset($validated['notification_channels']);

        $monitor->update($validated);
        $monitor->notificationChannels()->sync($channels);

        return to_route('monitors.show', $monitor);
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:pause,resume,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $monitors = Monitor::whereIn('id', $validated['ids'])->get();
        foreach ($monitors as $monitor) {
            $this->authorize($validated['action'] === 'delete' ? 'delete' : 'update', $monitor);
        }

        match ($validated['action']) {
            'pause' => Monitor::whereIn('id', $validated['ids'])->update(['is_active' => false]),
            'resume' => Monitor::whereIn('id', $validated['ids'])->update(['is_active' => true]),
            'delete' => Monitor::whereIn('id', $validated['ids'])->delete(),
        };

        return back()->with('success', count($validated['ids']).' monitors updated.');
    }

    public function destroy(Monitor $monitor): RedirectResponse
    {
        $this->authorize('delete', $monitor);

        $monitor->delete();

        return to_route('monitors.index');
    }

    public function pause(Monitor $monitor): RedirectResponse
    {
        $this->authorize('pause', $monitor);

        $monitor->update(['is_active' => false]);

        return back();
    }

    public function resume(Monitor $monitor): RedirectResponse
    {
        $this->authorize('resume', $monitor);

        $monitor->update(['is_active' => true]);

        return back();
    }

    public function lighthouse(Monitor $monitor): RedirectResponse
    {
        $this->authorize('lighthouse', $monitor);

        if ($monitor->type !== \App\Enums\MonitorType::HTTP) {
            abort(422, 'Lighthouse audits are only available for HTTP monitors.');
        }

        $cacheKey = "lighthouse_audit:{$monitor->id}";
        if (Cache::has($cacheKey)) {
            return back()->with('error', 'A Lighthouse audit was recently triggered. Please wait 5 minutes.');
        }

        Cache::put($cacheKey, true, now()->addMinutes(5));
        RunLighthouseAudit::dispatch($monitor);

        return back()->with('success', 'Lighthouse audit queued successfully.');
    }

    public function purge(Request $request, Monitor $monitor): RedirectResponse
    {
        $this->authorize('purge', $monitor);

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

        match ($validated['target']) {
            'checks' => $before
                ? $monitor->checks()->where('checked_at', '<', $before)->delete()
                : $monitor->checks()->delete(),
            'incidents' => $before
                ? $monitor->incidents()->where('started_at', '<', $before)->delete()
                : $monitor->incidents()->delete(),
            'lighthouse' => $before
                ? $monitor->lighthouseScores()->where('scored_at', '<', $before)->delete()
                : $monitor->lighthouseScores()->delete(),
        };

        Cache::forget("monitor:{$monitor->id}:uptime");
        Cache::forget("monitor:{$monitor->id}:heatmap");
        Cache::forget("monitor:{$monitor->id}:incident_stats");

        return back()->with('success', 'Monitor data purged successfully.');
    }

    public function lighthouseHistory(Request $request, Monitor $monitor): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->monitorShowService->getLighthouseHistory($request, $monitor));
    }
}
