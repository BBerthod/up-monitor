<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMonitorRequest;
use App\Http\Requests\UpdateMonitorRequest;
use App\Http\Resources\MonitorCheckResource;
use App\Http\Resources\MonitorResource;
use App\Models\Monitor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MonitorApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // BelongsToTeam global scope ensures only the authenticated team's monitors are returned.
        $monitors = Monitor::query()
            ->with(['checks' => fn ($q) => $q->latest('checked_at')->limit(1)])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return MonitorResource::collection($monitors);
    }

    public function show(Monitor $monitor): MonitorResource
    {
        $this->authorize('view', $monitor);

        $monitor->load([
            'checks' => fn ($q) => $q->latest('checked_at')->limit(1),
            'notificationChannels',
        ]);

        $checks = $monitor->checks()->latest('checked_at')->limit(50)->get();
        $incidents = $monitor->incidents()->latest('started_at')->limit(20)->get();

        $uptimeQuery = fn (int $days) => (float) ($monitor->checks()
            ->where('checked_at', '>=', now()->subDays($days))
            ->uptimePercent(2)
            ->value('uptime') ?? 100);

        return (new MonitorResource($monitor))->additional([
            'checks' => MonitorCheckResource::collection($checks),
            'incidents' => $incidents,
            'uptime' => [
                '24h' => $uptimeQuery(1),
                '7d' => $uptimeQuery(7),
                '30d' => $uptimeQuery(30),
                '90d' => $uptimeQuery(90),
            ],
        ]);
    }

    public function store(StoreMonitorRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $channels = $validated['notification_channels'] ?? [];
        unset($validated['notification_channels']);

        $monitor = Monitor::create(array_merge($validated, [
            'team_id' => $request->user()->team_id,
        ]));

        $monitor->notificationChannels()->sync($channels);

        return (new MonitorResource($monitor))->response()->setStatusCode(201);
    }

    public function update(UpdateMonitorRequest $request, Monitor $monitor): MonitorResource
    {
        $this->authorize('update', $monitor);

        $validated = $request->validated();

        $channels = $validated['notification_channels'] ?? null;
        unset($validated['notification_channels']);

        $monitor->update($validated);

        if ($channels !== null) {
            $monitor->notificationChannels()->sync($channels);
        }

        return new MonitorResource($monitor);
    }

    public function destroy(Monitor $monitor): Response
    {
        $this->authorize('delete', $monitor);

        $monitor->delete();

        return response()->noContent();
    }

    public function pause(Monitor $monitor): JsonResponse
    {
        $this->authorize('pause', $monitor);

        $monitor->update(['is_active' => false]);

        return response()->json(['message' => 'Monitor paused.']);
    }

    public function resume(Monitor $monitor): JsonResponse
    {
        $this->authorize('resume', $monitor);

        $monitor->update(['is_active' => true]);

        return response()->json(['message' => 'Monitor resumed.']);
    }

    public function checks(Request $request, Monitor $monitor): AnonymousResourceCollection
    {
        $this->authorize('view', $monitor);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:up,down'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $monitor->checks()
            ->when($validated['from'] ?? null, fn ($q) => $q->where('checked_at', '>=', Carbon::parse($validated['from'])))
            ->when($validated['to'] ?? null, fn ($q) => $q->where('checked_at', '<=', Carbon::parse($validated['to'])->endOfDay()))
            ->when($validated['status'] ?? null, fn ($q) => $q->where('status', $validated['status']))
            ->latest('checked_at');

        return MonitorCheckResource::collection($query->paginate($validated['per_page'] ?? 50));
    }
}
