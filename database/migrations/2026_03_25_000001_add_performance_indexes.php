<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->index(['is_active', 'last_checked_at']);
            $table->index(['is_active', 'type']);
        });

        Schema::table('monitor_lighthouse_scores', function (Blueprint $table): void {
            $table->index(['monitor_id', 'scored_at']);
        });

        Schema::table('warm_runs', function (Blueprint $table): void {
            $table->index(['warm_site_id', 'status']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'last_checked_at']);
            $table->dropIndex(['is_active', 'type']);
        });

        Schema::table('monitor_lighthouse_scores', function (Blueprint $table): void {
            $table->dropIndex(['monitor_id', 'scored_at']);
        });

        Schema::table('warm_runs', function (Blueprint $table): void {
            $table->dropIndex(['warm_site_id', 'status']);
            $table->dropIndex(['status', 'started_at']);
        });
    }
};
