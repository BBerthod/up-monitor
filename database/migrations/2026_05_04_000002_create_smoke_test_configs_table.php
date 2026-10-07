<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smoke_test_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('application_id')->unique();
            $table->string('application_name');
            $table->json('tests');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smoke_test_configs');
    }
};
