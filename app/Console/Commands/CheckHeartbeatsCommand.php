<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Heartbeat;
use App\Models\Insight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Trips the dead-man switches whose ping never arrived.
 *
 * WHY SILENCE IS THE ALARM
 * ────────────────────────
 * Every other check in Up asks a question and reads an answer. A cron cannot be
 * asked anything: when it stops running it emits no error, changes no page, and
 * moves no metric. The only observable is the ping that stops arriving.
 *
 * That is how sitemaps in this fleet went stale for months — nothing was down,
 * nothing failed, a scheduled task had simply stopped, and the first visible
 * symptom appeared much later in search results.
 *
 * WHAT IS DELIBERATELY NOT AN ALERT
 * ─────────────────────────────────
 * A heartbeat that has NEVER been pinged is unstarted, not overdue. Alerting on
 * it would fire the moment someone creates the record and before they have
 * wired the task up, which trains people to ignore the alert — the one outcome
 * a dead-man switch cannot survive.
 *
 * That grace is bounded, though. Left unbounded it turns into a permanent blind
 * spot: three sitemap heartbeats sat here for eleven days after creation, never
 * pinged and never reported, because "unstarted" has no expiry. Past
 * NEVER_PINGED_GRACE_DAYS the record stops reading as "not wired up yet" and
 * starts reading as "wired up wrong", which is exactly what a dead-man switch
 * exists to surface. The insight says so in as many words, so the fix is
 * obvious: instrument the cron, or delete the heartbeat.
 *
 * IDEMPOTENCE
 * ───────────
 * alerted_at is stamped when the switch trips and cleared by the next ping, so
 * an outage produces one insight rather than one per sweep. Recovery is handled
 * on the ping side, which is where the good news actually arrives.
 *
 * A CONTINUING OUTAGE IS NOT A FROZEN ONE
 * ────────────────────────────────────────
 * alerted_at being "stamped once" must not mean the insight itself is written
 * once. Before this fix, a sweep that found alerted_at already set did nothing
 * at all: the very first payload (lateness, severity, title) stayed on the
 * Insight row for the entire outage, so a problem alive for a month kept
 * displaying the age and WARNING severity it had the moment it first tripped —
 * understating both. alerted_at keeps meaning "first detection" (untouched on
 * every sweep after the first), but the insight itself is now refreshed on
 * each sweep: same row, same acknowledgement state, recomputed lateness and
 * severity.
 */
class CheckHeartbeatsCommand extends Command
{
    protected $signature = 'heartbeats:check';

    protected $description = 'Raise an insight for each scheduled task that stopped reporting.';

    /**
     * How long a never-pinged heartbeat is treated as "not wired up yet".
     *
     * Long enough that creating a record and instrumenting the cron the
     * following week raises nothing; short enough that a heartbeat forgotten at
     * creation cannot stay silent forever.
     */
    private const NEVER_PINGED_GRACE_DAYS = 7;

