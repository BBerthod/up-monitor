<?php

namespace App\Http\Controllers\Api;

use App\Enums\IngestEventLevel;
use App\Enums\IngestEventType;
use App\Events\IngestEventReceived;
use App\Http\Controllers\Controller;
use App\Jobs\SendIngestNotification;
use App\Models\IngestEvent;
use App\Models\IngestSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class IngestController extends Controller
{
    public function receive(Request $request, ?string $token = null): JsonResponse
    {
        $source = $this->resolveSource($request, $token);

        if (! $source) {
            return response()->json(['error' => 'Invalid or inactive source token.'], 401);
        }

        // Batch or single event
        if ($request->has('events')) {
            return $this->storeBatch($request, $source);
        }

        return $this->storeSingle($request, $source);
    }

    private function storeSingle(Request $request, IngestSource $source): JsonResponse
    {
        $validated = $this->validateEvent($request);

        $event = $this->createEvent($source, $validated);

        $this->broadcastAndNotify($source, $event);

        return response()->json(['id' => $event->id], 201);
    }

    private function storeBatch(Request $request, IngestSource $source): JsonResponse
    {
        $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
        ]);

        $created = [];

        foreach ($request->input('events') as $eventData) {
            $validated = $this->validateEventArray($eventData);
            $event = $this->createEvent($source, $validated);
            $this->broadcastAndNotify($source, $event);
            $created[] = $event->id;
        }

        return response()->json(['ids' => $created, 'count' => count($created)], 201);
    }

    private function createEvent(IngestSource $source, array $data): IngestEvent
    {
        return IngestEvent::create([
            'source_id' => $source->id,
            'type' => $data['type'],
            'level' => $data['level'],
            'message' => $data['message'],
            'context' => $data['context'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'created_at' => now(),
        ]);
    }

    private function broadcastAndNotify(IngestSource $source, IngestEvent $event): void
    {
        IngestEventReceived::dispatch($source, $event);

        if (! $event->shouldNotify()) {
            return;
        }

        $cooldownKey = "ingest_notify:{$source->id}:{$event->level->value}";
        $cooldownMinutes = $event->level->notificationCooldownMinutes();

        if (Cache::has($cooldownKey)) {
            return;
        }

        $channels = $source->notificationChannels()->where('is_active', true)->get();

        foreach ($channels as $channel) {
            SendIngestNotification::dispatch($channel, $source, $event);
        }

        Cache::put($cooldownKey, true, now()->addMinutes($cooldownMinutes));
    }

    /**
     * Resolve an IngestSource from either:
     *  1. Bearer token header (preferred, performs SHA-256 hash lookup).
     *  2. URL path token parameter (deprecated — logs a deprecation warning).
     *
     * In both cases the lookup is done against token_hash (not the plain token),
     * preventing the plain-text secret from appearing in DB query logs.
     */
    private function resolveSource(Request $request, ?string $urlToken): ?IngestSource
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken !== null) {
            return IngestSource::withoutGlobalScopes()
                ->where('token_hash', hash('sha256', $bearerToken))
                ->where('is_active', true)
                ->first();
        }

        if ($urlToken !== null) {
            // Legacy path: token in the URL. Still supported for backward compat.
            // Fall back to token_hash when available, plain token otherwise (rows not yet migrated).
            Log::warning('IngestController: token passed in URL path (deprecated). Migrate to Authorization: Bearer.', [
                'ip' => $request->ip(),
            ]);

            $tokenHash = hash('sha256', $urlToken);

            // Primary: hash lookup (post-migration).
            $source = IngestSource::withoutGlobalScopes()
                ->where('token_hash', $tokenHash)
                ->where('is_active', true)
                ->first();

            // Fallback: plain-token lookup (pre-migration rows where token_hash is still NULL).
            if ($source === null) {
                $source = IngestSource::withoutGlobalScopes()
                    ->whereNull('token_hash')
                    ->where('token', $urlToken)
                    ->where('is_active', true)
                    ->first();
            }

            return $source;
        }

        return null;
    }

    private function validateEvent(Request $request): array
    {
        return $request->validate($this->eventRules());
    }

    private function validateEventArray(array $data): array
    {
        return validator($data, $this->eventRules())->validate();
    }

    private function eventRules(): array
    {
        return [
            'type' => ['required', Rule::in(array_column(IngestEventType::cases(), 'value'))],
            'level' => ['required', Rule::in(array_column(IngestEventLevel::cases(), 'value'))],
            'message' => ['required', 'string', 'max:10000'],
            'context' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
