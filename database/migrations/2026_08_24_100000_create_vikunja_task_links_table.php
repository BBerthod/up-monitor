<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a recurring monitoring problem to its Vikunja Kanban card.
 *
 * WHY A SEPARATE TABLE (and not a column on `insights`)
 * ─────────────────────────────────────────────────────
 * Every detector rebuilds its insights on each run:
 *
 *     Insight::where(...)->whereNull('acknowledged_at')->delete();  // then re-insert
 *
 * (SslExpiryDetector, ContentDecayService, HealthDropDetector, SitemapHealthService,
 * ZombiePageService, ServerHealthDetector … the pattern is universal.)
 *
 * Two consequences drive this schema:
 *
 *   1. `insights.id` is NOT stable. A `vikunja_task_id` column on `insights`
 *      would be wiped every night, and the next run would create a second card
 *      for a problem that already has one — one duplicate per day, forever.
 *
 *   2. `insights.detected_at` is NOT the age of the problem — it is the age of
 *      the last detector run. Persistence therefore has to be remembered here,
 *      in `first_seen_at`, which survives the delete/recreate cycle.
 *
 * THE STABLE KEY
 * ──────────────
 * `scope_key` is the functional identity of a problem, mirroring how detectors
 * themselves deduplicate — by SITE, not by monitor (a site's representative
 * monitor can change between runs, which used to orphan insights):
 *
 *     site:12|ssl_expiry      server:3|server_health      monitor:87|perf_regression
 *
 * Unique per team, so two teams watching the same hostname keep separate cards.
 *
 * LIFECYCLE
 * ─────────
 *   observed  → row exists, vikunja_task_id NULL      (seen, not yet worth a card)
 *   promoted  → vikunja_task_id set, promoted_at set  (card exists on the board)
 *   closed    → closed_at + close_reason set          (card done, or problem gone)
 *
 * A closed row is deliberately KEPT: if the same problem comes back, we know it
 * is a recurrence rather than a first sighting, and reopening is a decision we
 * can make explicitly instead of silently creating a fresh card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vikunja_task_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Functional identity of the problem — see docblock.
            $table->string('scope_key', 191);
            $table->string('insight_type', 64);

            // Context, for querying and for routing the card to a project.
            // nullOnDelete (not cascade): losing the site must not erase the
            // trace of a card that still exists on the board.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('monitor_id')->nullable()->constrained()->nullOnDelete();

            // The insight seen last. Nulled the moment a detector deletes it —
            // which is exactly why it cannot be the link itself.
            $table->foreignId('insight_id')->nullable()->constrained()->nullOnDelete();

            // Denormalised so a card can still be commented on after the
            // insight behind it is gone.
            $table->string('title', 512);
            $table->string('severity', 32);

            $table->integer('vikunja_task_id')->nullable();
            $table->integer('vikunja_project_id')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // resolved | acknowledged | done_in_vikunja | abandoned
            $table->string('close_reason', 32)->nullable();

            $table->timestamps();

            // One live link per problem per team.
            $table->unique(['team_id', 'scope_key']);

            // Promotion sweep: open links, oldest sighting first.
            $table->index(['team_id', 'closed_at', 'first_seen_at'], 'vikunja_links_promotion_idx');

            // Reconciliation sweep: promoted-and-still-open links.
            $table->index(['vikunja_task_id', 'closed_at'], 'vikunja_links_reconcile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vikunja_task_links');
    }
};
