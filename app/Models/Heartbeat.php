<?php

namespace App\Models;

use App\Models\Traits\ScopedByTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A dead-man switch for scheduled work that runs outside Up.
 *
 * The task pings its URL on completion; if no ping arrives within the expected
 * period plus its grace, the switch trips and an insight is raised.
 *
 * WHY INVERT THE USUAL DIRECTION
 * ──────────────────────────────
 * Every other check in Up asks a question and reads the answer. A cron cannot
 * be asked anything — when it stops running it produces no error, no traffic,
 * and no change. The only observable is the ping that stops arriving, so
 * silence has to be the alarm rather than the all-clear.
 */
class Heartbeat extends Model
{
    use HasFactory;
    use ScopedByTeam;

    protected $fillable = [
        'team_id',
        'site_id',
        'name',
        'token_hash',
        'expected_period_minutes',
        'grace_minutes',
        'last_ping_at',
        'alerted_at',
        'is_active',
    ];

    protected $casts = [
        'expected_period_minutes' => 'integer',
        'grace_minutes' => 'integer',
        'last_ping_at' => 'datetime',
        'alerted_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * The plain token, available only on the request that generated it.
     *
     * Never persisted: only its hash is stored, matching IngestSource and
     * Server::ingest_token. A lost token is regenerated, not recovered.
     */
    public ?string $plainToken = null;

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

    // -------------------------------------------------------------------------
    // Tokens
    // -------------------------------------------------------------------------

    /**
     * Hash a plain-text token for storage and lookup.
     *
     * SHA-256 rather than a password hash on purpose: this value is looked up
     * on every ping, so the hash must be deterministic and cheap. The token is
     * high-entropy random rather than user-chosen, so there is nothing to
     * brute-force that a slow hash would protect.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Generate a fresh token, storing only its hash.
     *
     * Returns the plain value, which the caller must show once — it cannot be
     * retrieved afterwards.
     */
    public function regenerateToken(): string
    {
        $plain = Str::random(48);

        $this->token_hash = self::hashToken($plain);
        $this->plainToken = $plain;

        return $plain;
    }

    /**
     * Find an active heartbeat by its plain token.
     */
    public static function findByToken(string $plainToken): ?self
    {
        return static::withoutGlobalScopes()
            ->where('token_hash', self::hashToken($plainToken))
            ->where('is_active', true)
            ->first();
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /**
     * Deadline by which the next ping must arrive.
     *
     * Null when no ping has ever been received: a heartbeat that has never
     * reported is not overdue, it is unstarted. Alerting on it would fire the
     * moment someone creates it and before they have wired the task up.
     */
    public function dueAt(): ?\Carbon\CarbonInterface
    {
        if ($this->last_ping_at === null) {
            return null;
        }

        return $this->last_ping_at
            ->copy()
            ->addMinutes($this->expected_period_minutes + $this->grace_minutes);
    }

    /**
     * Has this heartbeat missed its deadline?
     */
    public function isOverdue(): bool
    {
        $dueAt = $this->dueAt();

        return $dueAt !== null && $dueAt->isPast();
    }

    /**
     * Minutes elapsed since the last ping, or null if there has never been one.
     */
    public function minutesSinceLastPing(): ?int
    {
        if ($this->last_ping_at === null) {
            return null;
        }

        // diffInMinutes() is signed; abs() guards against a clock skew making a
        // recent ping read as a large negative age and silently passing.
        return (int) abs(now()->diffInMinutes($this->last_ping_at));
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
