<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insights', function (Blueprint $table): void {
            // Idempotency guard: once an insight has been dispatched to notification
            // channels, this timestamp is set so SeoAlertService will never re-dispatch
            // it, even if the scheduler runs again before it is acknowledged.
            $table->timestamp('notified_at')->nullable()->after('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('insights', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
