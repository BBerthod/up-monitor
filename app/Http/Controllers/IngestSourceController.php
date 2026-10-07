<?php

namespace App\Http\Controllers;

use App\Models\IngestSource;
use App\Models\NotificationChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IngestSourceController extends Controller
{
    public function index(): Response
    {
        $sources = IngestSource::query()
            ->withCount('events')
            ->with('notificationChannels:id,name,type')
            ->latest()
            ->get()
            ->map(fn ($source) => [
                'id' => $source->id,
                'name' => $source->name,
                'slug' => $source->slug,
                'token' => $source->token,
                'is_active' => $source->is_active,
                'events_count' => $source->events_count,
                'notification_channels' => $source->notificationChannels->map(fn ($ch) => [
                    'id' => $ch->id,
                    'name' => $ch->name,
                    'type' => $ch->type->value,
                ]),
                'created_at' => $source->created_at->toIso8601String(),
            ]);

        $channels = NotificationChannel::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'type'])
            ->map(fn ($ch) => ['id' => $ch->id, 'name' => $ch->name, 'type' => $ch->type->value]);

        return Inertia::render('Sources/Index', [
            'sources' => $sources,
            'notificationChannels' => $channels,
        ]);
    }

    public function store(Request $request): RedirectResponse
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

        return to_route('sources.index')->with('success', "Source \"{$source->name}\" created.");
    }

    public function update(Request $request, IngestSource $source): RedirectResponse
    {
        $this->authorize('update', $source);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'notification_channel_ids' => ['nullable', 'array'],
            'notification_channel_ids.*' => ['integer', 'exists:notification_channels,id'],
        ]);

        $source->update([
            'name' => $validated['name'],
            'slug' => IngestSource::generateSlug($validated['name']),
            'is_active' => $validated['is_active'] ?? $source->is_active,
        ]);

        $source->notificationChannels()->sync($validated['notification_channel_ids'] ?? []);

        return to_route('sources.index')->with('success', "Source \"{$source->name}\" updated.");
    }

    public function destroy(IngestSource $source): RedirectResponse
    {
        $this->authorize('delete', $source);

        $name = $source->name;
        $source->delete();

        return to_route('sources.index')->with('success', "Source \"{$name}\" deleted.");
    }

    public function rotateToken(IngestSource $source): RedirectResponse
    {
        $this->authorize('update', $source);

        $newToken = IngestSource::generateToken();
        $source->update([
            'token' => $newToken,
            'token_hash' => IngestSource::hashToken($newToken),
        ]);

        return back()->with('success', 'Token rotated successfully.');
    }
}
