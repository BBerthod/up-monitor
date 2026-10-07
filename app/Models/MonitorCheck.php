<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorCheck extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'status',
        'response_time_ms',
        'status_code',
        'ssl_expires_at',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'status' => CheckStatus::class,
        'ssl_expires_at' => 'datetime',
        'checked_at' => 'datetime',
        'response_time_ms' => 'integer',
        'status_code' => 'integer',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function scopeUp($query)
    {
        return $query->where('status', CheckStatus::UP);
    }

    public function scopeDown($query)
    {
        return $query->where('status', CheckStatus::DOWN);
    }

    /**
     * Add a selectRaw fragment that computes the uptime percentage.
     *
     * Usage in plain queries:
     *   MonitorCheck::uptimePercent()->where(...)->value('uptime')
     *
     * Usage as a correlated subquery inside addSelect():
     *   addSelect(['uptime_24h' => MonitorCheck::uptimeExpr(1)->whereColumn(...)->where(...)])
     */
    public function scopeUptimePercent($query, int $decimals = 1): void
    {
        $query->selectRaw(self::uptimeRaw($decimals).' as uptime');
    }

    /**
     * Return the raw SQL fragment (without alias) for use inside COALESCE or addSelect subqueries.
     */
    public static function uptimeRaw(int $decimals = 1): string
    {
        return "ROUND(AVG(CASE WHEN status = 'up' THEN 100 ELSE 0 END), {$decimals})";
    }

    public function isWarning(): bool
    {
        if (! $this->monitor->warning_threshold_ms) {
            return false;
        }

        return $this->response_time_ms >= $this->monitor->warning_threshold_ms;
    }

    public function isCritical(): bool
    {
        if (! $this->monitor->critical_threshold_ms) {
            return false;
        }

        return $this->response_time_ms >= $this->monitor->critical_threshold_ms;
    }
}
