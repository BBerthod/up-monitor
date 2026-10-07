<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_kpi_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('site');
            $table->string('source');   // KpiSource enum value
            $table->string('metric');
            $table->string('severity'); // KpiRegressionSeverity enum value
            $table->decimal('baseline_value', 20, 4);
            $table->decimal('current_value', 20, 4);
            $table->decimal('delta_pct', 8, 4);
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();

            // Deduplication: find open incident for a given (site, source, metric)
            $table->index(['site', 'source', 'metric', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_kpi_incidents');
    }
};
