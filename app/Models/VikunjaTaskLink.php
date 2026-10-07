<?php

namespace App\Models;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Traits\ScopedByTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The bridge between a recurring monitoring problem and its Vikunja card.
 *
 * This model exists because insights are disposable and cards are not: detectors
 * delete and re-insert their insights on every run, so the link — and the memory
 * of how long a problem has been around — has to live somewhere that survives
 * that churn. See the table migration for the full rationale.
 *
 * @property string $scope_key
 */
class VikunjaTaskLink extends Model
{
    use HasFactory, ScopedByTeam;

    protected $fillable = [
        'team_id',
        'scope_key',
        'insight_type',
        'site_id',
        'server_id',
        'monitor_id',
        'insight_id',
        'title',
        'severity',
        'vikunja_task_id',
        'vikunja_project_id',
        'first_seen_at',
        'last_seen_at',
        'promoted_at',
        'closed_at',
        'close_reason',
    ];

    protected $casts = [
        'insight_type' => InsightType::class,
        'severity' => InsightSeverity::class,
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'promoted_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Insight types deduplicated per MONITOR rather than per site.
     *
     * Must mirror the dispatch strategy in DispatchInsights: a TLS certificate is
     * bound to one host:port and a Lighthouse score to one URL, so two monitors on
     * the same hostname are genuinely two problems and deserve two cards. Everything
     * else is deduplicated per site (a site's representative monitor can change
     * between runs — keying on it would orphan the link).
     */
    private const PER_MONITOR_TYPES = [
        InsightType::SSL_EXPIRY,
        InsightType::PERF_REGRESSION,
    ];

    /** Insight types scoped to a machine — one card per server, not per hosted site. */
    private const PER_SERVER_TYPES = [
        InsightType::SERVER_HEALTH,
    ];

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    /**
     * Build the stable functional key for an insight.
     *
     * The key must survive the nightly delete/recreate cycle, so it is composed
     * only of things that outlive an individual insight row: the subject of the
     * problem and its type.
     *
     * Falls back to the legacy `site` hostname string when no site_id is set, so
     * insights created before sites were modelled still get a stable key rather
     * than a new one on every run.
     */
    public static function scopeKeyFor(Insight $insight): string
    {
        $type = $insight->type instanceof InsightType
            ? $insight->type
            : InsightType::from((string) $insight->type);

        $subject = match (true) {
            in_array($type, self::PER_SERVER_TYPES, true) && $insight->server_id !== null => 'server:'.$insight->server_id,

            in_array($type, self::PER_MONITOR_TYPES, true) && $insight->monitor_id !== null => 'monitor:'.$insight->monitor_id,

            $insight->site_id !== null => 'site:'.$insight->site_id,
            $insight->server_id !== null => 'server:'.$insight->server_id,
            $insight->monitor_id !== null => 'monitor:'.$insight->monitor_id,

            // Legacy rows: hostname string, lowercased so casing drift does not
            // fork the key.
            default => 'host:'.strtolower(trim((string) $insight->site)),
        };

        return $subject.'|'.$type->value;
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function insight(): BelongsTo
    {
        return $this->belongsTo(Insight::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** Links whose problem is still live (not closed on either side). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /** Open links that already have a card on the board. */
    public function scopePromoted(Builder $query): Builder
    {
        return $query->whereNull('closed_at')->whereNotNull('vikunja_task_id');
    }

    /** Open links seen at least once but not yet worth a card. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('closed_at')->whereNull('vikunja_task_id');
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    public function isPromoted(): bool
    {
        return $this->vikunja_task_id !== null;
    }

    /** How long this problem has been continuously observed, in hours. */
    public function ageInHours(): float
    {
        // max(0): a clock skew between app servers must not produce a negative
        // age that would silently disqualify a link from ever being promoted.
        return max(0.0, $this->first_seen_at->diffInMinutes(now()) / 60);
    }
}
