<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Models\StatusPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StatusPageController extends Controller
{
    public function index(): Response
    {
        $statusPages = StatusPage::withCount('monitors')
            ->latest()
            ->get()
            ->map(fn ($sp) => [
                'id' => $sp->id,
                'name' => $sp->name,
                'slug' => $sp->slug,
                'is_active' => $sp->is_active,
                'monitors_count' => $sp->monitors_count,
            ]);

        return Inertia::render('StatusPages/Index', [
            'statusPages' => $statusPages,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('StatusPages/Create', [
            'monitors' => Monitor::select('id', 'name', 'url')->where('is_active', true)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateStatusPage($request);

        $monitors = $validated['monitors'] ?? [];
        unset($validated['monitors']);

        $statusPage = StatusPage::create(array_merge($validated, [
            'team_id' => $request->user()->team_id,
        ]));

        if (! empty($monitors)) {
            $sync = [];
            foreach ($monitors as $i => $id) {
                $sync[$id] = ['sort_order' => $i];
            }
            $statusPage->monitors()->sync($sync);
        }

        return to_route('status-pages.index')->with('success', 'Status page created.');
    }

    public function edit(StatusPage $statusPage): Response
    {
        $this->authorize('view', $statusPage);

        $statusPage->load(['monitors' => fn ($q) => $q->orderByPivot('sort_order')]);

        return Inertia::render('StatusPages/Edit', [
            'statusPage' => [
                'id' => $statusPage->id,
                'name' => $statusPage->name,
                'slug' => $statusPage->slug,
                'description' => $statusPage->description,
                'is_active' => $statusPage->is_active,
                'theme' => $statusPage->theme,
                'monitor_ids' => $statusPage->monitors->pluck('id'),
            ],
            'monitors' => Monitor::select('id', 'name', 'url')->where('is_active', true)->get(),
        ]);
    }

    public function update(Request $request, StatusPage $statusPage): RedirectResponse
    {
        $this->authorize('update', $statusPage);

        $validated = $this->validateStatusPage($request, $statusPage->id);

        $monitors = $validated['monitors'] ?? [];
        unset($validated['monitors']);

        $statusPage->update($validated);

        $sync = [];
        foreach ($monitors as $i => $id) {
            $sync[$id] = ['sort_order' => $i];
        }
        $statusPage->monitors()->sync($sync);

        return to_route('status-pages.index')->with('success', 'Status page updated.');
    }

    public function destroy(StatusPage $statusPage): RedirectResponse
    {
        $this->authorize('delete', $statusPage);

        $statusPage->delete();

        return to_route('status-pages.index')->with('success', 'Status page deleted.');
    }

    private function validateStatusPage(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('status_pages', 'slug')->ignore($ignoreId)],
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'theme' => 'required|in:dark,light',
            'monitors' => 'array',
            'monitors.*' => ['integer', Rule::exists('monitors', 'id')->where('team_id', $request->user()->team_id)],
        ]);
    }
}
