<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->decimal('cpu_percent', 5, 2);
            $table->decimal('ram_percent', 5, 2);
            $table->unsignedInteger('ram_used_mb')->nullable();
            $table->unsignedInteger('ram_total_mb')->nullable();
            $table->decimal('disk_percent', 5, 2);
            $table->unsignedInteger('disk_used_gb')->nullable();
            $table->unsignedInteger('disk_total_gb')->nullable();
            $table->datetime('captured_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['server_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
