<?php

namespace App\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use Illuminate\Support\Facades\Log;

/**
 * Detects a regression in the health grade of a site and persists a HEALTH_DROP
 * insight. The health score (0-100) is written daily into kpi_snapshots by
 * DispatchKpiCollection with source=custom, metric=health_score.
 *
 * Grade scale (mirrors HealthScoreService): A>=90 | B>=80 | C>=70 | D>=60 | F<60
 * Grade rank (higher = better): A=4 B=3 C=2 D=1 F=0
 *
 * Severity: CRITICAL when dropping to F or dropping 2+ grades; WARNING otherwise.
 *
 * DEDUP BY SITE, NOT MONITOR
 * ──────────────────────────
 * DispatchInsights fans out one DetectHealthDrop job per active HTTP monitor. A site
 * with multiple monitors (e.g. HTTP + HTTPS + www redirect) would otherwise produce
 * duplicate HEALTH_DROP insights — one per monitor — all sharing the same site key
 * and health-score snapshot, triggering the same notification N times (triple-email
 * bug). The health_score snapshot is keyed by site hostname (not monitor_id), so a
 * single HEALTH_DROP per site is both correct and sufficient.
 *
 * Idempotence strategy (two layers):
 *  1. At the start of detectForMonitor() all non-acknowledged HEALTH_DROP rows for
 *     the SITE are deleted (not just for this monitor). This is safe because
 *     HealthScoreService writes exactly one snapshot per site per run.
 *  2. A guard check before Insight::create() skips creation if another job has
 *     already inserted a row for the same site since the purge (race-condition window
 *     when multiple monitors dispatch in parallel on the same second).
 */
class HealthDropDetector
{
    /** @var array<string,int> */
    private const GRADE_RANK = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, 'F' => 0];

    public function detectForMonitor(Monitor $monitor): int
    {
        $site = $this->siteFromMonitor($monitor);
        $snapshots = KpiSnapshot::where('site', $site)
            ->where('source', KpiSource::CUSTOM->value)
            ->where('metric', 'health_score')
            ->latest('captured_at')
            ->limit(2)->get();

        if ($snapshots->count() < 2) {
            return 0;
        }
        $current = $snapshots->first();
        $previous = $snapshots->last();
        $currentGrade = $this->gradeFromSnapshot($current);
        $previousGrade = $this->gradeFromSnapshot($previous);

        $siteScope = fn ($query) => $query->where('site', $site);

        $firstDetectedAt = Insight::firstDetectedAtForOpen(InsightType::HEALTH_DROP, $siteScope);

        // Purge by SITE, not by monitor_id. Multiple monitors for the same hostname
        // (e.g. http + https + ping) share a single health_score snapshot; purging
        // only by monitor_id would leave stale rows from sibling monitors and allow
        // the triple-email bug to re-emerge. See class docblock for full rationale.
        Insight::openUnacknowledgedOfType(InsightType::HEALTH_DROP, $siteScope)
            ->delete();

        if (self::GRADE_RANK[$currentGrade] >= self::GRADE_RANK[$previousGrade]) {
            return 0;
        }

        $gradeDrop = self::GRADE_RANK[$previousGrade] - self::GRADE_RANK[$currentGrade];
        $currentScore = (float) $current->value;
        $previousScore = (float) $previous->value;

        // Hysteresis on boundary jitter. Grades are hard cut-offs every 10 points, so a
        // 1-point move can straddle one (91 -> 89 reported as "A -> B") and raise a
        // WARNING that tells the operator nothing. Require a minimum score delta before
        // reporting. Multi-grade falls bypass this: crossing two boundaries at once is a
        // genuine collapse regardless of how few points separate the snapshots.
        $minScoreDelta = (float) config('monitoring.health_drop.min_score_delta', 3);

        if ($gradeDrop < 2 && ($previousScore - $currentScore) < $minScoreDelta) {
            return 0;
        }

        $severity = ($currentGrade === 'F' || $gradeDrop >= 2)
            ? InsightSeverity::CRITICAL
            : InsightSeverity::WARNING;

        // Guard against the parallel-dispatch race: if another monitor for this site
        // already inserted a HEALTH_DROP since the purge above (same-second window),
        // skip creation to avoid the duplicate-notification bug.
        $alreadyExists = Insight::withoutGlobalScopes()
            ->where('site', $site)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->exists();

        if ($alreadyExists) {
            return 0;
        }

        Insight::create([
            'team_id' => $monitor->team_id,
            'site' => $site,
            'site_id' => $monitor->site_id,
            'monitor_id' => $monitor->id,
            'type' => InsightType::HEALTH_DROP,
            'severity' => $severity,
            'title' => sprintf(
                'Health grade dropped: %s -> %s (score %s -> %s)',
                $previousGrade, $currentGrade,
                number_format($previousScore, 0),
                number_format($currentScore, 0),
            ),
            'payload' => [
                'current_grade' => $currentGrade,
                'previous_grade' => $previousGrade,
                'current_score' => $currentScore,
                'previous_score' => $previousScore,
                'current_at' => $current->captured_at->toIso8601String(),
                'previous_at' => $previous->captured_at->toIso8601String(),
            ],
            'impact_score' => max(0.0, $previousScore - $currentScore),
            'detected_at' => $firstDetectedAt ?? now(),
        ]);

        Log::info('HealthDropDetector: insight created', [
            'monitor_id' => $monitor->id,
            'site' => $site,
            'previous_grade' => $previousGrade,
            'current_grade' => $currentGrade,
            'severity' => $severity->value,
        ]);

        return 1;
    }

    private function siteFromMonitor(Monitor $monitor): string
    {
        $host = parse_url($monitor->url, PHP_URL_HOST) ?? $monitor->url;
        if (str_starts_with(strtolower($host), 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    private function gradeFromSnapshot(KpiSnapshot $snapshot): string
    {
        $meta = $snapshot->meta;
        if (is_array($meta) && isset($meta['grade'])
            && array_key_exists($meta['grade'], self::GRADE_RANK)) {
            return $meta['grade'];
        }

        return $this->gradeFromScore((float) $snapshot->value);
    }

    private function gradeFromScore(float $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }
}
