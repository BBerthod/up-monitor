<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained('ingest_sources')->cascadeOnDelete();
            $table->string('type');
            $table->string('level');
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['source_id', 'occurred_at']);
            $table->index(['source_id', 'level']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_events');
    }
};
