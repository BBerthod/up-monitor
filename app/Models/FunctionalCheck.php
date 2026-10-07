<?php

namespace App\Models;

use App\Enums\FunctionalCheckStatus;
use App\Enums\FunctionalCheckType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FunctionalCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'monitor_id',
        'name',
        'url',
        'type',
        'rules',
        'check_interval',
        'last_checked_at',
        'last_status',
        'is_enabled',
    ];

    protected $casts = [
        'type' => FunctionalCheckType::class,
        'last_status' => FunctionalCheckStatus::class,
        'rules' => 'array',
        'is_enabled' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(FunctionalCheckResult::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(MonitorIncident::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeDueForCheck($query)
    {
        return $query->where('is_enabled', true)
            ->where(function ($q) {
                $q->whereNull('last_checked_at');

                if ($q->getConnection()->getDriverName() === 'pgsql') {
                    $q->orWhereRaw('last_checked_at <= now() - make_interval(mins => check_interval)');
                } else {
                    $q->orWhereRaw("last_checked_at <= datetime('now', '-' || check_interval || ' minutes')");
                }
            });
    }

    public function resolveUrl(): string
    {
        if (str_starts_with($this->url, 'http://') || str_starts_with($this->url, 'https://')) {
            return $this->url;
        }

        $parsed = parse_url($this->monitor->url);
        $baseUrl = $parsed['scheme'].'://'.$parsed['host'];

        if (isset($parsed['port'])) {
            $baseUrl .= ':'.$parsed['port'];
        }

        return $baseUrl.'/'.ltrim($this->url, '/');
    }
}
