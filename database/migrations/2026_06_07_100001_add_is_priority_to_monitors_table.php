<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            // Marks a monitor as a "money/priority" site.
            // Priority monitors get escalated SEO alert treatment: WARNING severity
            // is treated as CRITICAL when dispatching alerts via SeoAlertService.
            $table->boolean('is_priority')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('is_priority');
        });
    }
};
