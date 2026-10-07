<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when each page was last audited, so capped audits can rotate through
 * a site's catalogue instead of re-testing the same head pages forever.
 *
 * WHY A SEPARATE TABLE
 * ────────────────────
 * page_metrics is append-only — one row per page per collection run — so an
 * "audited_at" column there would be reset to null by the next snapshot and
 * lose the very state it is meant to carry. Audit state is per (page, audit
 * type) and must outlive individual metric rows.
 *
 * WHY PER AUDIT TYPE
 * ──────────────────
 * Broken-page probing and affiliate-link crawling cover different pages at
 * different costs and must rotate independently; sharing one cursor would let
 * one audit starve the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_audit_states', function (Blueprint $table): void {
            $table->id();
            $table->string('site');
            $table->string('page');

            // Free-form rather than an enum column: new audits are added by
            // shipping a service, not by migrating the schema.
            $table->string('audit_type', 64);

            $table->timestamp('last_audited_at')->nullable();
            $table->timestamps();

            // One state row per page per audit type. Also the lookup key used
            // when marking a page audited.
            $table->unique(['site', 'page', 'audit_type'], 'page_audit_states_unique');

            // Selection query: "oldest-audited pages for this site and audit
            // type". nullsFirst ordering rides this index too.
            $table->index(['site', 'audit_type', 'last_audited_at'], 'page_audit_states_rotation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_audit_states');
    }
};
