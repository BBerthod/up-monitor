<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IngestSourceResource;
use App\Models\IngestSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class IngestSourceApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sources = IngestSource::query()
            ->withCount('events')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return IngestSourceResource::collection($sources);
    }

    public function show(IngestSource $ingestSource): IngestSourceResource
    {
        $this->authorize('view', $ingestSource);

        return new IngestSourceResource($ingestSource->load('notificationChannels'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'notification_channel_ids' => ['nullable', 'array'],
            'notification_channel_ids.*' => ['integer', 'exists:notification_channels,id'],
        ]);

        $token = IngestSource::generateToken();

        $source = IngestSource::create([
            'name' => $validated['name'],
            'slug' => IngestSource::generateSlug($validated['name']),
            'token' => $token,
            'token_hash' => IngestSource::hashToken($token),
            'is_active' => $validated['is_active'] ?? true,
            'team_id' => $request->user()->team_id,
        ]);

        if (! empty($validated['notification_channel_ids'])) {
            $source->notificationChannels()->sync($validated['notification_channel_ids']);
        }

        // Plain token revealed exactly once at creation — store it client-side.
        return (new IngestSourceResource($source))
            ->additional(['token' => $token, 'token_warning' => 'Store this token now — it cannot be retrieved later.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, IngestSource $ingestSource): IngestSourceResource
    {
        $this->authorize('update', $ingestSource);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'notification_channel_ids' => ['nullable', 'array'],
            'notification_channel_ids.*' => ['integer', 'exists:notification_channels,id'],
        ]);

        if (isset($validated['name'])) {
            $ingestSource->slug = IngestSource::generateSlug($validated['name']);
        }

        $ingestSource->update(array_filter($validated, fn ($k) => ! in_array($k, ['notification_channel_ids']), ARRAY_FILTER_USE_KEY));

        if (array_key_exists('notification_channel_ids', $validated)) {
            $ingestSource->notificationChannels()->sync($validated['notification_channel_ids'] ?? []);
        }

        return new IngestSourceResource($ingestSource);
    }

    public function destroy(IngestSource $ingestSource): Response
    {
        $this->authorize('delete', $ingestSource);

        $ingestSource->delete();

        return response()->noContent();
    }

    public function rotateToken(IngestSource $ingestSource): JsonResponse
    {
        $this->authorize('update', $ingestSource);

        $newToken = IngestSource::generateToken();
        $ingestSource->update([
            'token' => $newToken,
            'token_hash' => IngestSource::hashToken($newToken),
        ]);

        // Plain token revealed exactly once on rotation.
        return (new IngestSourceResource($ingestSource))
            ->additional(['token' => $newToken, 'token_warning' => 'Store this token now — it cannot be retrieved later.'])
            ->response();
    }

    public function events(Request $request, IngestSource $ingestSource): JsonResponse
    {
        $this->authorize('view', $ingestSource);

        $query = $ingestSource->events()
            ->when($request->filled('level'), fn ($q) => $q->where('level', $request->input('level')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderByDesc('occurred_at');

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }
}