    public function handle(): int
    {
        $tripped = 0;
        $checked = 0;

        $heartbeats = Heartbeat::withoutGlobalScopes()
            ->active()
            ->where(function ($query): void {
                $query->whereNotNull('last_ping_at')
                    ->orWhere('created_at', '<', now()->subDays(self::NEVER_PINGED_GRACE_DAYS));
            })
            ->get();

        foreach ($heartbeats as $heartbeat) {
            $checked++;

            // A never-pinged heartbeat has no due date to be past, so isOverdue()
            // cannot speak for it: reaching the grace above is what makes it late.
            $overdue = $heartbeat->last_ping_at === null
                ? true
                : $heartbeat->isOverdue();

            if (! $overdue) {
                continue;
            }

            // Already reported: alerted_at stays put as the first-detection
            // stamp, but the outage is still live, so the displayed age and
            // severity must not freeze at whatever they were the moment the
            // switch first tripped.
            if ($heartbeat->alerted_at !== null) {
                $this->refresh($heartbeat);

                continue;
            }

            $this->raise($heartbeat);
            $tripped++;
        }

        $this->info("Checked {$checked} heartbeat(s), {$tripped} newly overdue.");

        if ($tripped > 0) {
            Log::warning('CheckHeartbeatsCommand: heartbeats missed', [
                'checked' => $checked,
                'tripped' => $tripped,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Raise the insight and stamp the heartbeat so it is not reported twice.
     */
    private function raise(Heartbeat $heartbeat): void
    {
        [$title, $severity, $payload, $impactScore] = $this->buildAlert($heartbeat);

        Insight::create([
            'team_id' => $heartbeat->team_id,
            'site' => $heartbeat->site?->primary_domain ?? 'portfolio',
            'site_id' => $heartbeat->site_id,
            'monitor_id' => null,
            'type' => InsightType::HEARTBEAT_MISSED->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => $payload,
            'impact_score' => $impactScore,
            'detected_at' => now(),
        ]);

        $heartbeat->forceFill(['alerted_at' => now()])->save();
    }

    /**
     * Recompute the age and severity of a continuing outage and update the
     * insight already raised for it — alerted_at (the first-detection stamp)
     * and the insight's own detected_at are deliberately left untouched.
     */
    private function refresh(Heartbeat $heartbeat): void
    {
        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::HEARTBEAT_MISSED->value)
            ->whereNull('acknowledged_at')
            ->where('payload->heartbeat_id', $heartbeat->id)
            ->first();

        if ($insight === null) {
            // Acknowledged (or otherwise gone) while still down: alerted_at
            // stays put, the next ping clears it either way, and re-raising
            // here would silently undo a decision an operator already made.
            return;
        }

        [$title, $severity, $payload, $impactScore] = $this->buildAlert($heartbeat);

        $insight->forceFill([
            'title' => $title,
            'severity' => $severity->value,
            'payload' => $payload,
            'impact_score' => $impactScore,
        ])->save();
    }

    /**
     * Compute the title/severity/payload/impact for the heartbeat's current
     * lateness. Pure with respect to persistence — shared by raise() (first
     * detection) and refresh() (recompute on a continuing outage) so the two
     * paths can never drift apart.
     *
     * @return array{0: string, 1: InsightSeverity, 2: array<string, mixed>, 3: float}
     */
    private function buildAlert(Heartbeat $heartbeat): array
    {
        $neverPinged = $heartbeat->last_ping_at === null;

        // With no ping to measure from, age since creation is the only elapsed
        // time there is — and it is the right one: it says how long the task has
        // been expected to report and has not.
        $lateMinutes = $neverPinged
            ? (int) abs(now()->diffInMinutes($heartbeat->created_at))
            : ($heartbeat->minutesSinceLastPing() ?? 0);

        $expected = $heartbeat->expected_period_minutes;

        // Severity by how far past due it is, not by absolute lateness: an hour
        // late means nothing on a daily job and everything on a 5-minute one.
        // Two full periods missed is the point where a single slow run stops
        // being a plausible explanation.
        $severity = $lateMinutes >= ($expected * 2 + $heartbeat->grace_minutes)
            ? InsightSeverity::CRITICAL
            : InsightSeverity::WARNING;

        // A task that never reported once is a wiring fault, not an outage. Say
        // that plainly: the operator looks at the cron, not at the service.
        $title = $neverPinged
            ? sprintf(
                'Scheduled task "%s" has never reported since it was created %s ago — the cron is probably not instrumented',
                $heartbeat->name,
                $this->humanise($lateMinutes),
            )
            : sprintf(
                'Scheduled task "%s" has not reported for %s',
                $heartbeat->name,
                $this->humanise($lateMinutes),
            );

        $payload = [
            'heartbeat_id' => $heartbeat->id,
            'name' => $heartbeat->name,
            'last_ping_at' => $heartbeat->last_ping_at?->toIso8601String(),
            'expected_period_minutes' => $expected,
            'grace_minutes' => $heartbeat->grace_minutes,
            'minutes_since_last_ping' => $lateMinutes,
            'never_pinged' => $neverPinged,
        ];

        // Scale with how many periods have been missed, capped: a job that
        // has been dead for a month is not fifty times more urgent than one
        // dead for a week — both need the same single fix.
        $impactScore = round(min(100, $lateMinutes / max(1, $expected) * 20), 2);

        return [$title, $severity, $payload, $impactScore];
    }

    /**
     * Render a minute count the way an operator would say it.
     */
    private function humanise(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        if ($minutes < 1440) {
            return round($minutes / 60, 1).'h';
        }

        return round($minutes / 1440, 1).'d';
    }
}
