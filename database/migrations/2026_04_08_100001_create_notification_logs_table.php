<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_incident_id')->nullable()->constrained('monitor_incidents')->nullOnDelete();
            $table->string('event');
            $table->string('channel_type');
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at');

            $table->index(['monitor_id', 'sent_at']);
            $table->index(['notification_channel_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
