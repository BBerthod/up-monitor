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
        Schema::table('sites', function (Blueprint $table) {
            // Amazon Associates tag of reference, e.g. "examplestore-21"; when null the
            // service auto-detects the dominant tag from crawled product links.
            $table->string('amazon_tag')->nullable()->after('merchant_domains');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('amazon_tag');
        });
    }
};
