<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_metrics', function (Blueprint $table): void {
            // System load averages (1/5/15 min). Nullable: older agents that
            // only report CPU/RAM/disk percentages omit them.
            $table->decimal('load_avg_1', 6, 2)->nullable()->after('disk_total_gb');
            $table->decimal('load_avg_5', 6, 2)->nullable()->after('load_avg_1');
            $table->decimal('load_avg_15', 6, 2)->nullable()->after('load_avg_5');
        });
    }

    public function down(): void
    {
        Schema::table('server_metrics', function (Blueprint $table): void {
            $table->dropColumn(['load_avg_1', 'load_avg_5', 'load_avg_15']);
        });
    }
};
