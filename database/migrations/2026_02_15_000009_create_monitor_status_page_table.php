<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_status_page', function (Blueprint $table) {
            $table->foreignId('status_page_id')->constrained('status_pages')->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);

            $table->primary(['status_page_id', 'monitor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_status_page');
    }
};
