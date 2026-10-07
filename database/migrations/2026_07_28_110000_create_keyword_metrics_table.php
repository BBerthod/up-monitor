<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-keyword GSC history: one row per (site, query, page) per collection run.
 *
 * WHY THIS EXISTS
 * ───────────────
 * KpiCollector::collectGscQueries() already fetches up to 1000 query×page rows
 * from Search Console on every run — and throws them away, keeping only the
 * aggregates it derives. Up therefore knows a site's average position but can
 * never answer "which keyword did we lose, and when", which is the question
 * every ranking investigation actually starts from.
 *
 * The data is already being paid for; this table simply stops discarding it.
 *
 * SHAPE
 * ─────
 * Append-only time series, same contract as page_metrics: rows are never
 * updated, and a pruning job trims the tail. Keeping (query, page) rather than
 * query alone is what makes cannibalisation detectable — two pages ranking for
 * one query are two rows that can be compared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('site');

            // Search queries are user-typed and can be long; TEXT avoids
            // silently truncating a legitimate long-tail query. Indexed via a
            // prefix below rather than in full, since btree cannot index
            // unbounded text.
            $table->text('query');
            $table->string('page', 1024);

            $table->decimal('clicks', 12, 2);
            $table->decimal('impressions', 14, 2);
            $table->decimal('ctr', 8, 4);
            $table->decimal('position', 6, 2);

            $table->timestamp('captured_at');
            $table->timestamps();

            // Primary access pattern: "this site's history, most recent first",
            // used by both the trend detector and the pruning job.
            $table->index(['site', 'captured_at'], 'keyword_metrics_site_captured');
        });

        // Query-scoped lookup ("how has THIS keyword moved"). A functional index
        // on the first 255 characters keeps the btree bounded while still being
        // selective — real queries are far shorter than that.
        DB::statement(
            'CREATE INDEX keyword_metrics_query_lookup ON keyword_metrics '
            .'(site, left(query, 255), captured_at)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_metrics');
    }
};
