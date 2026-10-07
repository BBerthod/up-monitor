<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Models\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NotificationChannel extends Model
{
    use BelongsToTeam, HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'type',
        'settings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'monitor_notification_channel');
    }

    /**
     * Settings with sensitive keys redacted for safe API exposure.
     * Public API responses must never include raw secrets (bot tokens, webhook URLs, VAPID keys).
     */
    public function safeSettings(): array
    {
        $settings = $this->settings ?? [];
        $sensitiveKeys = [
            'bot_token', 'token', 'api_key', 'secret', 'password',
            'webhook_url', 'url', 'endpoint',
            'vapid_private_key', 'vapid_public_key', 'p256dh', 'auth',
        ];

        foreach ($sensitiveKeys as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = $this->redact($settings[$key]);
            }
        }

        return $settings;
    }

    private function redact(mixed $value): string
    {
        if (! is_string($value) || strlen($value) <= 8) {
            return '[redacted]';
        }

        return substr($value, 0, 4).str_repeat('*', max(4, strlen($value) - 8)).substr($value, -4);
    }
}
