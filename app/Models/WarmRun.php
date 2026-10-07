<?php

namespace App\Models;

use App\Enums\WarmRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarmRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'warm_site_id',
        'urls_total',
        'urls_hit',
        'urls_miss',
        'urls_error',
        'avg_response_ms',
        'status',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => WarmRunStatus::class,
        'urls_total' => 'integer',
        'urls_hit' => 'integer',
        'urls_miss' => 'integer',
        'urls_error' => 'integer',
        'avg_response_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function warmSite(): BelongsTo
    {
        return $this->belongsTo(WarmSite::class);
    }

    public function urls(): HasMany
    {
        return $this->hasMany(WarmRunUrl::class);
    }

    /**
     * Ratio of cache hits to total URLs processed (0.0 – 1.0).
     * Returns null when no URLs have been processed yet.
     */
    public function getHitRatioAttribute(): ?float
    {
        if ($this->urls_total === 0) {
            return null;
        }

        return round($this->urls_hit / $this->urls_total, 4);
    }

    /**
     * Total duration of the run in seconds.
     * Returns null while the run is still in progress.
     */
    public function getDurationSecondsAttribute(): ?int
    {
        if ($this->completed_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }
}
