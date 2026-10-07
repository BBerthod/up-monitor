<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->integer('response_time_ms');
            $table->integer('status_code')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at');

            $table->index(['monitor_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_checks');
    }
};
