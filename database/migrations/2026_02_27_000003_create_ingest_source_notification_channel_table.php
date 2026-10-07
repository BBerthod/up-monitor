<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_source_notification_channel', function (Blueprint $table): void {
            $table->foreignId('ingest_source_id')->constrained('ingest_sources')->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->primary(['ingest_source_id', 'notification_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_source_notification_channel');
    }
};
