<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            // Per-monitor HTTP request timeout in seconds.
            // Null means use the application default (15s).
            // Range: 5–60 seconds.
            $table->unsignedSmallInteger('request_timeout_s')->nullable()->after('critical_threshold_ms');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('request_timeout_s');
        });
    }
};
