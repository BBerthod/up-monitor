<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class ServerMetric extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'cpu_percent',
        'ram_percent',
        'ram_used_mb',
        'ram_total_mb',
        'disk_percent',
        'disk_used_gb',
        'disk_total_gb',
        'load_avg_1',
        'load_avg_5',
        'load_avg_15',
        'captured_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu_percent' => 'decimal:2',
            'ram_percent' => 'decimal:2',
            'disk_percent' => 'decimal:2',
            'ram_used_mb' => 'integer',
            'ram_total_mb' => 'integer',
            'disk_used_gb' => 'integer',
            'disk_total_gb' => 'integer',
            'load_avg_1' => 'decimal:2',
            'load_avg_5' => 'decimal:2',
            'load_avg_15' => 'decimal:2',
            'captured_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Metrics for the given server over the last N hours, ordered by captured_at asc.
     * Returns a Collection suited for sparkline rendering.
     */
    public static function recentFor(Server $server, int $hours = 24): Collection
    {
        return static::where('server_id', $server->id)
            ->where('captured_at', '>=', now()->subHours($hours))
            ->orderBy('captured_at')
            ->get();
    }
}
