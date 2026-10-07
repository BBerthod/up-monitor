<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insights', function (Blueprint $table): void {
            // When set, the insight is hidden from triage and notification
            // until the timestamp passes (snooze expires). after() places it
            // immediately after notified_at for logical column ordering.
            $table->timestamp('snoozed_until')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('insights', function (Blueprint $table): void {
            $table->dropColumn('snoozed_until');
        });
    }
};
