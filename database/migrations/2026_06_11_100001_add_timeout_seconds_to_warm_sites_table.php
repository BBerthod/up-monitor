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
            $table->unsignedSmallInteger('timeout_seconds')->default(10)->after('custom_headers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warm_sites', function (Blueprint $table) {
            $table->dropColumn('timeout_seconds');
        });
    }
};
