<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\MonitorMethod;
use App\Enums\MonitorType;
use App\Models\Traits\BelongsToTeam;
use App\Support\UrlNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Monitor extends Model
{
    use BelongsToTeam;
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'url',
        'method',
        'expected_status_code',
        'keyword',
        'redirect_location_keyword',
        'request_headers',
        'verify_tls',
        'follow_redirects',
        'port',
        'dns_record_type',
        'dns_expected_value',
        'interval',
        'is_active',
        'is_priority',
        'badge_secret',
        'last_checked_at',
        'warning_threshold_ms',
        'critical_threshold_ms',
        'request_timeout_s',
        'alert_after_failures',
        'site_id',
        'deploying_until',
        'lighthouse_enabled',
    ];

    protected $casts = [
        'type' => MonitorType::class,
        'method' => MonitorMethod::class,
        'is_active' => 'boolean',
        'is_priority' => 'boolean',
        'verify_tls' => 'boolean',
        'follow_redirects' => 'boolean',
        'lighthouse_enabled' => 'boolean',
        'request_headers' => 'array',
        'last_checked_at' => 'datetime',
        'expected_status_code' => 'integer',
        'port' => 'integer',
        'warning_threshold_ms' => 'integer',
        'critical_threshold_ms' => 'integer',
        'request_timeout_s' => 'integer',
        'alert_after_failures' => 'integer',
        'deploying_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Monitor $monitor): void {
            if (empty($monitor->badge_secret)) {
                $monitor->badge_secret = Str::random(32);
            }
        });

        // Keep `normalized_url` (host-casing/trailing-slash agnostic — see
        // UrlNormalizer) in sync with `url` so the (team_id, normalized_url)
        // unique index scoped to HTTP monitors — see the corresponding
        // migration — always reflects the current value.
        static::saving(function (Monitor $monitor): void {
            if ($monitor->isDirty('url') || empty($monitor->normalized_url)) {
                $monitor->normalized_url = UrlNormalizer::normalize((string) $monitor->url);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(MonitorIncident::class);
    }

    public function lighthouseScores(): HasMany
    {
        return $this->hasMany(MonitorLighthouseScore::class);
    }

    public function functionalChecks(): HasMany
    {
        return $this->hasMany(FunctionalCheck::class);
    }

    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class, 'monitor_notification_channel');
    }

    public function warmSite(): HasOne
    {
        return $this->hasOne(\App\Models\WarmSite::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeDueForCheck($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('last_checked_at');

                if ($q->getConnection()->getDriverName() === 'pgsql') {
                    $q->orWhereRaw('last_checked_at <= now() - make_interval(mins => "interval")');
                } else {
                    $q->orWhereRaw("last_checked_at <= datetime('now', '-' || \"interval\" || ' minutes')");
                }
            });
    }

    public function isUp(): bool
    {
        return $this->checks()->latest('checked_at')->value('status') === CheckStatus::UP;
    }

    public function isDown(): bool
    {
        $status = $this->checks()->latest('checked_at')->value('status');

        return $status !== null && $status !== CheckStatus::UP;
    }
}
