<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->index(['monitor_id', 'started_at'], 'monitor_incidents_monitor_id_started_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->dropIndex('monitor_incidents_monitor_id_started_at_index');
        });
    }
};
