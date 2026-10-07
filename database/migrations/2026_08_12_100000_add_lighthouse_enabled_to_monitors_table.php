<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('lighthouse_enabled')->default(true)->after('type');
        });

        // Data patch: immediately disable Lighthouse for monitors that are
        // already known to be non-auditable pages (JSON health-check
        // endpoints, affiliate redirect guards) so the fix takes effect in
        // production without a manual toggle per monitor. Mirrors the
        // defense-in-depth filter in DispatchLighthouseAudits, duplicated
        // here on purpose — migrations must not depend on app classes that
        // can change shape after this migration has run.
        DB::table('monitors')
            ->where('type', 'http')
            ->orderBy('id')
            ->chunkById(200, function ($monitors): void {
                foreach ($monitors as $monitor) {
                    if ($this->isNonAuditable($monitor->url, $monitor->expected_status_code)) {
                        DB::table('monitors')
                            ->where('id', $monitor->id)
                            ->update(['lighthouse_enabled' => false]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('lighthouse_enabled');
        });
    }

    private function isNonAuditable(string $url, ?int $expectedStatusCode): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        if (preg_match('#/(api|health|status|go)/#i', $path) === 1) {
            return true;
        }

        return $expectedStatusCode !== null && $expectedStatusCode >= 300 && $expectedStatusCode < 400;
    }
};
