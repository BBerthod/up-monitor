<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dead-man switches for scheduled work that runs OUTSIDE Up.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Up already has a heartbeat for servers (an agent stops reporting → alert) and
 * one for its own scheduler. It has none for the jobs that actually keep the
 * monitored sites correct: wp-cron, a nightly rebuild, a sitemap regeneration,
 * a feed import.
 *
 * That gap is exactly how the sitemaps in this fleet went stale. Nothing was
 * down, nothing returned an error, no page changed — a scheduled task simply
 * stopped running, and the only visible symptom appeared weeks later in search
 * results. A failing cron is silent by nature: the absence of work produces no
 * signal, so it has to be inferred from the absence of a ping.
 *
 * MODEL
 * ─────
 * One row per watched task. The task pings its URL when it finishes; if no ping
 * arrives within expected_period_minutes + grace_minutes, the switch trips.
 *
 * Tokens are hashed, never stored in plain text, matching IngestSource and
 * Server::ingest_token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Optional: attributing a heartbeat to a site lets its failure show
            // up in that site's cockpit. Plenty of tasks are fleet-wide and
            // legitimately have no site.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('token_hash', 64)->unique();

            // How often the task is expected to report, and how much lateness is
            // tolerated before alerting. Grace is separate on purpose: a nightly
            // job that usually finishes at 03:00 may legitimately finish at 03:40
            // under load, and alerting on that would train everyone to ignore it.
            $table->unsignedInteger('expected_period_minutes');
            $table->unsignedInteger('grace_minutes')->default(10);

            $table->timestamp('last_ping_at')->nullable();

            // Set when the switch trips, cleared on recovery. Its presence is
            // what makes alerting idempotent — one alert per outage, not one per
            // check cycle.
            $table->timestamp('alerted_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // The sweep query: active heartbeats ordered by how overdue they are.
            $table->index(['is_active', 'last_ping_at'], 'heartbeats_sweep');
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
