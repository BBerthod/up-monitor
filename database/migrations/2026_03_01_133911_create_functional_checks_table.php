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
        Schema::create('functional_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('type');
            $table->json('rules')->default('[]');
            $table->integer('check_interval')->default(60);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_status')->default('pending');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['monitor_id', 'is_enabled']);
            $table->index('last_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('functional_checks');
    }
};
