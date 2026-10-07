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
        Schema::create('warm_run_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warm_run_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->string('cache_status', 20);
            $table->unsignedSmallInteger('response_time_ms')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('warm_run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warm_run_urls');
    }
};
