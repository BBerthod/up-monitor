<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_lighthouse_scores', function (Blueprint $table) {
            $table->decimal('lcp_observed', 8, 1)->nullable();
            $table->decimal('benchmark_index', 8, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitor_lighthouse_scores', function (Blueprint $table) {
            $table->dropColumn(['lcp_observed', 'benchmark_index']);
        });
    }
};
