<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('site')->index();
            $table->string('page');
            $table->decimal('clicks', 12, 2);
            $table->decimal('impressions', 14, 2);
            // CTR stored as percentage (0-100), matching KpiCollector's convention.
            $table->decimal('ctr', 8, 4);
            // Average position weighted by impressions across all queries for the page.
            $table->decimal('position', 6, 2);
            // When the GSC data was captured — used as the timeline axis for decay detection.
            $table->timestamp('captured_at');
            $table->timestamps();

            // Primary query pattern: fetch snapshots for a specific page ordered by time.
            $table->index(['site', 'page', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_metrics');
    }
};
