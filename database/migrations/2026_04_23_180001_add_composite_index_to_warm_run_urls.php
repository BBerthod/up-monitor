<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a composite index on (warm_run_id, cache_status) to warm_run_urls.
     *
     * Reporting queries that aggregate cache-status breakdowns per run always
     * filter on both columns:
     *   WHERE warm_run_id = ? AND cache_status = ?
     * or group by cache_status for a given run. The composite index covers both
     * cases without requiring a full-table scan for each run.
     */
    public function up(): void
    {
        Schema::table('warm_run_urls', function (Blueprint $table) {
            $table->index(['warm_run_id', 'cache_status']);
        });
    }

    public function down(): void
    {
        Schema::table('warm_run_urls', function (Blueprint $table) {
            $table->dropIndex(['warm_run_id', 'cache_status']);
        });
    }
};
