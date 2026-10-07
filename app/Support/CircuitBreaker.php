<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Redis-backed circuit breaker for external service calls.
 *
 * States:
 *   CLOSED  — normal operation (no key in cache, or failure count < threshold)
 *   OPEN    — too many consecutive failures; calls are blocked until cooldown expires
 *
 * Usage:
 *   if (CircuitBreaker::isOpen($key)) { return; }
 *   try {
 *       $result = externalCall();
 *       CircuitBreaker::recordSuccess($key);
 *   } catch (\Throwable $e) {
 *       CircuitBreaker::recordFailure($key);
 *       throw $e;
 *   }
 */
final class CircuitBreaker
{
    /**
     * Returns true when the circuit is OPEN (too many failures within cooldown window).
     * When open, callers should skip the downstream call entirely.
     */
    public static function isOpen(string $key): bool
    {
        return Cache::has(self::openKey($key));
    }

    /**
     * Record a failure for the given key.
     * When the consecutive failure count reaches $threshold the circuit trips OPEN
     * and remains open for $cooldownSeconds before auto-resetting.
     *
     * @param  int  $threshold  Number of consecutive failures before tripping (default: 5)
     * @param  int  $cooldownSeconds  Seconds the circuit stays open (default: 900 = 15 min)
     */
    public static function recordFailure(string $key, int $threshold = 5, int $cooldownSeconds = 900): void
    {
        $failKey = self::failKey($key);

        // Increment the rolling failure counter (TTL = cooldown window).
        $failures = Cache::increment($failKey);

        if ($failures === 1) {
            // First failure in this window — set TTL on the counter.
            Cache::put($failKey, 1, $cooldownSeconds);
        }

        if ($failures >= $threshold) {
            // Trip the circuit — store the open flag for the full cooldown duration.
            Cache::put(self::openKey($key), true, $cooldownSeconds);
        }
    }

    /**
     * Record a successful call. Resets both the failure counter and the open flag.
     */
    public static function recordSuccess(string $key): void
    {
        Cache::forget(self::failKey($key));
        Cache::forget(self::openKey($key));
    }

    /**
     * Manually reset the circuit (e.g. from an admin command).
     */
    public static function reset(string $key): void
    {
        self::recordSuccess($key);
    }

    private static function failKey(string $key): string
    {
        return "circuit:{$key}:failures";
    }

    private static function openKey(string $key): string
    {
        return "circuit:{$key}:open";
    }
}
