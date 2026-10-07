<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table): void {
            $table->string('severity')->default('major')->after('cause');
            $table->text('notes')->nullable()->after('severity');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table): void {
            $table->dropColumn(['severity', 'notes']);
        });
    }
};
