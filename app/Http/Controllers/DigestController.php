<?php

namespace App\Http\Controllers;

use App\Services\DigestService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly Digest page.
 *
 * Renders the full AI-narrated digest for the authenticated user's team.
 *
 * DESIGN NOTES
 * ─────────────────────────────────────────────────────────────────────────────
 * • DigestService::generateForTeam() may call an external AI provider (Gemini).
 *   We cache the result for 1 hour under "digest:view:{team_id}" so repeated
 *   page loads do not re-trigger the AI call.  The cache is NOT invalidated on
 *   insight changes because the digest represents a point-in-time narrative —
 *   staleness is acceptable and expected between weekly email runs.
 *
 * • If the digest computation throws (AI provider down, service failure) we
 *   return a minimal payload with 'narrative' set to null so the Vue page can
 *   render a graceful fallback.  The error is logged at warning level.
 *   IMPORTANT: error results are NOT cached — the next page load retries.
 *
 * • Carbon instances in the digest payload (period_start / period_end) are
 *   serialised to ISO 8601 strings because Inertia serialises them via JSON
 *   and Carbon's toJson() would produce the right output, but explicit
 *   toIso8601String() ensures no timezone ambiguity.
 */
class DigestController extends Controller
{
    private const CACHE_TTL = 3600; // 1 hour in seconds

    public function __construct(private readonly DigestService $digestService) {}

    public function index(): Response
    {
        $team = auth()->user()->team;

        if ($team === null) {
            return Inertia::render('Digest', [
                'digest' => null,
                'generated_at' => null,
            ]);
        }

        $cacheKey = "digest:view:{$team->id}";

        // ── Return cached digest when available ───────────────────────────────
        if (($cached = Cache::get($cacheKey)) !== null) {
            return Inertia::render('Digest', $cached);
        }

        // ── Generate fresh digest ─────────────────────────────────────────────
        // Cache::remember is intentionally NOT used: if generateForTeam throws we
        // must NOT cache the error payload — the next request retries generation.
        try {
            $raw = $this->digestService->generateForTeam($team);

            // Serialise top-level Carbon instances to strings for JSON transport.
            $digest = array_merge($raw, [
                'period_start' => $raw['period_start']->toIso8601String(),
                'period_end' => $raw['period_end']->toIso8601String(),
            ]);

            $payload = [
                'digest' => $digest,
                'generated_at' => now()->toIso8601String(),
            ];

            Cache::put($cacheKey, $payload, self::CACHE_TTL);

            return Inertia::render('Digest', $payload);
        } catch (\Throwable $e) {
            // Graceful degradation: digest page still renders, narrative is null.
            // Do NOT call Cache::put — next load will retry.
            Log::warning('DigestController: digest generation failed', [
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return Inertia::render('Digest', [
                'digest' => [
                    'team_name' => $team->name,
                    'narrative' => null,
                    'health' => [],
                    'what_changed' => [],
                    'top_opportunities' => [],
                    'server_health' => [],
                    'report' => [],
                    'alerts' => ['core_update_suspected' => false, 'ga4_broken_sites' => []],
                    'action_plan' => ['actions' => [], 'total_actionable' => 0, 'generated_at' => now()->toIso8601String()],
                ],
                'generated_at' => now()->toIso8601String(),
            ]);
        }
    }
}
