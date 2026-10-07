<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationChannelResource;
use App\Models\NotificationChannel;
use App\Services\Telegram\TelegramValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class NotificationChannelApiController extends Controller
{
    public function __construct(private TelegramValidator $telegramValidator) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $channels = NotificationChannel::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return NotificationChannelResource::collection($channels);
    }

    public function show(NotificationChannel $notificationChannel): NotificationChannelResource
    {
        return new NotificationChannelResource($notificationChannel);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateChannel($request);

        if (($validated['type'] ?? null) === 'telegram') {
            $result = $this->telegramValidator->validateChat(
                $validated['settings']['bot_token'],
                $validated['settings']['chat_id']
            );
            if (! $result['ok']) {
                abort(422, $result['error']);
            }
        }

        $channel = NotificationChannel::create(array_merge($validated, [
            'team_id' => $request->user()->team_id,
        ]));

        return (new NotificationChannelResource($channel))->response()->setStatusCode(201);
    }

    public function update(Request $request, NotificationChannel $notificationChannel): NotificationChannelResource
    {
        $validated = $this->validateChannel($request, isUpdate: true);

        if (($validated['type'] ?? null) === 'telegram' && isset($validated['settings']['chat_id'])) {
            $result = $this->telegramValidator->validateChat(
                $validated['settings']['bot_token'],
                $validated['settings']['chat_id']
            );
            if (! $result['ok']) {
                abort(422, $result['error']);
            }
        }

        $notificationChannel->update($validated);

        return new NotificationChannelResource($notificationChannel);
    }

    public function destroy(NotificationChannel $notificationChannel): Response
    {
        $notificationChannel->delete();

        return response()->noContent();
    }

    private function validateChannel(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => [($isUpdate ? 'sometimes' : 'required'), 'string', 'max:255'],
            'type' => [($isUpdate ? 'sometimes' : 'required'), Rule::in(array_column(ChannelType::cases(), 'value'))],
            'settings' => [($isUpdate ? 'sometimes' : 'present'), 'array'],
            'is_active' => ['boolean'],
        ];

        $type = $request->input('type');
        if ($type) {
            $rules = array_merge($rules, match ($type) {
                'email' => ['settings.recipients' => ['required', 'string', 'max:1000']],
                'webhook' => [
                    'settings.url' => ['required', 'url', 'max:2000'],
                    'settings.secret' => ['nullable', 'string', 'max:255'],
                ],
                'slack' => ['settings.webhook_url' => ['required', 'url', 'max:2000']],
                'discord' => ['settings.webhook_url' => ['required', 'url', 'max:2000']],
                'telegram' => [
                    'settings.bot_token' => ['required', 'string', 'max:255'],
                    'settings.chat_id' => ['required', 'string', 'max:255'],
                ],
                default => [],
            });
        }

        return $request->validate($rules);
    }
}
