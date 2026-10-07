<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            // TCP ports go up to 65535 but the column was signed smallint (max 32767),
            // so any monitor on a high port threw SQLSTATE[22003] on Postgres. Postgres
            // has no native unsigned type — unsignedSmallInteger still stores as signed
            // smallint with a check constraint, so we promote to integer which covers
            // the full port range comfortably.
            $table->integer('port')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->smallInteger('port')->nullable()->change();
        });
    }
};
