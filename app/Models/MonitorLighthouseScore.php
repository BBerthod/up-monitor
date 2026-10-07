<?php

namespace App\Models;

use App\Models\Traits\ScopedByMonitorTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorLighthouseScore extends Model
{
    use HasFactory;
    use ScopedByMonitorTeam;

    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'performance',
        'accessibility',
        'best_practices',
        'seo',
        'lcp',
        'lcp_observed',
        'fcp',
        'cls',
        'tbt',
        'benchmark_index',
        'speed_index',
        'scored_at',
    ];

    protected $casts = [
        'performance' => 'integer',
        'accessibility' => 'integer',
        'best_practices' => 'integer',
        'seo' => 'integer',
        'lcp' => 'float',
        'lcp_observed' => 'float',
        'fcp' => 'float',
        'cls' => 'float',
        'tbt' => 'float',
        'benchmark_index' => 'float',
        'speed_index' => 'float',
        'scored_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
