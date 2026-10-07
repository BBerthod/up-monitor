<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_lighthouse_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('performance');
            $table->unsignedTinyInteger('accessibility');
            $table->unsignedTinyInteger('best_practices');
            $table->unsignedTinyInteger('seo');
            $table->timestamp('scored_at');

            $table->index('monitor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_lighthouse_scores');
    }
};
