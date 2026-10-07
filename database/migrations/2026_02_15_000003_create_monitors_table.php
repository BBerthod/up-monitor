<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('method')->default('GET');
            $table->integer('expected_status_code')->default(200);
            $table->string('keyword')->nullable();
            $table->integer('interval')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->integer('warning_threshold_ms')->nullable();
            $table->integer('critical_threshold_ms')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
