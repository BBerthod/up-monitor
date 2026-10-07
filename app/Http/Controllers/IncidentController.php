<?php

namespace App\Http\Controllers;

use App\Enums\IncidentCause;
use App\Enums\IncidentSeverity;
use App\Http\Traits\FiltersBySiteScope;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class IncidentController extends Controller
{
    use FiltersBySiteScope;

    public function index(Request $request): \Inertia\Response
    {
        $teamId = $request->user()->team_id;

        $query = MonitorIncident::query()
            ->join('monitors', 'monitor_incidents.monitor_id', '=', 'monitors.id')
            ->leftJoin('functional_checks', 'monitor_incidents.functional_check_id', '=', 'functional_checks.id')
            ->where('monitors.team_id', $teamId)
            ->select([
                'monitor_incidents.*',
                'monitors.name as monitor_name',
                'monitors.type as monitor_type',
                'monitors.url as monitor_url',
                'functional_checks.name as functional_check_name',
            ]);

        // Apply site-scope lens: incidents are scoped via their monitor's site_id.
        $this->applySiteScope($query, 'monitors.site_id');

        // Filters
        if ($request->filled('status')) {
            match ($request->input('status')) {
                'active' => $query->whereNull('monitor_incidents.resolved_at'),
                'resolved' => $query->whereNotNull('monitor_incidents.resolved_at'),
                default => null,
            };
        }

        if ($request->filled('cause')) {
            $query->where('monitor_incidents.cause', $request->input('cause'));
        }

        if ($request->filled('monitor_id')) {
            $query->where('monitor_incidents.monitor_id', $request->input('monitor_id'));
        }

        if ($request->filled('from')) {
            $query->where('monitor_incidents.started_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('monitor_incidents.started_at', '<=', $request->input('to').' 23:59:59');
        }

        // Sorting
        $sortBy = $request->input('sort', 'started_at');
        $sortDir = $request->input('dir', 'desc');
        $allowedSorts = ['started_at', 'resolved_at', 'monitor_name'];

        if (in_array($sortBy, $allowedSorts)) {
            $column = $sortBy === 'monitor_name' ? 'monitors.name' : "monitor_incidents.{$sortBy}";
            $query->orderBy($column, $sortDir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('monitor_incidents.started_at');
        }

        $activeCount = MonitorIncident::whereNull('resolved_at')->count();

        // The frontend used to derive the label from the raw cause with a
        // str_replace, which mangled multi-word causes like failed_smoke_test.
        $incidents = $query->paginate(25)->withQueryString();
        $incidents->through(fn (MonitorIncident $incident) => tap($incident, fn ($i) => $i->cause_label = $i->cause->label()));

        return Inertia::render('Incidents/Index', [
            'incidents' => $incidents,
            'activeCount' => $activeCount,
            'monitors' => Monitor::select('id', 'name')->orderBy('name')->get(),
            'causes' => collect(IncidentCause::cases())->map(fn ($c) => ['value' => $c->value, 'label' => ucfirst(str_replace('_', ' ', $c->value))]),
            // Cast to object: only() returns [] when no filter is present, which
            // json_encode emits as a JSON array. The page then reads `sort` off a
            // JS Array and gets Array.prototype.sort, which ends up serialised
            // into the query string as "function sort() { [native code] }".
            'filters' => (object) $request->only(['status', 'cause', 'monitor_id', 'from', 'to', 'sort', 'dir']),
        ]);
    }

    public function export(Request $request): Response|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $format = $request->input('format', 'csv');
        $teamId = $request->user()->team_id;

        $query = MonitorIncident::query()
            ->join('monitors', 'monitor_incidents.monitor_id', '=', 'monitors.id')
            ->where('monitors.team_id', $teamId)
            ->select([
                'monitors.name as monitor_name',
                'monitors.type as monitor_type',
                'monitor_incidents.cause',
                'monitor_incidents.started_at',
                'monitor_incidents.resolved_at',
            ]);

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'active' => $query->whereNull('monitor_incidents.resolved_at'),
                'resolved' => $query->whereNotNull('monitor_incidents.resolved_at'),
                default => null,
            };
        }

        if ($request->filled('cause')) {
            $query->where('monitor_incidents.cause', $request->input('cause'));
        }

        if ($request->filled('monitor_id')) {
            $query->where('monitor_incidents.monitor_id', $request->input('monitor_id'));
        }

        if ($request->filled('from')) {
            $query->where('monitor_incidents.started_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('monitor_incidents.started_at', '<=', $request->input('to').' 23:59:59');
        }

        $incidents = $query->orderByDesc('monitor_incidents.started_at')->limit(10000)->get();

        if ($format === 'json') {
            $data = $incidents->map(fn ($i) => [
                'monitor' => $i->monitor_name,
                'type' => $i->monitor_type,
                'status' => $i->resolved_at ? 'resolved' : 'active',
                'cause' => $i->cause,
                'started_at' => $i->started_at,
                'resolved_at' => $i->resolved_at,
                'duration_seconds' => $i->resolved_at
                    ? $i->started_at->diffInSeconds($i->resolved_at)
                    : null,
            ]);

            return response($data->toJson(JSON_PRETTY_PRINT), 200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="incidents-'.now()->format('Y-m-d').'.json"',
            ]);
        }

        // CSV — streamed to avoid memory issues on large exports
        return response()->streamDownload(function () use ($query) {
            echo "Monitor,Type,Status,Cause,Started,Resolved,Duration (seconds)\n";
            $query->orderByDesc('monitor_incidents.started_at')->chunk(500, function ($incidents) {
                foreach ($incidents as $i) {
                    $status = $i->resolved_at ? 'resolved' : 'active';
                    $duration = $i->resolved_at
                        ? $i->started_at->diffInSeconds($i->resolved_at)
                        : '';
                    echo sprintf(
                        "%s,%s,%s,%s,%s,%s,%s\n",
                        '"'.str_replace('"', '""', $i->monitor_name).'"',
                        $i->monitor_type,
                        $status,
                        $i->cause,
                        $i->started_at,
                        $i->resolved_at ?? '',
                        $duration,
                    );
                }
            });
        }, 'incidents-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function update(Request $request, MonitorIncident $incident): RedirectResponse
    {
        $this->authorize('update', $incident);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
            'severity' => ['nullable', Rule::enum(IncidentSeverity::class)],
        ]);

        $incident->update($validated);

        return back()->with('success', 'Incident updated.');
    }
}
