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
        Schema::create('warm_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain');
            $table->string('mode');
            $table->string('sitemap_url')->nullable();
            $table->json('urls')->nullable();
            $table->unsignedSmallInteger('frequency_minutes')->default(60);
            $table->unsignedSmallInteger('max_urls')->default(50);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_warmed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
            $table->index('last_warmed_at');
            $table->unique(['team_id', 'domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warm_sites');
    }
};
