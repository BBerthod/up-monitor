<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // Rattachement Site→Server : les alertes serveur peuvent ainsi nommer
            // les sites hébergés sur le serveur en cause.
            $table->foreignId('server_id')
                ->nullable()
                ->after('team_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('server_id');
        });
    }
};
