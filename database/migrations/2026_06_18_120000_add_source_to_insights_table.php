<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insights', function (Blueprint $table): void {
            // Identifies the origin of the insight: 'internal' for insights
            // produced by built-in detectors (HealthScore, StrikingDistance…),
            // 'monitor' for insights ingested from the /monitor skill or any
            // external source. Defaults to 'internal' so existing rows are
            // unaffected without a backfill.
            $table->string('source')->default('internal')->after('type');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('insights', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
