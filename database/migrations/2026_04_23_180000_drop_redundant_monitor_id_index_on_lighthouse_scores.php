<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the simple monitor_id index that is made redundant by the composite
     * (monitor_id, scored_at) index added in a later migration.
     * The composite index already satisfies any query that would have used the
     * simple one, so keeping both wastes write overhead and storage.
     */
    public function up(): void
    {
        Schema::table('monitor_lighthouse_scores', function (Blueprint $table) {
            $table->dropIndex(['monitor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('monitor_lighthouse_scores', function (Blueprint $table) {
            $table->index('monitor_id');
        });
    }
};
