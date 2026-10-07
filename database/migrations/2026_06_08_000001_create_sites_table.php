<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            // Team ownership — cascadeOnDelete so sites are removed with their team.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete()->index();

            // Human-readable identifier unique within a team, e.g. "examplestore".
            $table->string('alias');

            // Optional grouping, e.g. "Radiank" for the Radiank portfolio.
            $table->string('organization')->nullable();

            // The canonical domain used as primary health target, e.g. "fr.examplestore.com".
            // Resolved at import time from the domains/primary_locale combination.
            $table->string('primary_domain');

            // Full list of domain patterns, may contain "{locale}" placeholders,
            // e.g. ["{locale}.examplestore.com"].
            $table->json('domains');

            // Supported locale codes for multi-locale sites, e.g. ["fr","us","uk"].
            $table->json('locales')->nullable();

            // The "main" locale for primary_domain resolution and default health checks.
            $table->string('primary_locale')->nullable();

            // Application stack type, drives import/deploy-gate logic.
            $table->string('type'); // "laravel" | "wordpress" | "static" | "compose"

            // Optional path checked by the Up platform to verify the app is live.
            $table->string('health_endpoint')->nullable();

            // Google Search Console property, e.g. "sc-domain:examplestore.com".
            $table->string('gsc_property')->nullable();

            // Bing Webmaster Tools URL for this site, or null if not configured.
            $table->string('bing_url')->nullable();

            // Google Analytics 4 measurement/property ID, e.g. "p528945610".
            $table->string('ga4_property')->nullable();

            // Sitemap path used for index-coverage audits, e.g. "/sitemap.xml".
            $table->string('sitemap_path')->nullable();

            // Per-locale sitemap pattern, e.g. "/sitemap-{locale}.xml".
            $table->string('sitemap_locale_pattern')->nullable();

            // Critical pages to smoke-test on every deploy, e.g. ["/", "/top"].
            $table->json('key_pages')->nullable();

            // Ad network identifiers active on this site, e.g. ["adsense"].
            $table->json('ad_networks')->nullable();

            // Merchant/affiliate domains linked to this site, e.g. ["amazon"].
            $table->json('merchant_domains')->nullable();

            // Dokploy application identifier for deploy-gate and rollback integration.
            $table->string('dokploy_app_id')->nullable();

            // Dokploy resource type, distinguishes single-app vs docker-compose stacks.
            $table->string('dokploy_resource_type')->nullable(); // "application" | "compose"

            // Soft toggle: false = excluded from scheduled audits and dashboards.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // (team_id, alias) must be unique — prevents duplicate aliases within a team.
            $table->unique(['team_id', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
