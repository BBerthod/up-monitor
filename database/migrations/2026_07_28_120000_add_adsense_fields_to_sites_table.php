<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the AdSense identifiers needed to pull real revenue per site.
 *
 * Until now Site::ad_networks recorded THAT a site runs ads, but nothing
 * recorded WHERE to read its earnings, so no euro figure ever entered the
 * system and every "revenue at risk" signal was a proxy built from clicks.
 *
 * Two fields because the AdSense Management API needs both:
 *  - the account (publisher) the reports are pulled from;
 *  - the domain to filter on, since one account serves many sites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // "pub-XXXXXXXXXXXXXXXX". Nullable: most sites in a fleet share one
            // publisher account, which is read from config when this is unset.
            $table->string('adsense_account_id')->nullable()->after('ad_networks');

            // The DOMAIN_NAME dimension value used to filter reports for this
            // site. Nullable: falls back to the resolved primary domain, which
            // is correct for every ordinary setup.
            $table->string('adsense_domain')->nullable()->after('adsense_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['adsense_account_id', 'adsense_domain']);
        });
    }
};
