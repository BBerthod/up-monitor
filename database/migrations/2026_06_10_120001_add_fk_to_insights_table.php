<?php

use App\Enums\InsightType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add FK columns ────────────────────────────────────────────────
        Schema::table('insights', function (Blueprint $table): void {
            // NB: ->index() must NOT be chained after ->constrained() — it lands
            // on the ForeignKeyDefinition and overrides the constraint name.
            $table->foreignId('site_id')
                ->nullable()
                ->after('site')
                ->constrained('sites')
                ->nullOnDelete();

            $table->foreignId('server_id')
                ->nullable()
                ->after('site_id')
                ->constrained('servers')
                ->nullOnDelete();

            $table->index('site_id');
            $table->index('server_id');
        });

        // ── 2. Backfill server_id for SERVER_HEALTH insights ─────────────────
        //
        // Strategy A (preferred): read payload->server_id if present — it was
        //   stored by buildPayload() from the beginning and is the exact server
        //   PK, so it is always correct.
        // Strategy B (fallback): match the `site` string against servers.name
        //   scoped by team_id, for any old row where the payload was missing the
        //   key (should not happen in practice but covers edge cases).
        //
        // We use raw DB queries to avoid booting Eloquent models / global scopes
        // during the migration.
        $serverHealthType = InsightType::SERVER_HEALTH->value;

        // Strategy A — payload->server_id is available.
        DB::statement("
            UPDATE insights
            SET    server_id = (payload->>'server_id')::bigint
            WHERE  type      = ?
              AND  payload  IS NOT NULL
              AND  (payload->>'server_id') IS NOT NULL
              AND  server_id IS NULL
              AND  EXISTS (
                  SELECT 1 FROM servers
                  WHERE  servers.id      = (payload->>'server_id')::bigint
                    AND  servers.team_id = insights.team_id
              )
        ", [$serverHealthType]);

        // Strategy B — fallback: match site string against servers.name.
        DB::statement('
            UPDATE insights
            SET    server_id = s.id
            FROM   servers s
            WHERE  insights.type      = ?
              AND  insights.server_id IS NULL
              AND  insights.team_id   = s.team_id
              AND  insights.site      = s.name
        ', [$serverHealthType]);

        // ── 3. Backfill site_id for SEO insights ─────────────────────────────
        //
        // The `site` column stores a stripped hostname (www. removed) derived
        // from the monitor URL via KpiCollector::siteNameFromUrl().
        //
        // We match against two things on the Site model:
        //   a) primary_domain — the already-resolved canonical domain stored at
        //      import time (e.g. "fr.examplestore.com").
        //   b) All entries in the domains JSON array, with any {locale} template
        //      instantiated for every locale listed in the locales JSON array.
        //      For non-template entries the value is used as-is.
        //
        // Pass 1: exact match on primary_domain (fastest, covers the majority).
        DB::statement('
            UPDATE insights
            SET    site_id = sites.id
            FROM   sites
            WHERE  insights.site_id  IS NULL
              AND  insights.type     != ?
              AND  insights.team_id   = sites.team_id
              AND  insights.site      = sites.primary_domain
        ', [$serverHealthType]);

        // Pass 2: match against resolved domain templates.
        //
        // For each site that still has unresolved insights after pass 1:
        //   - iterate every domain pattern in domains[]
        //   - if the pattern contains {locale}, expand it for each locale in locales[]
        //   - if the expanded (or plain) domain matches insights.site, assign site_id
        //
        // We do this in PHP rather than pure SQL because PostgreSQL JSON array
        // iteration with template substitution would require a PL/pgSQL function.
        // The insights table is small (hundreds of rows at most), so the N+1 is
        // acceptable here.
        $unresolved = DB::select('
            SELECT DISTINCT i.team_id, i.site
            FROM   insights i
            WHERE  i.site_id IS NULL
              AND  i.type    != ?
        ', [$serverHealthType]);

        foreach ($unresolved as $row) {
            $teamId = $row->team_id;
            $hostname = $row->site;

            $matchingId = self::resolveSiteId((string) $hostname, (int) $teamId);

            if ($matchingId !== null) {
                DB::table('insights')
                    ->where('team_id', $teamId)
                    ->where('site', $hostname)
                    ->whereNull('site_id')
                    ->whereNot('type', $serverHealthType)
                    ->update(['site_id' => $matchingId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('insights', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('server_id');
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Resolve a stripped hostname to a Site ID within a team by expanding
     * domain templates against the site's configured locales.
     *
     * Mirrors the logic of Site::findByHostname() but operates on raw DB rows
     * so this migration has no dependency on the current state of the model.
     */
    private static function resolveSiteId(string $hostname, int $teamId): ?int
    {
        $sites = DB::select('
            SELECT id, primary_domain, domains, locales
            FROM   sites
            WHERE  team_id = ?
        ', [$teamId]);

        foreach ($sites as $site) {
            // Fast path: primary_domain (pass 1 SQL already handles exact matches,
            // but we check again here to guard against www-prefixed stored values).
            $primary = preg_replace('/^www\./i', '', $site->primary_domain ?? '');
            if ($primary !== '' && $primary === $hostname) {
                return (int) $site->id;
            }

            $domains = json_decode($site->domains, true) ?: [];
            $locales = json_decode($site->locales ?? '[]', true) ?: [];

            foreach ($domains as $pattern) {
                if (! str_contains($pattern, '{locale}')) {
                    // Plain domain — strip www. and compare directly.
                    $plain = preg_replace('/^www\./i', '', $pattern);

                    if ($plain === $hostname) {
                        return (int) $site->id;
                    }

                    continue;
                }

                // Template — expand for every locale.
                foreach ($locales as $locale) {
                    $resolved = str_replace('{locale}', $locale, $pattern);
                    $resolved = preg_replace('/^www\./i', '', $resolved);

                    if ($resolved === $hostname) {
                        return (int) $site->id;
                    }
                }
            }
        }

        return null;
    }
};
