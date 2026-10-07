<?php

namespace App\Models;

use App\Models\Traits\ScopedByTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Server extends Model
{
    use HasFactory, ScopedByTeam;

    /**
     * The attributes that should be hidden for serialization.
     * metrics_token and ingest_token_hash must never leak in API responses (public repo).
     */
    protected $hidden = ['metrics_token', 'ingest_token_hash'];

    protected $fillable = [
        'team_id',
        'dokploy_server_id',
        'name',
        'metrics_url',
        'metrics_token',
        'ingest_token_hash',
        'is_active',
        'settings',
        'vikunja_project_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Most recent metric row for this server, ordered by captured_at.
     */
    public function latestMetric(): ?ServerMetric
    {
        return $this->metrics()->latest('captured_at')->first();
    }

    /**
     * Whether this server can report metrics by either supported path:
     *   - push:  a shell agent holds an ingest token (ingest_token_hash set), or
     *   - pull:  Dokploy monitoring credentials are configured (metrics_url+token).
     * Drives the "Monitoring not configured" state on the Servers page.
     */
    public function hasMonitoringConfigured(): bool
    {
        return $this->hasIngestToken() || $this->hasDokployMonitoring();
    }

    /**
     * Push path: a shell agent has been issued an ingest token for this server.
     */
    public function hasIngestToken(): bool
    {
        return ! empty($this->ingest_token_hash);
    }

    /**
     * Pull path: Dokploy monitoring credentials are present (Cloud only).
     * Used by ServerMetricsCollector to decide whether to query the Dokploy API.
     */
    public function hasDokployMonitoring(): bool
    {
        return ! empty($this->metrics_url) && ! empty($this->metrics_token);
    }

    // -------------------------------------------------------------------------
    // Threshold helpers
    // -------------------------------------------------------------------------

    /**
     * Effective server-health thresholds for this server.
     *
     * The global config/monitoring.php values are used as defaults.  Any key
     * present under settings['thresholds'] overrides the global default, so an
     * operator can raise the disk_warning threshold for a high-churn server
     * without touching the team-wide config.
     *
     * All six threshold keys are guaranteed to be present in the returned array:
     *   disk_warning, disk_critical, ram_warning, ram_critical,
     *   cpu_warning, cpu_critical, cpu_sustained_points
     *
     * @return array<string, float|int>
     */
    public function thresholds(): array
    {
        $global = config('monitoring.server_health');
        $overrides = $this->settings['thresholds'] ?? [];

        return array_merge($global, $overrides);
    }

    // -------------------------------------------------------------------------
    // Push-agent token helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a cryptographically random 64-char plain-text ingest token.
     * Show it to the operator once; never store or log the plain value.
     */
    public static function generateIngestToken(): string
    {
        return Str::random(64);
    }

    /**
     * Derive the SHA-256 hash that is stored in ingest_token_hash.
     * Only the hash ever persists — the plain token is ephemeral.
     */
    public static function hashIngestToken(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
