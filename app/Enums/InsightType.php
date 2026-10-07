<?php

namespace App\Enums;

enum InsightType: string
{
    case STRIKING_DISTANCE = 'striking_distance';
    case TRAFFIC_CHANGE = 'traffic_change';
    case POSITION_CHANGE = 'position_change';
    case CTR_CHANGE = 'ctr_change';
    case HEALTH_DROP = 'health_drop';
    case PERF_REGRESSION = 'perf_regression';
    case UPTIME_INCIDENT = 'uptime_incident';
    case CONTENT_DECAY = 'content_decay';
    case REVENUE_AT_RISK = 'revenue_at_risk';
    case AFFILIATE_LEAK = 'affiliate_leak';
    case AFFILIATE_REDIRECT_BROKEN = 'affiliate_redirect_broken';
    case CMP_MISSING = 'cmp_missing';
    case SITEMAP_HEALTH = 'sitemap_health';
    case KEYWORD_DROP = 'keyword_drop';
    case KEYWORD_CANNIBALISATION = 'keyword_cannibalisation';
    case HREFLANG_BROKEN = 'hreflang_broken';
    case OUTDATED_CMS = 'outdated_cms';
    case HEARTBEAT_MISSED = 'heartbeat_missed';
    case ZOMBIE_PAGE = 'zombie_page';
    case SERVER_HEALTH = 'server_health';
    case SSL_EXPIRY = 'ssl_expiry';
    case DOMAIN_EXPIRY = 'domain_expiry';
    case WARMING_DISABLED = 'warming_disabled';
    case DEPLOY_ROLLBACK_FAILED = 'deploy_rollback_failed';
    case VIKUNJA_UNMAPPED_SITE = 'vikunja_unmapped_site';

    public function label(): string
    {
        return match ($this) {
            self::STRIKING_DISTANCE => 'Striking distance opportunity',
            self::TRAFFIC_CHANGE => 'Traffic change',
            self::POSITION_CHANGE => 'Position change',
            self::CTR_CHANGE => 'CTR change',
            self::HEALTH_DROP => 'Health drop',
            self::PERF_REGRESSION => 'Performance regression',
            self::UPTIME_INCIDENT => 'Uptime incident',
            self::CONTENT_DECAY => 'Content decay',
            self::REVENUE_AT_RISK => 'Revenue at risk',
            self::AFFILIATE_LEAK => 'Affiliate commission leak',
            self::AFFILIATE_REDIRECT_BROKEN => 'Affiliate redirect broken',
            self::CMP_MISSING => 'Consent management missing',
            self::SITEMAP_HEALTH => 'Sitemap health issue',
            self::KEYWORD_DROP => 'Keyword ranking drop',
            self::KEYWORD_CANNIBALISATION => 'Keyword cannibalisation',
            self::HREFLANG_BROKEN => 'Hreflang misconfiguration',
            self::OUTDATED_CMS => 'Outdated CMS version',
            self::HEARTBEAT_MISSED => 'Scheduled task stopped reporting',
            self::ZOMBIE_PAGE => 'Page with no search visibility',
            self::SERVER_HEALTH => 'Server health alert',
            self::SSL_EXPIRY => 'SSL certificate expiring',
            self::DOMAIN_EXPIRY => 'Domain name expiring',
            self::WARMING_DISABLED => 'Cache warming auto-disabled',
            self::DEPLOY_ROLLBACK_FAILED => 'Deployment rollback failed',
            self::VIKUNJA_UNMAPPED_SITE => 'Site not mapped to a Vikunja project',
        };
    }

    public function domain(): InsightDomain
    {
        return match ($this) {
            self::STRIKING_DISTANCE => InsightDomain::SEO_BUSINESS,
            self::TRAFFIC_CHANGE => InsightDomain::SEO_BUSINESS,
            self::POSITION_CHANGE => InsightDomain::SEO_BUSINESS,
            self::CTR_CHANGE => InsightDomain::SEO_BUSINESS,
            self::HEALTH_DROP => InsightDomain::SEO_BUSINESS,
            self::PERF_REGRESSION => InsightDomain::SEO_BUSINESS,
            self::UPTIME_INCIDENT => InsightDomain::AVAILABILITY,
            self::CONTENT_DECAY => InsightDomain::SEO_BUSINESS,
            self::REVENUE_AT_RISK => InsightDomain::SEO_BUSINESS,
            self::AFFILIATE_LEAK => InsightDomain::SEO_BUSINESS,
            self::AFFILIATE_REDIRECT_BROKEN => InsightDomain::SEO_BUSINESS,
            self::CMP_MISSING => InsightDomain::SEO_BUSINESS,
            self::SITEMAP_HEALTH => InsightDomain::SEO_BUSINESS,
            self::KEYWORD_DROP => InsightDomain::SEO_BUSINESS,
            self::KEYWORD_CANNIBALISATION => InsightDomain::SEO_BUSINESS,
            self::HREFLANG_BROKEN => InsightDomain::SEO_BUSINESS,
            self::OUTDATED_CMS => InsightDomain::INFRASTRUCTURE,
            self::HEARTBEAT_MISSED => InsightDomain::INFRASTRUCTURE,
            self::ZOMBIE_PAGE => InsightDomain::SEO_BUSINESS,
            self::SERVER_HEALTH => InsightDomain::INFRASTRUCTURE,
            self::SSL_EXPIRY => InsightDomain::AVAILABILITY,
            self::DOMAIN_EXPIRY => InsightDomain::AVAILABILITY,
            // A disabled warming site is an operational/performance concern, not a
            // live outage — grouped with the other infrastructure upkeep types.
            self::WARMING_DISABLED => InsightDomain::INFRASTRUCTURE,
            // A rollback failure leaves the site running a broken deploy in
            // production, same class of problem as an uptime incident.
            self::DEPLOY_ROLLBACK_FAILED => InsightDomain::AVAILABILITY,
            // Not a problem with the site itself — a gap in the alerting pipeline
            // that watches it: every future insight for this site silently falls
            // back to the shared Inbox instead of its own project. First (and so
            // far only) type to use ALERTING, since it is a meta-alert about the
            // pipeline rather than about the monitored asset.
            self::VIKUNJA_UNMAPPED_SITE => InsightDomain::ALERTING,
        };
    }

