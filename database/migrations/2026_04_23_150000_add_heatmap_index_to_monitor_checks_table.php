<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Functional index to speed up heatmap queries that group/filter by DATE(checked_at).
        // Laravel's Schema builder does not support functional indexes natively, so we use a
        // raw statement. This index is only created on PostgreSQL (the production driver).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_monitor_checks_date ON monitor_checks (monitor_id, (checked_at::date))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_monitor_checks_date');
        }
    }
};
