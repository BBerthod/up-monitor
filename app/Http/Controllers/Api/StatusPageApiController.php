<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StatusPageResource;
use App\Models\StatusPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StatusPageApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $statusPages = StatusPage::query()
            ->with('monitors:id,name,url')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return StatusPageResource::collection($statusPages);
    }

    public function show(StatusPage $statusPage): StatusPageResource
    {
        $statusPage->load('monitors');

        return new StatusPageResource($statusPage);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:status_pages,slug',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'theme' => 'required|in:dark,light',
            'monitors' => 'array',
            'monitors.*' => 'exists:monitors,id',
        ]);

        $monitors = $validated['monitors'] ?? [];
        unset($validated['monitors']);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

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

        $statusPage->load('monitors');

        return (new StatusPageResource($statusPage))->response()->setStatusCode(201);
    }

    public function update(Request $request, StatusPage $statusPage): StatusPageResource
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('status_pages', 'slug')->ignore($statusPage->id)],
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'theme' => 'sometimes|required|in:dark,light',
            'monitors' => 'array',
            'monitors.*' => 'exists:monitors,id',
        ]);

        if (isset($validated['monitors'])) {
            $sync = [];
            foreach ($validated['monitors'] as $i => $id) {
                $sync[$id] = ['sort_order' => $i];
            }
            $statusPage->monitors()->sync($sync);
            unset($validated['monitors']);
        }

        $statusPage->update($validated);
        $statusPage->load('monitors');

        return new StatusPageResource($statusPage);
    }

    public function destroy(StatusPage $statusPage): Response
    {
        $statusPage->delete();

        return response()->noContent();
    }

    public function publicShow(string $slug): StatusPageResource
    {
        $statusPage = StatusPage::withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with('monitors')
            ->firstOrFail();

        return new StatusPageResource($statusPage);
    }
}
