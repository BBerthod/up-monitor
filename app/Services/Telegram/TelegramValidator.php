<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TelegramValidator
{
    /**
     * Validate that a Telegram bot token and chat ID are valid.
     *
     * @return array{ok: bool, title: ?string, error: ?string}
     */
    public function validateChat(string $token, string $chatId): array
    {
        try {
            $response = Http::timeout(10)
                ->get("https://api.telegram.org/bot{$token}/getChat", [
                    'chat_id' => $chatId,
                ]);

            $data = $response->json();

            if (! ($data['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'title' => null,
                    'error' => $data['description'] ?? 'Invalid chat ID.',
                ];
            }

            return [
                'ok' => true,
                'title' => $data['result']['title'] ?? $data['result']['username'] ?? null,
                'error' => null,
            ];
        } catch (ConnectionException) {
            return [
                'ok' => false,
                'title' => null,
                'error' => 'Connection to Telegram API failed. Please try again.',
            ];
        }
    }
}
