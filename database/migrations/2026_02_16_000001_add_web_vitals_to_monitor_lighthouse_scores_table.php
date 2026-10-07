<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_lighthouse_scores', function (Blueprint $table) {
            $table->decimal('lcp', 8, 1)->nullable();
            $table->decimal('fcp', 8, 1)->nullable();
            $table->decimal('cls', 6, 4)->nullable();
            $table->decimal('tbt', 8, 1)->nullable();
            $table->decimal('speed_index', 8, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitor_lighthouse_scores', function (Blueprint $table) {
            $table->dropColumn(['lcp', 'fcp', 'cls', 'tbt', 'speed_index']);
        });
    }
};
