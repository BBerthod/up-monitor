<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catch-up for the unique index that 2026_09_07_100000 was supposed to create.
 *
 * WHY THIS EXISTS
 * ────────────────
 * The earlier migration skipped the index — with a Log::warning — when duplicate
 * (team_id, normalized_url) pairs already existed, so that it would not fail on a
 * dirty dataset. It then told the operator to "resolve the duplicates, then re-run
 * php artisan migrate". That instruction is wrong: the migration had already been
 * recorded in the `migrations` table, so re-running only ever answers "Nothing to
 * migrate". Production sat with the column populated and no index, silently.
 *
 * Verified on 2026-09-08: 6 duplicate groups (12 monitors) existed at deploy time,
 * the index was skipped, and `php artisan migrate` reported nothing to do.
 *
 * WHY IT THROWS INSTEAD OF SKIPPING
 * ──────────────────────────────────
 * Skipping is what produced the silent gap in the first place. Throwing leaves this
 * migration PENDING, so it is retried on the next deploy and keeps being retried
 * until the data actually allows the index. docker/entrypoint/start.sh runs
 * `migrate --force` inside an `if` and starts the container regardless, so a failure
 * here is loud in the deploy log without ever costing an outage.
 */
return new class extends Migration
{
    private const INDEX = 'monitors_team_id_normalized_url_http_unique';

    public function up(): void
    {
        // A fresh install runs 2026_09_07_100000 against empty data, where it finds
        // no duplicates and creates the index itself. Nothing left to do here.
        if ($this->indexExists()) {
            return;
        }

        $duplicates = $this->duplicateGroupCount();

        if ($duplicates > 0) {
            throw new RuntimeException(sprintf(
                'Cannot create %s: %d duplicate (team_id, normalized_url) group(s) remain among HTTP '
                .'monitors. Run "php artisan monitors:audit-duplicate-urls", merge or delete the redundant '
                .'monitors, then deploy again — this migration stays pending until it can succeed.',
                self::INDEX,
                $duplicates,
            ));
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.' ON monitors (team_id, normalized_url) '
            ."WHERE type = 'http'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }

    private function indexExists(): bool
    {
        return DB::table('pg_indexes')
            ->where('tablename', 'monitors')
            ->where('indexname', self::INDEX)
            ->exists();
    }

    private function duplicateGroupCount(): int
    {
        return DB::table('monitors')
            ->select('team_id', 'normalized_url')
            ->where('type', 'http')
            ->groupBy('team_id', 'normalized_url')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }
};
