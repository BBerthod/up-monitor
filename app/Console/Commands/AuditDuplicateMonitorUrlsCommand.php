<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MonitorType;
use App\Models\Monitor;
use Illuminate\Console\Command;

class AuditDuplicateMonitorUrlsCommand extends Command
{
    protected $signature = 'monitors:audit-duplicate-urls
                            {--team= : Restrict the audit to a single team ID (optional)}';

    protected $description = 'Report HTTP monitors that share the same normalised URL within a team — '
        .'each duplicate doubles outbound checks (PSI, HTTP) for the same content. Read-only: it never '
        .'merges or deletes anything, that decision belongs to a human.';

    public function handle(): int
    {
        $teamId = $this->option('team');

        $query = Monitor::withoutGlobalScopes()
            ->where('type', MonitorType::HTTP->value)
            ->whereNotNull('normalized_url');

        if ($teamId !== null) {
            $query->where('team_id', (int) $teamId);
        }

        $monitors = $query->orderBy('team_id')->orderBy('normalized_url')->orderBy('id')->get();

        $groups = $monitors
            ->groupBy(fn (Monitor $monitor) => $monitor->team_id.'|'.$monitor->normalized_url)
            ->filter(fn ($group) => $group->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No duplicate normalised URLs found among HTTP monitors.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($groups as $group) {
            foreach ($group as $monitor) {
                $rows[] = [
                    $monitor->team_id,
                    $monitor->id,
                    $monitor->name,
                    $monitor->url,
                    $monitor->normalized_url,
                    $monitor->created_at?->toDateString(),
                ];
            }
        }

        $this->table(
            ['Team', 'Monitor ID', 'Name', 'URL', 'Normalised URL', 'Created'],
            $rows
        );

        $duplicateMonitorCount = $groups->flatten(1)->count();

        $this->warn(
            "{$groups->count()} duplicate group(s) found, {$duplicateMonitorCount} monitor(s) involved. "
            .'This command is read-only — review each group and merge or delete the redundant monitor manually.'
        );

        return self::SUCCESS;
    }
}
