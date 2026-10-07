<?php

namespace App\Models;

use App\Enums\ReportFrequency;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Traits\ScopedByTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Site extends Model
{
    use HasFactory;
    use ScopedByTeam;

    /**
     * Invalidate the navSites cache whenever a site is created, updated, or
     * deleted — the topbar site-switcher must reflect changes within 60 s
     * (the TTL), but we bust early on model events for immediate consistency.
     */
    protected static function booted(): void
    {
        $bust = static function (Site $site): void {
            Cache::forget(HandleInertiaRequests::navSitesCacheKey($site->team_id));
        };

        static::created($bust);
        static::updated($bust);
        static::deleted($bust);
    }

    protected $fillable = [
        'team_id',
        'server_id',
        'alias',
        'organization',
        'primary_domain',
        'domains',
        'locales',
        'primary_locale',
        'type',
        'health_endpoint',
        'gsc_property',
        'bing_url',
        'ga4_property',
        'sitemap_path',
        'sitemap_locale_pattern',
        'key_pages',
        'ad_networks',
        'adsense_account_id',
        'adsense_domain',
        'merchant_domains',
        'amazon_tag',
        'dokploy_app_id',
        'dokploy_resource_type',
        'vikunja_project_id',
        'is_active',
        'domain_expires_at',
        'domain_expiry_checked_at',
        'report_frequency',
        'report_recipients',
        'last_report_sent_at',
    ];

    protected $casts = [
        'domains' => 'array',
        'locales' => 'array',
        'key_pages' => 'array',
        'ad_networks' => 'array',
        'merchant_domains' => 'array',
        'is_active' => 'boolean',
        'domain_expires_at' => 'datetime',
        'domain_expiry_checked_at' => 'datetime',
        'report_frequency' => ReportFrequency::class,
        'report_recipients' => 'array',
        'last_report_sent_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /** Virtual "name" alias — returns alias so $site->name works in presenters. */
    public function getNameAttribute(): ?string
    {
        return $this->alias;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the canonical primary domain for this site.
     *
     * When the site is multi-locale and the domain template contains the
     * "{locale}" placeholder (e.g. "{locale}.examplestore.com"), we substitute the
     * primary_locale so health checks target the right subdomain.  Otherwise
     * we fall back to the explicit primary_domain or the first entry in domains.
     */
    public function resolvedPrimaryDomain(): string
    {
        $template = $this->domains[0] ?? null;

        if ($template && $this->primary_locale && str_contains($template, '{locale}')) {
            return str_replace('{locale}', $this->primary_locale, $template);
        }

        return $this->primary_domain ?: ($template ?? '');
    }

    /**
     * Absolute URL of this site's sitemap, or null when none is configured.
     *
     * Built from resolvedPrimaryDomain() so multi-locale sites are audited on
     * their active locale: on a "{locale}.example.com" template the sitemap of
     * the primary locale is the live index, while the bare apex may serve a
     * stale or empty document.
     *
     * sitemap_path is stored as a path ("/sitemap.xml") but tolerating an
     * absolute URL here costs one check and avoids a confusing double-prefix
     * when someone fills the field with a full URL.
     */
    public function resolvedSitemapUrl(): ?string
    {
        $path = $this->sitemap_path;

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $domain = $this->resolvedPrimaryDomain();

        if ($domain === '') {
            return null;
        }

        return 'https://'.rtrim($domain, '/').'/'.ltrim($path, '/');
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a stripped hostname to a Site within a team.
     *
     * Matching order:
     *   1. Exact match on primary_domain (the pre-resolved canonical domain).
     *   2. Match any entry in domains[] — plain or template.
     *      Template entries (containing "{locale}") are expanded for every locale
     *      in locales[] before comparison.
     *
     * The hostname is expected to already be www-stripped, consistent with
     * KpiCollector::siteNameFromUrl().  We apply the same strip defensively on
     * all candidate domain strings before comparing.
     *
     * Returns null when no site matches (insight remains unlinked, which is
     * normal for "portfolio"-level or synthetic site strings).
     */
    public static function findByHostname(string $hostname, int $teamId): ?self
    {
        $sites = self::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->get(['id', 'primary_domain', 'domains', 'locales']);

        return $sites->first(fn (self $site): bool => $site->matchesHostname($hostname));
    }

    /**
     * Find the site owning a hostname across ALL teams.
     *
     * For code paths that start from a site-keyed record (KpiSnapshot,
     * BusinessKpiIncident) and need to recover which team owns it — typically
     * to scope notifications. Returns null when no site matches; callers must
     * treat that as "unknown owner" and fail closed rather than broadcast.
     */
    public static function findOwnerByHostname(string $hostname): ?self
    {
        $sites = self::withoutGlobalScopes()
            ->get(['id', 'team_id', 'primary_domain', 'domains', 'locales']);

        return $sites->first(fn (self $site): bool => $site->matchesHostname($hostname));
    }

    /**
     * Does this site serve the given (www-stripped) hostname on any of its
     * domains, expanding {locale} templates?
     */
    private function matchesHostname(string $hostname): bool
    {
        // 1. Quick check on the already-resolved primary domain.
        if (preg_replace('/^www\./i', '', $this->primary_domain) === $hostname) {
            return true;
        }

        // 2. Walk every domain pattern.
        $locales = $this->locales ?? [];

        foreach ($this->domains as $pattern) {
            if (! str_contains($pattern, '{locale}')) {
                if (preg_replace('/^www\./i', '', $pattern) === $hostname) {
                    return true;
                }

                continue;
            }

            // Template — expand for every locale.
            foreach ($locales as $locale) {
                $resolved = preg_replace('/^www\./i', '', str_replace('{locale}', $locale, $pattern));

                if ($resolved === $hostname) {
                    return true;
                }
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForOrganization(Builder $query, string $org): Builder
    {
        return $query->where('organization', $org);
    }
}
