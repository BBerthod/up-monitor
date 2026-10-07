<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MonitorType;
use App\Models\Monitor;
use Illuminate\Console\Command;

class EnforceStrictMonitorConfigCommand extends Command
{
    protected $signature = 'monitors:enforce-strict
                            {--name= : Filter monitors by name pattern (LIKE wildcard, optional)}
                            {--keyword= : Keyword to set when the monitor has none (optional)}
                            {--status-code=200 : Expected HTTP status code to enforce}
                            {--dry-run : Show what would be changed without saving}';

    protected $description = 'Ensure HTTP monitors have a strict expected_status_code (and optional keyword) configured.';

    public function handle(): int
    {
        $namePattern = $this->option('name');
        $keyword = $this->option('keyword') ?: null;
        $expectedCode = (int) $this->option('status-code');
        $isDryRun = (bool) $this->option('dry-run');

        $query = Monitor::query()->where('type', MonitorType::HTTP);

        if ($namePattern) {
            $query->where('name', 'LIKE', '%'.addcslashes($namePattern, '\\%_').'%');
        }

        $monitors = $query->get();

        if ($monitors->isEmpty()) {
            $this->info('No HTTP monitors matched the filter.');

            return self::SUCCESS;
        }

        $candidates = $monitors->filter(function (Monitor $monitor) use ($expectedCode, $keyword): bool {
            $needsCode = $monitor->expected_status_code !== $expectedCode;
            $needsKeyword = $keyword !== null && blank($monitor->keyword);

            return $needsCode || $needsKeyword;
        });

        if ($candidates->isEmpty()) {
            $this->info("All {$monitors->count()} monitor(s) are already correctly configured.");

            return self::SUCCESS;
        }

        $rows = $candidates->map(fn (Monitor $m) => [
            $m->id,
            $m->name,
            $m->expected_status_code ?? 'null',
            $expectedCode,
            $m->keyword ?? '—',
            $keyword ?? '(unchanged)',
        ]);

        $this->table(
            ['ID', 'Name', 'Current Code', 'New Code', 'Current Keyword', 'New Keyword'],
            $rows
        );

        if ($isDryRun) {
            $this->warn("Dry-run: {$candidates->count()} monitor(s) would be updated.");

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($candidates as $monitor) {
            $changes = ['expected_status_code' => $expectedCode];

            if ($keyword !== null && blank($monitor->keyword)) {
                $changes['keyword'] = $keyword;
            }

            $monitor->update($changes);
            $updated++;
        }

        $this->info("Updated {$updated} monitor(s).");

        return self::SUCCESS;
    }
}
