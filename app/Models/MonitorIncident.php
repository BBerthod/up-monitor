<?php

namespace App\Models;

use App\Enums\IncidentCause;
use App\Enums\IncidentSeverity;
use App\Models\Traits\ScopedByMonitorTeam;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorIncident extends Model
{
    use HasFactory;
    use ScopedByMonitorTeam;

    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'functional_check_id',
        'started_at',
        'resolved_at',
        'down_notified_at',
        'cause',
        'severity',
        'notes',
    ];

    protected $casts = [
        'cause' => IncidentCause::class,
        'severity' => IncidentSeverity::class,
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'down_notified_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function functionalCheck(): BelongsTo
    {
        return $this->belongsTo(FunctionalCheck::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    public function duration(): CarbonInterval
    {
        $end = $this->resolved_at ?? now();

        return CarbonInterval::make($this->started_at->diff($end));
    }

    public function resolve(): void
    {
        $this->resolved_at = now();
        $this->save();
    }
}
