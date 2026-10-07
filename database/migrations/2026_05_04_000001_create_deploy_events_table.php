<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_events', function (Blueprint $table): void {
            $table->id();
            $table->string('application_id');
            $table->string('application_name');
            $table->string('commit_sha')->nullable();
            $table->timestamp('deployed_at');
            $table->string('smoke_test_status')->default('pending'); // pending, passed, failed
            $table->json('smoke_test_results')->nullable();
            $table->boolean('rollback_triggered')->default(false);
            $table->timestamps();

            $table->index(['application_id', 'deployed_at']);
            $table->index('smoke_test_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_events');
    }
};
