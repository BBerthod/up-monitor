<?php

namespace App\Models;

use App\Models\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class IngestSource extends Model
{
    use BelongsToTeam;
    use HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'token',
        'token_hash',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(IngestEvent::class, 'source_id');
    }

    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class, 'ingest_source_notification_channel');
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Generate the SHA-256 hash of the given plain-text token.
     * Store this hash in DB; never store the plain token for lookup.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function generateSlug(string $name): string
    {
        return Str::slug($name);
    }
}
