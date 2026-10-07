<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingest_events', function (Blueprint $table): void {
            $table->dropIndex(['source_id', 'level']);
            $table->index(['source_id', 'occurred_at', 'level']);
        });
    }

    public function down(): void
    {
        Schema::table('ingest_events', function (Blueprint $table): void {
            $table->dropIndex(['source_id', 'occurred_at', 'level']);
            $table->index(['source_id', 'level']);
        });
    }
};
