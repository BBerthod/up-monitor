<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens page_metrics.page from varchar(255) to varchar(1024).
 *
 * WHY
 * ───
 * GSC can return page URLs longer than 255 characters (long query strings,
 * deeply nested slugs). Postgres was raising
 * SQLSTATE[22001] "value too long for type character varying(255)" on every
 * such row, and — because CollectPageMetrics sits at the head of the
 * per-site detector chain (see DispatchInsights) — a single pathological URL
 * failed the whole chain for that site's run: ContentDecayService,
 * BrokenPageService, DetectAffiliateLeaks and KeywordTrendService never ran.
 *
 * Mirrors keyword_metrics.page (2026_07_28_110000_create_keyword_metrics_table),
 * which already uses string('page', 1024) for the same reason.
 *
 * INDEX
 * ─────
 * The composite index ['site', 'page', 'captured_at'] is dropped and replaced
 * by a functional index on a 255-char prefix of `page`, identical in spirit to
 * keyword_metrics_query_lookup: a btree cannot index an unbounded/very wide
 * text column efficiently, and the prefix stays fully selective in practice
 * since real page paths rarely share a 255-char prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_metrics', function (Blueprint $table): void {
            $table->dropIndex(['site', 'page', 'captured_at']);
        });

        Schema::table('page_metrics', function (Blueprint $table): void {
            $table->string('page', 1024)->change();
        });

        DB::statement(
            'CREATE INDEX page_metrics_site_page_prefix_captured ON page_metrics '
            .'(site, left(page, 255), captured_at)',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS page_metrics_site_page_prefix_captured');

        Schema::table('page_metrics', function (Blueprint $table): void {
            $table->string('page', 255)->change();
        });

        Schema::table('page_metrics', function (Blueprint $table): void {
            $table->index(['site', 'page', 'captured_at']);
        });
    }
};
