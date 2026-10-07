<?php

namespace App\Models;

use App\Enums\WarmSiteMode;
use App\Models\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WarmSite extends Model
{
    use BelongsToTeam;
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'mode',
        'sitemap_url',
        'urls',
        'frequency_minutes',
        'max_urls',
        'custom_headers',
        'timeout_seconds',
        'is_active',
        'last_warmed_at',
        'monitor_id',
    ];

    protected $casts = [
        'mode' => WarmSiteMode::class,
        'urls' => 'array',
        'custom_headers' => 'array',
        'is_active' => 'boolean',
        'frequency_minutes' => 'integer',
        'max_urls' => 'integer',
        'timeout_seconds' => 'integer',
        'last_warmed_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Monitor::class);
    }

    public function warmRuns(): HasMany
    {
        return $this->hasMany(WarmRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(WarmRun::class)->latestOfMany('started_at');
    }

    public function latestCompletedRun(): HasOne
    {
        return $this->hasOne(WarmRun::class)
            ->ofMany(['started_at' => 'max'], fn ($q) => $q->where('status', 'completed'));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDueForWarming($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('last_warmed_at')
                    ->orWhereRaw('last_warmed_at <= now() - make_interval(mins => frequency_minutes)');
            });
    }

    /**
     * Returns the effective sitemap URL.
     * In sitemap mode, uses the explicit sitemap_url if set, otherwise falls back to /sitemap.xml.
     */
    public function getResolvedSitemapUrlAttribute(): ?string
    {
        if ($this->mode !== WarmSiteMode::SITEMAP) {
            return null;
        }

        if ($this->sitemap_url) {
            return $this->sitemap_url;
        }

        return 'https://'.rtrim($this->domain, '/').'/sitemap.xml';
    }
}
