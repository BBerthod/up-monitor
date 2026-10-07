<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table): void {
            // Replace the single-column team_id index with a composite (team_id, is_active)
            // to match the query pattern used when listing active status pages per team.
            $table->dropIndex(['team_id']);
            $table->index(['team_id', 'is_active'], 'idx_status_pages_team_active');
        });
    }

    public function down(): void
    {
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->dropIndex('idx_status_pages_team_active');
            $table->index('team_id');
        });
    }
};
