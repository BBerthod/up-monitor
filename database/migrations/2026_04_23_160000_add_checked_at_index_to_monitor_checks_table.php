<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            // Standalone index on checked_at for global prune/cleanup queries that
            // filter only by date (without a specific monitor_id constraint).
            // The existing (monitor_id, checked_at) composite index does not help
            // when monitor_id is absent from the WHERE clause.
            $table->index('checked_at', 'idx_monitor_checks_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->dropIndex('idx_monitor_checks_checked_at');
        });
    }
};
