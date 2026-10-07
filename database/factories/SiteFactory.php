<?php

namespace Database\Factories;

use App\Enums\ReportFrequency;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $domain = fake()->domainName();

        return [
            'team_id' => Team::factory(),
            'alias' => fake()->unique()->word(),
            'organization' => 'Radiank',
            'primary_domain' => $domain,
            'domains' => [$domain],
            'locales' => null,
            'primary_locale' => null,
            'type' => 'wordpress',
            'health_endpoint' => null,
            'gsc_property' => null,
            'bing_url' => null,
            'ga4_property' => null,
            'sitemap_path' => null,
            'sitemap_locale_pattern' => null,
            'key_pages' => null,
            'ad_networks' => null,
            'merchant_domains' => null,
            'dokploy_app_id' => null,
            'dokploy_resource_type' => null,
            'is_active' => true,
            'report_frequency' => ReportFrequency::NONE,
            'report_recipients' => null,
            'last_report_sent_at' => null,
        ];
    }

    /** Factory state for a site with weekly reports enabled. */
    public function weeklyReport(array $recipients = []): static
    {
        return $this->state(fn (array $attributes) => [
            'report_frequency' => ReportFrequency::WEEKLY,
            'report_recipients' => $recipients ?: null,
        ]);
    }

    /** Factory state for a site with monthly reports enabled. */
    public function monthlyReport(array $recipients = []): static
    {
        return $this->state(fn (array $attributes) => [
            'report_frequency' => ReportFrequency::MONTHLY,
            'report_recipients' => $recipients ?: null,
        ]);
    }

    /** Factory state for a multi-locale site with locale-templated domains. */
    public function multiLocale(string $primaryLocale = 'fr', array $locales = ['fr', 'us', 'uk']): static
    {
        $base = fake()->domainName();
        // Strip the TLD and use a pattern that mirrors real multi-locale setups.
        $namePart = explode('.', $base)[0];
        $template = '{locale}.'.$namePart.'.com';

        return $this->state(fn (array $attributes) => [
            'domains' => [$template],
            'locales' => $locales,
            'primary_locale' => $primaryLocale,
            'primary_domain' => str_replace('{locale}', $primaryLocale, $template),
        ]);
    }

    /** Factory state for a site with a full SEO toolchain configured. */
    public function withSeo(): static
    {
        return $this->state(fn (array $attributes) => [
            'gsc_property' => 'sc-domain:'.$attributes['primary_domain'],
            'bing_url' => 'https://www.bing.com/webmasters/?siteUrl=https://'.$attributes['primary_domain'],
            'ga4_property' => 'p'.fake()->numerify('########'),
            'sitemap_path' => '/sitemap.xml',
        ]);
    }
}
