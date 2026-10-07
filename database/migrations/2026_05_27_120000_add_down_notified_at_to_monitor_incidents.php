<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table): void {
            $table->timestamp('down_notified_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table): void {
            $table->dropColumn('down_notified_at');
        });
    }
};
