<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            // SHA-256 hex digest of the plain-text push-agent token.
            // The plain token is shown once via servers:token and never stored.
            $table->string('ingest_token_hash', 64)->nullable()->unique()->after('metrics_token');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('ingest_token_hash');
        });
    }
};
