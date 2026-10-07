<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('dokploy_server_id')->nullable(); // Nullable: the primary Dokploy server may have no remote ID
            $table->string('name');
            $table->string('metrics_url')->nullable();  // From metricsConfig.server.urlCallback
            $table->text('metrics_token')->nullable();  // From metricsConfig.server.token — text: can be long
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
            $table->unique(['team_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
