<?php

namespace App\Models;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Events\InsightChanged;
use App\Models\Traits\ScopedByTeam;
use App\Services\TriageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Insight extends Model
{
    use HasFactory, ScopedByTeam;

    /**
     * Invalidate the triage:counts cache whenever an insight is created or
     * when acknowledged_at / snoozed_until changes — the badge must reflect
     * reality immediately ("acquitté quelque part = disparu partout").
     *
     * NOTE: This covers model-level changes (controllers, service calls via
     * $insight->update()). Mass updates via query builder (e.g. ServerHealthDetector
     * ::resolveAlerts()) bypass Eloquent events and need explicit cache-busting
     * at the call site.
     */
    protected static function booted(): void
    {
        // New insight created → team badge count increases.
        static::created(function (Insight $insight): void {
            Cache::forget(TriageService::cacheKey($insight->team_id));

            broadcast(new InsightChanged(
                insightId: $insight->id,
                teamId: $insight->team_id,
                action: 'created',
                severity: $insight->severity instanceof InsightSeverity
                    ? $insight->severity->value
                    : (string) $insight->severity,
                domain: ($insight->type instanceof InsightType
                    ? $insight->type->domain()
                    : InsightType::from((string) $insight->type)->domain()
                )->value,
            ));
        });

        // Acknowledge or snooze → team badge count decreases (or changes).
        static::updated(function (Insight $insight): void {
            if ($insight->wasChanged(['acknowledged_at', 'snoozed_until'])) {
                Cache::forget(TriageService::cacheKey($insight->team_id));

                $action = $insight->wasChanged('acknowledged_at') ? 'acknowledged' : 'snoozed';

                broadcast(new InsightChanged(
                    insightId: $insight->id,
                    teamId: $insight->team_id,
                    action: $action,
                    severity: $insight->severity instanceof InsightSeverity
                        ? $insight->severity->value
                        : (string) $insight->severity,
                    domain: ($insight->type instanceof InsightType
                        ? $insight->type->domain()
                        : InsightType::from((string) $insight->type)->domain()
                    )->value,
                ))->toOthers();
            }
        });
    }

    protected $fillable = [
        'team_id',
        'site',
        'site_id',
        'server_id',
        'monitor_id',
        'type',
        'source',
        'severity',
        'title',
        'payload',
        'impact_score',
        'detected_at',
        'acknowledged_at',
        'notified_at',
        'snoozed_until',
    ];

    protected $casts = [
        'type' => InsightType::class,
        'severity' => InsightSeverity::class,
        'payload' => 'array',
        'impact_score' => 'decimal:2',
        'detected_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'notified_at' => 'datetime',
        'snoozed_until' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    // Named linkedSite (not site): the legacy `site` string column shadows a
    // relation of the same name — $insight->site returns the hostname string.
    public function linkedSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }

    /**
     * Exclude insights that are currently snoozed (snoozed_until > now()).
     * An insight with a null snoozed_until, or whose snooze has already
     * expired, is considered "not snoozed" and passes through the scope.
     */
    public function scopeNotSnoozed($query)
    {
        return $query->where(fn ($q) => $q
            ->whereNull('snoozed_until')
            ->orWhere('snoozed_until', '<=', now())
        );
    }

    public function scopeOfType($query, InsightType $type)
    {
        return $query->where('type', $type->value);
    }

    public static function openUnacknowledgedOfType(InsightType $type, callable $constraints): Builder
    {
        $query = self::withoutGlobalScopes()
            ->where('type', $type->value)
            ->whereNull('acknowledged_at');

        $constraints($query);

        return $query;
    }

    public static function firstDetectedAtForOpen(InsightType $type, callable $constraints): ?\Illuminate\Support\Carbon
    {
        return self::openUnacknowledgedOfType($type, $constraints)
            ->oldest('detected_at')
            ->first(['detected_at'])
            ?->detected_at;
    }

    /**
     * @return array<string, \Illuminate\Support\Carbon|null>
     */
    public static function firstDetectedAtByPayloadKeyForOpen(
        InsightType $type,
        callable $constraints,
        string $payloadKey,
    ): array {
        $detectedAtByPayloadValue = [];

        self::openUnacknowledgedOfType($type, $constraints)
            ->oldest('detected_at')
            ->get(['payload', 'detected_at'])
            ->each(function (Insight $insight) use (&$detectedAtByPayloadValue, $payloadKey): void {
                $payloadValue = $insight->payload[$payloadKey] ?? null;

                if (! is_int($payloadValue) && ! is_string($payloadValue)) {
                    return;
                }

                $key = (string) $payloadValue;
                $detectedAtByPayloadValue[$key] ??= $insight->detected_at;
            });

        return $detectedAtByPayloadValue;
    }

    public function scopeForSite($query, string $site)
    {
        return $query->where('site', $site);
    }

    public function acknowledge(): void
    {
        $this->update(['acknowledged_at' => now()]);
    }

    public function snooze(\DateTimeInterface $until): void
    {
        $this->update(['snoozed_until' => $until]);
    }
}
