<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('follow_redirects')->default(true)->after('verify_tls');
            $table->string('redirect_location_keyword')->nullable()->after('keyword');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('follow_redirects');
            $table->dropColumn('redirect_location_keyword');
        });
    }
};
