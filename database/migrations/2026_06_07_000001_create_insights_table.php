<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('site');
            $table->foreignId('monitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');      // InsightType enum value
            $table->string('severity');  // InsightSeverity enum value
            $table->string('title');
            $table->json('payload')->nullable();
            $table->decimal('impact_score', 12, 2)->default(0);
            $table->timestamp('detected_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'site', 'type', 'detected_at']);
            $table->index(['team_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights');
    }
};
