<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\MonitorType;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Support\Facades\Log;

/**
 * Detects imminent or already-expired SSL certificates for HTTPS monitors.
 *
 * SCOPE
 * ─────
 * Only HTTP monitors whose URL starts with https:// are evaluated.  Plain HTTP
 * monitors cannot have a TLS certificate, so they are silently skipped.
 *
 * DATA SOURCE
 * ───────────
 * `ssl_expires_at` is written to `monitor_checks` by HttpChecker after each
 * successful TLS handshake.  We read the most recent non-null value so that the
 * detector keeps working even when a check temporarily fails (no new ssl_expires_at
 * in that check row) — the last known expiry is still valid.
 *
 * THRESHOLDS
 * ──────────
 * already expired OR ≤ 3 days remaining → CRITICAL
 * ≤ 14 days remaining                   → WARNING
 * > 14 days                             → silent (certificate is healthy)
 *
 * IDEMPOTENCE
 * ───────────
 * Before inserting a new insight we delete all previous unacknowledged
 * SSL_EXPIRY insights for the same monitor.  Each run therefore produces at most
 * one insight per monitor — no duplicates pile up between daily runs.
 *
 * IMPACT SCORE
 * ────────────
 * impact_score = max(0, 100 − days_remaining × 5).
 * A certificate expiring in 1 day scores 95; one expiring in 14 days scores 30.
 * Already-expired certificates are capped at 100.
 */
class SslExpiryDetector
{
    /** Certificates at or below this many days → CRITICAL. */
    private const THRESHOLD_CRITICAL = 3;

    /** Certificates at or below this many days → WARNING. */
    private const THRESHOLD_WARNING = 14;

    /**
     * Detect SSL expiry for a single monitor.
     *
     * @return int 1 if an insight was created, 0 otherwise.
     */
    public function detectForMonitor(Monitor $monitor): int
    {
        // Only HTTPS monitors carry TLS certificates.
        if ($monitor->type !== MonitorType::HTTP) {
            return 0;
        }

        if (! str_starts_with((string) $monitor->url, 'https://')) {
            return 0;
        }

        // Find the most recent check that recorded an SSL expiry date.
        $expiresAt = MonitorCheck::where('monitor_id', $monitor->id)
            ->whereNotNull('ssl_expires_at')
            ->latest('checked_at')
            ->value('ssl_expires_at');

        if ($expiresAt === null) {
            // No TLS data collected yet — stay silent.
            return 0;
        }

        $expiresAt = \Carbon\Carbon::parse($expiresAt);

        // Whole calendar days from today (start of day) until the expiry date.
        // Positive = days left in the future, 0 = expires today, negative = already expired.
        // Carbon's signed diff has bitten this repo before — compute on startOfDay to
        // get a stable integer rather than relying on truncation of a fractional diff.
        $daysRemaining = (int) now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), absolute: false);

        // Determine severity.
        if ($daysRemaining <= self::THRESHOLD_CRITICAL) {
            $severity = InsightSeverity::CRITICAL;
        } elseif ($daysRemaining <= self::THRESHOLD_WARNING) {
            $severity = InsightSeverity::WARNING;
        } else {
            // Certificate is healthy — nothing to report.
            return 0;
        }

        // Build a human-readable title.
        $title = $daysRemaining <= 0
            ? 'SSL certificate EXPIRED'
            : sprintf('SSL certificate expires in %d day%s', $daysRemaining, $daysRemaining === 1 ? '' : 's');

        // Derive hostname: strip leading www. from the URL host.
        $hostname = (string) parse_url($monitor->url, PHP_URL_HOST);
        if (str_starts_with($hostname, 'www.')) {
            $hostname = substr($hostname, 4);
        }

        // impact_score: rises as expiry approaches, caps at 100.
        $impactScore = min(100, max(0, 100 - $daysRemaining * 5));

        $firstDetectedAt = Insight::firstDetectedAtForOpen(
            InsightType::SSL_EXPIRY,
            fn ($query) => $query->where('monitor_id', $monitor->id),
        );

        // Idempotence: remove any existing unacknowledged insight for this monitor.
        Insight::openUnacknowledgedOfType(
            InsightType::SSL_EXPIRY,
            fn ($query) => $query->where('monitor_id', $monitor->id),
        )
            ->delete();

        Insight::create([
            'team_id' => $monitor->team_id,
            'site' => $hostname,
            'site_id' => $monitor->site_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::SSL_EXPIRY->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => [
                'hostname' => $hostname,
                'expires_at' => $expiresAt->toIso8601String(),
                'days_remaining' => $daysRemaining,
                'threshold' => $daysRemaining <= self::THRESHOLD_CRITICAL
                    ? self::THRESHOLD_CRITICAL
                    : self::THRESHOLD_WARNING,
            ],
            'impact_score' => $impactScore,
            'detected_at' => $firstDetectedAt ?? now(),
        ]);

        Log::info('SslExpiryDetector: insight created', [
            'monitor_id' => $monitor->id,
            'url' => $monitor->url,
            'days_remaining' => $daysRemaining,
            'severity' => $severity->value,
        ]);

        return 1;
    }
}
