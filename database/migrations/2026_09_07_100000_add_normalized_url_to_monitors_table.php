<?php

use App\Support\UrlNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a computed `normalized_url` column and, when the current data
     * allows it, a unique index on (team_id, normalized_url) scoped to HTTP
     * monitors — the only type PSI audits against (see LighthouseService).
     *
     * The `url` column itself is left untouched: it is used verbatim for
     * outbound requests (HttpChecker, LighthouseService) and for ping/port/dns
     * monitors it isn't a URL at all (hostname, IP, domain name).
     *
     * Ping/Port/DNS monitors are intentionally excluded from the constraint:
     * they legitimately reuse the same host with a different port or DNS
     * record type, which would otherwise collide.
     *
     * If duplicates already exist among HTTP monitors (a real risk on this
     * dataset — see the `monitors:audit-duplicate-urls` command), the unique
     * index is skipped with a warning instead of failing the migration.
     *
     * WARNING — this docblock used to say "resolve the duplicates, then re-run
     * php artisan migrate". That advice is wrong. Once this migration has run it
     * is recorded in the `migrations` table, so `migrate` answers "Nothing to
     * migrate" forever and the skipped index is never retried. That is exactly
     * what happened in production on 2026-09-08: 6 duplicate groups existed at
     * deploy time, the index was skipped, and the table stayed unconstrained.
     * Adding it after the fact is the job of
     * 2026_09_08_090000_add_normalized_url_unique_index_to_monitors_table, which
     * throws rather than skips so that it stays pending until the data allows it.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('normalized_url')->nullable()->after('url');
        });

        $this->backfillNormalizedUrls();

        if ($this->hasDuplicateHttpNormalizedUrls()) {
            Log::warning(
                'monitors.normalized_url: duplicate (team_id, normalized_url) pairs found among HTTP '
                .'monitors — skipping the unique index. Run "php artisan monitors:audit-duplicate-urls", '
                .'resolve the duplicates, then re-run "php artisan migrate" to add it.'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX monitors_team_id_normalized_url_http_unique '
            ."ON monitors (team_id, normalized_url) WHERE type = 'http'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS monitors_team_id_normalized_url_http_unique');

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('normalized_url');
        });
    }

    private function backfillNormalizedUrls(): void
    {
        DB::table('monitors')->select(['id', 'url'])->orderBy('id')->chunkById(200, function ($monitors): void {
            foreach ($monitors as $monitor) {
                DB::table('monitors')
                    ->where('id', $monitor->id)
                    ->update(['normalized_url' => UrlNormalizer::normalize($monitor->url)]);
            }
        });
    }

    private function hasDuplicateHttpNormalizedUrls(): bool
    {
        return DB::table('monitors')
            ->select('team_id', 'normalized_url')
            ->where('type', 'http')
            ->groupBy('team_id', 'normalized_url')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->get()
            ->isNotEmpty();
    }
};
