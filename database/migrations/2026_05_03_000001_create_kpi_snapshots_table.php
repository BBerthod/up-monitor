<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('site');
            $table->string('source'); // KpiSource enum value
            $table->string('metric'); // e.g. "impressions_28d", "clicks_28d", "ttfb_p95_ms"
            $table->decimal('value', 20, 4);
            $table->unsignedSmallInteger('period_days')->default(28);
            $table->timestamp('captured_at');
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            // Primary query pattern: deltas per (site, source, metric) ordered by date
            $table->index(['site', 'source', 'metric', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_snapshots');
    }
};
