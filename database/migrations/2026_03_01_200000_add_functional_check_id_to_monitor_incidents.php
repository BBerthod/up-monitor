<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->foreignId('functional_check_id')
                ->nullable()
                ->constrained('functional_checks')
                ->nullOnDelete()
                ->after('monitor_id');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\FunctionalCheck::class);
            $table->dropColumn('functional_check_id');
        });
    }
};
