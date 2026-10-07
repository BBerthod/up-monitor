<?php

namespace App\Models;

use App\Enums\KpiRegressionSeverity;
use App\Enums\KpiSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessKpiIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'site',
        'source',
        'metric',
        'severity',
        'baseline_value',
        'current_value',
        'delta_pct',
        'detected_at',
        'resolved_at',
        'notification_sent_at',
    ];

    protected $casts = [
        'source' => KpiSource::class,
        'severity' => KpiRegressionSeverity::class,
        'baseline_value' => 'decimal:4',
        'current_value' => 'decimal:4',
        'delta_pct' => 'decimal:4',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'notification_sent_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Find an open (unresolved) incident for a specific (site, source, metric) triple.
     */
    public static function findOpen(string $site, KpiSource $source, string $metric): ?self
    {
        return static::where('site', $site)
            ->where('source', $source->value)
            ->where('metric', $metric)
            ->whereNull('resolved_at')
            ->latest('detected_at')
            ->first();
    }

    public function resolve(): void
    {
        $this->update(['resolved_at' => now()]);
    }

    public function markNotified(): void
    {
        $this->update(['notification_sent_at' => now()]);
    }
}
