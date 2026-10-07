<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\IncidentResolved;
use App\Models\MonitorIncident;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResolveStaleIncidentsCommand extends Command
{
    protected $signature = 'incidents:resolve-stale
                            {--hours=24 : Resolve incidents whose monitor has recovered (or is inactive) and has stayed that way for this many hours}
                            {--hard-hours=168 : Absolute age cap — force-close ANY open incident older than this, even one still owned by an enabled functional check}
                            {--force : Apply changes (default is dry-run)}';

    protected $description = 'Resolve active incidents for monitors that have been continuously UP (safety net for zombie incidents).';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $hardHours = (int) $this->option('hard-hours');
        $isDryRun = ! $this->option('force');

        if ($isDryRun) {
            $this->warn('Dry-run mode: pass --force to apply changes.');
        }

        $now = now();

        // Load every open incident. Filtering used to happen at the query
        // level (excluding functional incidents owned by an enabled check),
        // but the --hard-hours cap below needs to see ALL open incidents so
        // it can override that exclusion once an incident is old enough.
        $candidates = MonitorIncident::whereNull('resolved_at')
            ->with([
                'monitor.checks' => fn ($q) => $q->latest('checked_at')->limit(1),
                'functionalCheck',
            ])
            ->get();

        // Each entry: ['incident' => MonitorIncident, 'reason' => 'hard_cap'|'recovered'].
        $toResolve = [];

        foreach ($candidates as $incident) {
            $monitor = $incident->monitor;

            if (! $monitor) {
                continue;
            }

            $ageHours = $incident->started_at->diffInHours($now, absolute: true);

            // ── Hard cap ─────────────────────────────────────────────────
            // Past this age, close the incident no matter what: no functional
            // check ever runs again for a check that keeps failing forever,
            // so nothing else would ever resolve it. This is a deliberate
            // "give up and close it" valve, independent of monitor status.
            if ($ageHours >= $hardHours) {
                $toResolve[] = ['incident' => $incident, 'reason' => 'hard_cap'];

                continue;
            }

            // ── Soft safety net (original behavior) ─────────────────────
            // Functional incidents whose check is still ENABLED are managed
            // by FunctionalCheckService (a passing run resolves them) — skip
            // those to avoid cross-service resolution conflicts, UNLESS the
            // hard cap above already claimed them.
            $ownedByEnabledFunctionalCheck = $incident->functional_check_id !== null
                && $incident->functionalCheck?->is_enabled === true;

            if ($ownedByEnabledFunctionalCheck) {
                continue;
            }

            // The monitor must have recovered (last check UP) OR have been
            // taken out of rotation (is_active = false). A monitor paused
            // while DOWN never writes another MonitorCheck, so its last
            // check stays "down" forever — without this OR, its incident
            // could never be closed by the soft net.
            $latestCheck = $monitor->checks->first();
            $monitorRecovered = $latestCheck && $latestCheck->status->value === 'up';
            $monitorInactive = ! $monitor->is_active;

            if (! $monitorRecovered && ! $monitorInactive) {
                continue;
            }

            // The incident must have been open for at least $hours hours — short
            // incidents are not "zombies" and should not be touched by the safety net.
            if ($ageHours < $hours) {
                continue;
            }

            $toResolve[] = ['incident' => $incident, 'reason' => 'recovered'];
        }

        if ($toResolve === []) {
            $this->info('No stale incidents found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Monitor', 'Cause', 'Started At', 'Open Hours', 'Reason'],
            array_map(fn (array $row) => [
                $row['incident']->id,
                $row['incident']->monitor->name,
                $row['incident']->cause->value,
                $row['incident']->started_at->format('Y-m-d H:i'),
                (int) $row['incident']->started_at->diffInHours($now, absolute: true),
                $row['reason'] === 'hard_cap' ? 'forced (age cap)' : 'recovered',
            ], $toResolve)
        );

        if ($isDryRun) {
            $this->warn(sprintf('Dry-run: %d incident(s) would be resolved. Pass --force to apply.', count($toResolve)));

            return self::SUCCESS;
        }

        foreach ($toResolve as $row) {
            /** @var MonitorIncident $incident */
            $incident = $row['incident'];
            $incident->resolve();

            // Without this, the safety net closes the incident but leaves its
            // mirrored UPTIME_INCIDENT insight unacknowledged forever — ResolveUptimeInsight
            // only fires off this event, and nothing else acknowledges the insight.
            event(new IncidentResolved($incident));

            if ($row['reason'] === 'hard_cap') {
                Log::warning('incidents:resolve-stale — incident force-closed by hard age cap', [
                    'incident_id' => $incident->id,
                    'monitor_id' => $incident->monitor_id,
                    'monitor_name' => $incident->monitor->name,
                    'cause' => $incident->cause->value,
                    'functional_check_id' => $incident->functional_check_id,
                    'started_at' => $incident->started_at->toIso8601String(),
                    'resolved_at' => $incident->resolved_at->toIso8601String(),
                    'hard_hours' => $hardHours,
                ]);
            } else {
                Log::info('incidents:resolve-stale — zombie incident auto-resolved', [
                    'incident_id' => $incident->id,
                    'monitor_id' => $incident->monitor_id,
                    'monitor_name' => $incident->monitor->name,
                    'cause' => $incident->cause->value,
                    'started_at' => $incident->started_at->toIso8601String(),
                    'resolved_at' => $incident->resolved_at->toIso8601String(),
                ]);
            }
        }

        $this->info(sprintf('Resolved %d stale incident(s).', count($toResolve)));

        return self::SUCCESS;
    }
}
