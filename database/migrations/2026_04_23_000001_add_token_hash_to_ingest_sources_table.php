<?php

use App\Models\IngestSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingest_sources', function (Blueprint $table): void {
            $table->string('token_hash', 64)->nullable()->unique()->after('token');
        });

        // Backfill hashes for existing tokens.
        IngestSource::withoutGlobalScopes()->chunkById(500, function ($sources): void {
            foreach ($sources as $source) {
                $source->updateQuietly(['token_hash' => hash('sha256', $source->token)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ingest_sources', function (Blueprint $table): void {
            $table->dropColumn('token_hash');
        });
    }
};
