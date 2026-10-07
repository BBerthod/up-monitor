<?php

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Models\NotificationChannel;
use App\Services\NotificationService;
use App\Services\Telegram\TelegramValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationChannelController extends Controller
{
    public function __construct(
        private TelegramValidator $telegramValidator,
        private NotificationService $notificationService,
    ) {}

    public function index(): Response
    {
        $channels = NotificationChannel::query()
            ->latest()
            ->get()
            ->map(fn ($channel) => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type->value,
                'is_active' => $channel->is_active,
                'summary' => $this->getSettingsSummary($channel->type, $channel->settings),
            ]);

        return Inertia::render('NotificationChannels/Index', [
            'channels' => $channels,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('NotificationChannels/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateChannel($request);

        if ($validated['type'] === 'telegram') {
            $validation = $this->telegramValidator->validateChat(
                $validated['settings']['bot_token'],
                $validated['settings']['chat_id']
            );

            if (! $validation['ok']) {
                return back()->withInput()->withErrors(['settings.chat_id' => $validation['error']]);
            }
        }

        NotificationChannel::create(array_merge($validated, [
            'team_id' => $request->user()->team_id,
        ]));

        return to_route('channels.index')->with('success', 'Notification channel created.');
    }

    public function edit(NotificationChannel $channel): Response
    {
        $this->authorize('view', $channel);

        return Inertia::render('NotificationChannels/Edit', [
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type->value,
                'is_active' => $channel->is_active,
                'settings' => $channel->settings,
            ],
        ]);
    }

    public function update(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $this->authorize('update', $channel);

        $validated = $this->validateChannel($request);

        if ($validated['type'] === 'telegram') {
            $validation = $this->telegramValidator->validateChat(
                $validated['settings']['bot_token'],
                $validated['settings']['chat_id']
            );

            if (! $validation['ok']) {
                return back()->withInput()->withErrors(['settings.chat_id' => $validation['error']]);
            }
        }

        $channel->update($validated);

        return to_route('channels.index')->with('success', 'Notification channel updated.');
    }

    public function destroy(NotificationChannel $channel): RedirectResponse
    {
        $this->authorize('delete', $channel);

        $channel->delete();

        return to_route('channels.index')->with('success', 'Notification channel deleted.');
    }

    public function test(NotificationChannel $channel): RedirectResponse
    {
        $this->authorize('test', $channel);

        try {
            $this->notificationService->sendTestNotification($channel);

            return back()->with('success', "Test notification sent to {$channel->name}.");
        } catch (\Throwable $e) {
            return back()->with('error', "Test notification failed: {$e->getMessage()}");
        }
    }

    private function getSettingsSummary(ChannelType $type, array $settings): string
    {
        return match ($type) {
            ChannelType::EMAIL => sprintf(
                '%d recipient%s',
                $this->countRecipients($settings['recipients'] ?? ''),
                $this->countRecipients($settings['recipients'] ?? '') !== 1 ? 's' : ''
            ),
            ChannelType::WEBHOOK,
            ChannelType::SLACK,
            ChannelType::DISCORD,
            ChannelType::TELEGRAM,
            ChannelType::PUSH => 'Configured',
        };
    }

    private function countRecipients(mixed $recipients): int
    {
        if (is_array($recipients)) {
            return count(array_filter($recipients));
        }

        return count(array_filter(array_map('trim', explode(',', (string) $recipients))));
    }

    private function validateChannel(Request $request): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_column(ChannelType::cases(), 'value'))],
            'settings' => ['present', 'array'],
            'is_active' => ['boolean'],
        ];

        $rules = array_merge($rules, match ($request->input('type')) {
            'email' => ['settings.recipients' => ['required', 'string', 'max:1000', 'regex:/^[\w.+\-]+@[\w\-]+\.[\w.]+(\s*,\s*[\w.+\-]+@[\w\-]+\.[\w.]+)*$/']],
            'webhook' => [
                'settings.url' => ['required', 'url', 'regex:#^https?://#i', 'max:2000'],
                'settings.secret' => ['nullable', 'string', 'max:255'],
            ],
            'slack' => ['settings.webhook_url' => ['required', 'url', 'regex:#^https?://#i', 'max:2000']],
            'discord' => ['settings.webhook_url' => ['required', 'url', 'regex:#^https?://#i', 'max:2000']],
            'telegram' => [
                'settings.bot_token' => ['required', 'string', 'max:255'],
                'settings.chat_id' => ['required', 'string', 'max:255'],
            ],
            default => [],
        });

        return $request->validate($rules);
    }
}