    /**
     * Business impact weight used as a tiebreaker when two insights share the
     * same severity tier and impact_score.  Higher = more directly revenue-
     * affecting.  Scale: 0 (informational opportunity) → 10 (revenue-blocking).
     *
     * Used by SeoAlertService (alert ordering) and TriageService::topItems()
     * (dashboard preview ordering).
     */
    public function businessWeight(): int
    {
        return match ($this) {
            // A broken affiliate redirect earns 10 alongside REVENUE_AT_RISK: it is
            // strictly worse than a leak (a leak still pays someone, a 404 pays nobody)
            // and it is invisible from the outside — the page renders perfectly, only
            // the outbound hop is dead. This is the class of failure that went
            // unnoticed for days in production.
            self::AFFILIATE_REDIRECT_BROKEN => 10,
            self::REVENUE_AT_RISK => 10,
            self::AFFILIATE_LEAK => 9,
            // Worse than a plain outage: the automatic rollback — the one
            // self-healing path a broken deploy had — already failed. The site
            // stays on broken code with no recovery in flight until a human acts.
            self::DEPLOY_ROLLBACK_FAILED => 9,
            // No CMP means no personalised ads on a site that runs ads: the
            // inventory still renders, it just earns a fraction of what it should.
            // A silent revenue haircut rather than an outage, so just below a leak.
            self::CMP_MISSING => 8,
            self::UPTIME_INCIDENT => 7,
            self::SSL_EXPIRY => 6,
            self::DOMAIN_EXPIRY => 6,
            // A stopped cron produces no error and no traffic change, so its
            // only symptom surfaces weeks later — in stale sitemaps, missing
            // content, unbuilt caches. Ranked alongside the expiry warnings it
            // resembles: a deadline quietly passing rather than a live outage.
            self::HEARTBEAT_MISSED => 6,
            self::SERVER_HEALTH => 5,
            // A dead sitemap does not break the site, but it silently starves
            // discovery of every new page — a slow revenue leak rather than an
            // outage, hence below infrastructure but above ranking movements.
            self::SITEMAP_HEALTH => 5,
            // An insecure CMS release is the one entry here that can end the
            // site rather than degrade it, so it sits alongside availability
            // rather than with the gradual signals below.
            self::OUTDATED_CMS => 5,
            self::HEALTH_DROP => 4,
            // Degrades performance/cache-hit ratio rather than breaking anything
            // outright — the site still serves, just slower and un-warmed. Ranked
            // with HEALTH_DROP: real, gradual, not an incident.
            self::WARMING_DISABLED => 4,
            self::TRAFFIC_CHANGE => 3,
            // A named keyword losing rank is more actionable than an aggregate
            // position shift: it points at one page and one intent, so it sits
            // just above the site-wide POSITION_CHANGE it would otherwise hide in.
            self::KEYWORD_DROP => 3,
            // Broken hreflang splits a multi-locale site against itself: the
            // wrong locale ranks, or none does. Structural, fixable, and it
            // silently caps what every other SEO effort can achieve.
            self::HREFLANG_BROKEN => 3,
            self::POSITION_CHANGE => 2,
            // Cannibalisation is a structural content problem, not an incident —
            // real, worth fixing, never urgent.
            self::KEYWORD_CANNIBALISATION => 1,
            self::PERF_REGRESSION => 2,
            self::CTR_CHANGE => 1,
            self::CONTENT_DECAY => 1,
            // Wasted effort rather than lost revenue: a page that was written,
            // published and then never seen. Worth knowing, never urgent.
            self::ZOMBIE_PAGE => 1,
            self::STRIKING_DISTANCE => 0,
            // Ranked with the infrastructure upkeep types (WARMING_DISABLED,
            // HEALTH_DROP): not itself revenue-affecting, but every alert this
            // site raises afterwards is degraded (misrouted to the Inbox) until
            // it is fixed.
            self::VIKUNJA_UNMAPPED_SITE => 4,
        };
    }
}
