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
        Schema::create('functional_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('functional_check_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->integer('duration_ms')->nullable();
            $table->json('details')->default('[]');
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['functional_check_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('functional_check_results');
    }
};
