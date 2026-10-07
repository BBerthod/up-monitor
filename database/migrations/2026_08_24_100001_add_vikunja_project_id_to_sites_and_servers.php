<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routes a monitoring problem to the right Vikunja project.
 *
 * Mapping by NAME was rejected: the Vikunja board is a human artefact and its
 * project titles get renamed ("Examplestore" → "Examplestore"), which would silently send
 * cards to the fallback Inbox with no error anywhere. An explicit id breaks
 * loudly instead — and only when the project is actually deleted.
 *
 * Nullable on purpose: a site with no mapping still works, its cards simply
 * land in the fallback project (config vikunja.fallback_project_id, Vikunja's
 * native Inbox — whose stated role is unqualified capture).
 *
 * Servers get their own mapping because SERVER_HEALTH insights are scoped to a
 * machine, not to a site: a host carrying eight sites must produce ONE card in
 * an infrastructure project, not eight cards spread across eight site projects.
 *
 * No foreign key: the referenced ids live in another system entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->integer('vikunja_project_id')->nullable()->after('dokploy_resource_type');
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->integer('vikunja_project_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('vikunja_project_id');
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('vikunja_project_id');
        });
    }
};
