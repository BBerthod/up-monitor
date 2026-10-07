<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            // Nullable so that pre-existing monitors remain valid until they are
            // associated with a Site via the sites:import command or the UI.
            $table->foreignId('site_id')
                ->nullable()
                ->after('team_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
