<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('badge_secret', 32)->nullable()->after('is_active');
        });

        // Backfill existing monitors with a random secret.
        DB::table('monitors')->whereNull('badge_secret')->chunkById(200, function ($monitors): void {
            foreach ($monitors as $monitor) {
                DB::table('monitors')
                    ->where('id', $monitor->id)
                    ->update(['badge_secret' => Str::random(32)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('badge_secret');
        });
    }
};
