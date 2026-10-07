<?php

namespace App\Http\Controllers;

use App\Enums\IngestEventLevel;
use App\Enums\IngestEventType;
use App\Models\IngestEvent;
use App\Models\IngestSource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $sources = IngestSource::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $query = IngestEvent::query()
            ->join('ingest_sources', 'ingest_events.source_id', '=', 'ingest_sources.id')
            ->select([
                'ingest_events.*',
                'ingest_sources.name as source_name',
                'ingest_sources.team_id',
            ])
            ->where('ingest_sources.team_id', $request->user()->team_id);

        // Filters
        if ($request->filled('source_id')) {
            $query->where('ingest_events.source_id', $request->input('source_id'));
        }

        if ($request->filled('level')) {
            $query->where('ingest_events.level', $request->input('level'));
        }

        if ($request->filled('type')) {
            $query->where('ingest_events.type', $request->input('type'));
        }

        if ($request->filled('from')) {
            $query->where('ingest_events.occurred_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('ingest_events.occurred_at', '<=', $request->input('to').' 23:59:59');
        }

        $query->orderByDesc('ingest_events.occurred_at');

        $alertCount = IngestEvent::query()
            ->join('ingest_sources', 'ingest_events.source_id', '=', 'ingest_sources.id')
            ->where('ingest_sources.team_id', $request->user()->team_id)
            ->whereIn('ingest_events.level', [IngestEventLevel::CRITICAL->value, IngestEventLevel::EMERGENCY->value])
            ->where('ingest_events.occurred_at', '>=', now()->subHours(24))
            ->count();

        return Inertia::render('Events/Index', [
            'events' => $query->cursorPaginate(50)->withQueryString(),
            'sources' => $sources,
            'alertCount' => $alertCount,
            'levels' => collect(IngestEventLevel::cases())->map(fn ($l) => ['value' => $l->value, 'label' => $l->label()]),
            'types' => collect(IngestEventType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
            'filters' => $request->only(['source_id', 'level', 'type', 'from', 'to']),
        ]);
    }
}
