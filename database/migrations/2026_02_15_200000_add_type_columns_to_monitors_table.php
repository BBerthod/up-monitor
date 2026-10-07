<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('type')->default('http')->after('name');
            $table->smallInteger('port')->nullable()->after('url');
            $table->string('dns_record_type')->nullable()->after('port');
            $table->string('dns_expected_value')->nullable()->after('dns_record_type');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['type', 'port', 'dns_record_type', 'dns_expected_value']);
        });
    }
};
