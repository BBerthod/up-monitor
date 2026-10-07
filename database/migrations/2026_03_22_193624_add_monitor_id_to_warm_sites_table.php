<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('warm_sites', function (Blueprint $table) {
            $table->foreignId('monitor_id')->nullable()->constrained()->nullOnDelete()->after('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warm_sites', function (Blueprint $table) {
            $table->dropForeign(['monitor_id']);
            $table->dropColumn('monitor_id');
        });
    }
};
