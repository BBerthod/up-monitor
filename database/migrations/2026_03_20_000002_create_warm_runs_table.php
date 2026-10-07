<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('warm_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warm_site_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('urls_total')->default(0);
            $table->unsignedSmallInteger('urls_hit')->default(0);
            $table->unsignedSmallInteger('urls_miss')->default(0);
            $table->unsignedSmallInteger('urls_error')->default(0);
            $table->unsignedSmallInteger('avg_response_ms')->default(0);
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['warm_site_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warm_runs');
    }
};
