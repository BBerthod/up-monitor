<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // Date the domain registration expires, populated by RDAP lookup.
            $table->timestamp('domain_expires_at')->nullable()->after('is_active');

            // Last time the RDAP check was performed for this domain.
            $table->timestamp('domain_expiry_checked_at')->nullable()->after('domain_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['domain_expires_at', 'domain_expiry_checked_at']);
        });
    }
};
