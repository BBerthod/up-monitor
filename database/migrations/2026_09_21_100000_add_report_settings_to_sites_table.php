<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('report_frequency')->default('none')->after('is_active');
            $table->jsonb('report_recipients')->nullable()->after('report_frequency');
            $table->timestamp('last_report_sent_at')->nullable()->after('report_recipients');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['report_frequency', 'report_recipients', 'last_report_sent_at']);
        });
    }
};
