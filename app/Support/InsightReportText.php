<?php

namespace App\Support;

use App\Enums\InsightType;
use App\Models\Insight;

/**
 * Turns an Insight's `type` + `payload` into a plain-French, client-friendly
 * sentence for the periodic site report's "À surveiller" section.
 *
 * WHY THIS EXISTS
 * ────────────────
 * Detectors write `title` in English, terse and detector-internal (e.g.
 * "6 keywords lost rank — worst: "agence seo besançon" 30.3 → 41.0") — the
 * Up app UI and Vikunja both consume that column and must keep doing so
 * unmodified. The report is French and reads by a non-technical client, so
 * it needs its OWN copy, built from the same facts (`payload`), never from
 * `title`.
 *
 * ROBUSTNESS
 * ──────────
 * A type can have more than one payload shape (e.g. TRAFFIC_CHANGE covers
 * both a plain KPI delta and a "GA4 tag looks broken" signal; HEALTH_DROP
 * covers both a real health-score drop and an internal "orphan monitors"
 * housekeeping notice that never reaches a per-site report but still must
 * not crash this class). Every builder below checks for the payload keys IT
 * needs and returns null when they are missing — for() then falls back to a
 * generic French sentence naming the insight TYPE, never the English title.
 *
 * All sentences live in lang/fr/reports.php ('insights' key), requested with
 * an explicit 'fr' locale — same convention as the rest of this report.
 */
final class InsightReportText
{
    public static function for(Insight $insight): string
    {
        $type = $insight->type instanceof InsightType
            ? $insight->type
            : InsightType::tryFrom((string) $insight->type);

        // The `type` column is a free string in the database — should it ever
        // hold a value outside the enum (data drift, a since-removed type),
        // there is no French label to fall back to either. This is the one
        // place with nothing left to build a sentence from.
        if ($type === null) {
            return 'Point détecté.';
        }

        $payload = is_array($insight->payload) ? $insight->payload : [];

        $sentence = match ($type) {
            InsightType::STRIKING_DISTANCE => self::strikingDistance($payload),
            InsightType::TRAFFIC_CHANGE => self::trafficChange($payload),
            InsightType::POSITION_CHANGE => self::positionChange($payload),
            InsightType::CTR_CHANGE => self::ctrChange($payload),
            InsightType::HEALTH_DROP => self::healthDrop($payload),
            InsightType::PERF_REGRESSION => self::perfRegression($payload),
            InsightType::UPTIME_INCIDENT => self::uptimeIncident($insight, $payload),
            InsightType::CONTENT_DECAY => self::contentDecay($payload),
            InsightType::REVENUE_AT_RISK => self::revenueAtRisk($payload),
            InsightType::AFFILIATE_LEAK => self::affiliateLeak($payload),
            InsightType::AFFILIATE_REDIRECT_BROKEN => self::affiliateRedirectBroken($payload),
            InsightType::CMP_MISSING => self::cmpMissing($insight, $payload),
            InsightType::SITEMAP_HEALTH => self::sitemapHealth($insight, $payload),
            InsightType::KEYWORD_DROP => self::keywordDrop($payload),
            InsightType::KEYWORD_CANNIBALISATION => self::keywordCannibalisation($payload),
            InsightType::HREFLANG_BROKEN => self::hreflangBroken($payload),
            InsightType::OUTDATED_CMS => self::outdatedCms($insight, $payload),
            InsightType::HEARTBEAT_MISSED => self::heartbeatMissed($payload),
            InsightType::ZOMBIE_PAGE => self::zombiePage($payload),
            InsightType::SERVER_HEALTH => self::serverHealth($payload),
            InsightType::SSL_EXPIRY => self::sslExpiry($payload),
            InsightType::DOMAIN_EXPIRY => self::domainExpiry($payload),
            InsightType::WARMING_DISABLED => self::warmingDisabled($insight, $payload),
            InsightType::DEPLOY_ROLLBACK_FAILED => self::deployRollbackFailed($payload),
            InsightType::VIKUNJA_UNMAPPED_SITE => self::vikunjaUnmappedSite($insight, $payload),
        };

        return $sentence ?? self::fallback($type);
    }

    // ──────────────────────────────────────────────────────────
    // Per-type builders — each returns null when its expected payload
    // shape is not the one actually present, triggering the fallback.
    // ──────────────────────────────────────────────────────────

    private static function strikingDistance(array $p): ?string
    {
        if (! self::has($p, ['query', 'position', 'impressions'])) {
            return null;
        }

        return __('reports.insights.striking_distance', [
            'query' => $p['query'],
            'position' => ReportFormatter::number((float) $p['position'], 1),
            'impressions' => ReportFormatter::number((float) $p['impressions'], 0),
        ], 'fr');
    }

    private static function trafficChange(array $p): ?string
    {
        if (self::has($p, ['metric', 'previous', 'current', 'delta_pct'])) {
            return __('reports.insights.traffic_change', [
                'label' => self::kpiLabel((string) $p['metric']),
                'sign' => self::sign((float) $p['delta_pct']),
                'delta_pct' => ReportFormatter::number(abs((float) $p['delta_pct']), 0),
                'previous' => ReportFormatter::number((float) $p['previous'], 0),
                'current' => ReportFormatter::number((float) $p['current'], 0),
            ], 'fr');
        }

        // GA4-looks-broken shape (WhatChangedService::detectGa4Broken()).
        if (self::has($p, ['gsc_clicks', 'ga4_users'])) {
            return __('reports.insights.traffic_change_ga4_broken', [], 'fr');
        }

        return null;
    }

    private static function positionChange(array $p): ?string
    {
        if (($p['metric'] ?? null) === 'position_28d' && self::has($p, ['previous', 'current', 'direction'])) {
            return __('reports.insights.position_change', [
                'verb' => $p['direction'] === 'improvement' ? 'améliorée' : 'dégradée',
                'previous' => ReportFormatter::number((float) $p['previous'], 1),
                'current' => ReportFormatter::number((float) $p['current'], 1),
            ], 'fr');
        }

        // Portfolio-wide "probable Google core update" shape.
        if (self::has($p, ['sites_total', 'sites_with_position_change'])) {
            return __('reports.insights.position_change_core_update', [
                'count' => (int) $p['sites_with_position_change'],
                'total' => (int) $p['sites_total'],
            ], 'fr');
        }

        return null;
    }

    private static function ctrChange(array $p): ?string
    {
        if (! self::has($p, ['previous', 'current', 'delta_pct'])) {
            return null;
        }

        return __('reports.insights.ctr_change', [
            'sign' => self::sign((float) $p['delta_pct']),
            'delta_pct' => ReportFormatter::number(abs((float) $p['delta_pct']), 0),
            // Two decimals: CTRs are often below 1 %, where one decimal turns
            // a +19 % change into "0,1 % → 0,1 %".
            'previous' => ReportFormatter::number((float) $p['previous'], 2),
            'current' => ReportFormatter::number((float) $p['current'], 2),
        ], 'fr');
    }

    private static function healthDrop(array $p): ?string
    {
        if (! self::has($p, ['previous_grade', 'current_grade', 'previous_score', 'current_score'])) {
            return null;
        }

        return __('reports.insights.health_drop', [
            'previous_grade' => $p['previous_grade'],
            'current_grade' => $p['current_grade'],
            'previous_score' => ReportFormatter::number((float) $p['previous_score'], 0),
            'current_score' => ReportFormatter::number((float) $p['current_score'], 0),
        ], 'fr');
    }

    private static function perfRegression(array $p): ?string
    {
        if (! isset($p['regressions']) || ! is_array($p['regressions']) || $p['regressions'] === []) {
            return null;
        }

        $regressions = collect($p['regressions']);

        $performance = $regressions->first(fn ($r) => ($r['metric'] ?? null) === 'performance');
        if ($performance !== null && self::has($performance, ['from', 'to'])) {
            return __('reports.insights.perf_regression_score', [
                'previous' => ReportFormatter::number((float) $performance['from'], 0),
                'current' => ReportFormatter::number((float) $performance['to'], 0),
            ], 'fr');
        }

        $absolute = $regressions->first(fn ($r) => ($r['metric'] ?? null) === 'lcp_absolute');
        if ($absolute !== null && self::has($absolute, ['to', 'threshold'])) {
            return __('reports.insights.perf_regression_lcp_critical', [
                'to' => ReportFormatter::number((float) $absolute['to'] / 1000, 1),
                'threshold' => ReportFormatter::number((float) $absolute['threshold'] / 1000, 1),
            ], 'fr');
        }

        $first = $regressions->first();
        if (! is_array($first) || ! self::has($first, ['metric', 'from', 'to'])) {
            return null;
        }

        $metric = (string) $first['metric'];
        $decimals = $metric === 'cls' ? 3 : 0;

        return __('reports.insights.perf_regression_metric', [
            'label' => self::perfMetricLabel($metric),
            'from' => ReportFormatter::number((float) $first['from'], $decimals),
            'to' => ReportFormatter::number((float) $first['to'], $decimals),
        ], 'fr');
    }

    private static function uptimeIncident(Insight $insight, array $p): ?string
    {
        if (! self::has($p, ['cause'])) {
            return null;
        }

        if (! empty($p['functional_check_id'])) {
            return __('reports.insights.uptime_incident_functional', [
                'site' => $insight->site,
            ], 'fr');
        }

        return __('reports.insights.uptime_incident_down', [
            'site' => $insight->site,
            'cause' => self::causeLabel((string) $p['cause']),
        ], 'fr');
    }

    private static function contentDecay(array $p): ?string
    {
        if (! self::has($p, ['page', 'decline_pct', 'weeks'])) {
            return null;
        }

        return __('reports.insights.content_decay', [
            'path' => self::path((string) $p['page']),
            'decline_pct' => ReportFormatter::number((float) $p['decline_pct'], 0),
            'weeks' => (int) $p['weeks'],
        ], 'fr');
    }

    private static function revenueAtRisk(array $p): ?string
    {
        if (! self::has($p, ['page', 'clicks'])) {
            return null;
        }

        $path = self::path((string) $p['page']);
        $clicks = ReportFormatter::number((float) $p['clicks'], 0);

        if (! empty($p['status_code'])) {
            return __('reports.insights.revenue_at_risk_status', [
                'path' => $path,
                'status' => (int) $p['status_code'],
                'clicks' => $clicks,
            ], 'fr');
        }

        return __('reports.insights.revenue_at_risk_unreachable', [
            'path' => $path,
            'clicks' => $clicks,
        ], 'fr');
    }

    private static function affiliateLeak(array $p): ?string
    {
        if (! self::has($p, ['page', 'clicks'])) {
            return null;
        }

        $leakCount = (int) ($p['leak_count'] ?? 0);
        $untaggedCount = (int) ($p['untagged_count'] ?? 0);

        $parts = [];
        if ($leakCount > 0) {
            $parts[] = trans_choice('reports.insights.affiliate_leak_foreign_tags', $leakCount, ['count' => $leakCount], 'fr');
        }
        if ($untaggedCount > 0) {
            $parts[] = trans_choice('reports.insights.affiliate_leak_untagged', $untaggedCount, ['count' => $untaggedCount], 'fr');
        }

        if ($parts === []) {
            return null;
        }

        return __('reports.insights.affiliate_leak', [
            'path' => self::path((string) $p['page']),
            'anomaly' => implode(' et ', $parts),
            'clicks' => ReportFormatter::number((float) $p['clicks'], 0),
        ], 'fr');
    }

    private static function affiliateRedirectBroken(array $p): ?string
    {
        if (! self::has($p, ['tested', 'broken'])) {
            return null;
        }

        $broken = (int) $p['broken'];

        return trans_choice('reports.insights.affiliate_redirect_broken', $broken, [
            'broken' => $broken,
            'tested' => (int) $p['tested'],
        ], 'fr');
    }

    private static function cmpMissing(Insight $insight, array $p): ?string
    {
        $domain = $insight->site;

        return match ($p['reason'] ?? null) {
            'consent_mode_without_cmp' => __('reports.insights.cmp_missing_consent_mode_without_cmp', ['domain' => $domain], 'fr'),
            'no_cmp_detected' => __('reports.insights.cmp_missing_no_cmp_detected', ['domain' => $domain], 'fr'),
            'multiple_cmp_detected' => __('reports.insights.cmp_missing_multiple_cmp_detected', [
                'domain' => $domain,
                'vendors' => implode(', ', (array) ($p['vendors_found'] ?? [])),
            ], 'fr'),
            default => null,
        };
    }

    private static function sitemapHealth(Insight $insight, array $p): ?string
    {
        $domain = $insight->site;

        if (($p['reason'] ?? null) === 'empty_or_unreachable') {
            return __('reports.insights.sitemap_health_empty', ['domain' => $domain], 'fr');
        }

        if (($p['broken_ratio'] ?? 0.0) > 0.0) {
            return __('reports.insights.sitemap_health_broken', [
                'domain' => $domain,
                'pct' => ReportFormatter::number((float) $p['broken_ratio'] * 100, 0),
            ], 'fr');
        }

        if (isset($p['lastmod_age_days'])) {
            return __('reports.insights.sitemap_health_stale', [
                'domain' => $domain,
                'days' => (int) $p['lastmod_age_days'],
            ], 'fr');
        }

        if (($p['redirect_ratio'] ?? 0.0) > 0.0) {
            return __('reports.insights.sitemap_health_redirect', [
                'domain' => $domain,
                'pct' => ReportFormatter::number((float) $p['redirect_ratio'] * 100, 0),
            ], 'fr');
        }

        return null;
    }

    private static function keywordDrop(array $p): ?string
    {
        if (! isset($p['drops']) || ! is_array($p['drops']) || $p['drops'] === []) {
            return null;
        }

        $worst = $p['drops'][0];
        if (! self::has($worst, ['query', 'from', 'to'])) {
            return null;
        }

        $count = count($p['drops']);

        if ($count === 1) {
            return __('reports.insights.keyword_drop_single', [
                'query' => $worst['query'],
                'from' => ReportFormatter::number((float) $worst['from'], 1),
                'to' => ReportFormatter::number((float) $worst['to'], 1),
            ], 'fr');
        }

        return __('reports.insights.keyword_drop_multiple', [
            'count' => $count,
            'query' => $worst['query'],
            'from' => ReportFormatter::number((float) $worst['from'], 1),
            'to' => ReportFormatter::number((float) $worst['to'], 1),
        ], 'fr');
    }

    private static function keywordCannibalisation(array $p): ?string
    {
        if (! isset($p['findings']) || ! is_array($p['findings']) || $p['findings'] === []) {
            return null;
        }

        $worst = $p['findings'][0];
        if (! self::has($worst, ['query', 'pages'])) {
            return null;
        }

        return trans_choice('reports.insights.keyword_cannibalisation', count($p['findings']), [
            'count' => count($p['findings']),
            'query' => $worst['query'],
            'pages' => (int) $worst['pages'],
        ], 'fr');
    }

    private static function hreflangBroken(array $p): ?string
    {
        if (! isset($p['problems']) || ! is_array($p['problems']) || $p['problems'] === []) {
            return null;
        }

        $first = $p['problems'][0];
        if (! self::has($first, ['locale', 'detail'])) {
            return null;
        }

        $count = count($p['problems']);

        if ($count === 1) {
            return __('reports.insights.hreflang_broken_single', [
                'locale' => $first['locale'],
                'detail' => $first['detail'],
            ], 'fr');
        }

        return __('reports.insights.hreflang_broken_multiple', [
            'count' => count(array_unique(array_column($p['problems'], 'locale'))),
            'locale' => $first['locale'],
            'detail' => $first['detail'],
        ], 'fr');
    }

    private static function outdatedCms(Insight $insight, array $p): ?string
    {
        if (! self::has($p, ['cms', 'installed_version', 'latest_version'])) {
            return null;
        }

        return __('reports.insights.outdated_cms', [
            'cms' => self::cmsLabel((string) $p['cms']),
            'installed' => $p['installed_version'],
            'domain' => $insight->site,
            'latest' => $p['latest_version'],
        ], 'fr');
    }

    private static function heartbeatMissed(array $p): ?string
    {
        if (! self::has($p, ['name', 'minutes_since_last_ping'])) {
            return null;
        }

        $age = self::humanizeMinutes((int) $p['minutes_since_last_ping']);

        if (! empty($p['never_pinged'])) {
            return __('reports.insights.heartbeat_missed_never', [
                'name' => $p['name'],
                'age' => $age,
            ], 'fr');
        }

        return __('reports.insights.heartbeat_missed_late', [
            'name' => $p['name'],
            'age' => $age,
        ], 'fr');
    }

    private static function zombiePage(array $p): ?string
    {
        if (! self::has($p, ['zombie_count', 'published_count'])) {
            return null;
        }

        return __('reports.insights.zombie_page', [
            'zombies' => (int) $p['zombie_count'],
            'published' => (int) $p['published_count'],
        ], 'fr');
    }

    private static function serverHealth(array $p): ?string
    {
        $metric = $p['metric'] ?? null;

        if ($metric === 'heartbeat') {
            if (! self::has($p, ['server_name', 'last_seen_minutes'])) {
                return null;
            }

            return __('reports.insights.server_health_heartbeat', [
                'server' => $p['server_name'],
                'minutes' => (int) $p['last_seen_minutes'],
            ], 'fr');
        }

        if ($metric === 'load') {
            if (! self::has($p, ['server_name', 'load_avg_5', 'cores'])) {
                return null;
            }

            return __('reports.insights.server_health_load', [
                'server' => $p['server_name'],
                'load' => ReportFormatter::number((float) $p['load_avg_5'], 1),
                'cores' => (int) $p['cores'],
            ], 'fr');
        }

        if (in_array($metric, ['disk', 'ram', 'cpu'], true) && self::has($p, ['server_name', 'value', 'threshold'])) {
            return __('reports.insights.server_health_metric', [
                'server' => $p['server_name'],
                'label' => self::serverMetricLabel((string) $metric),
                'value' => ReportFormatter::number((float) $p['value'], 1),
                'threshold' => ReportFormatter::number((float) $p['threshold'], 1),
            ], 'fr');
        }

        return null;
    }

    private static function sslExpiry(array $p): ?string
    {
        if (! self::has($p, ['hostname', 'days_remaining'])) {
            return null;
        }

        $days = (int) $p['days_remaining'];

        if ($days <= 0) {
            return __('reports.insights.ssl_expiry_expired', ['hostname' => $p['hostname']], 'fr');
        }

        return trans_choice('reports.insights.ssl_expiry_expiring', $days, [
            'hostname' => $p['hostname'],
            'days' => $days,
        ], 'fr');
    }

    private static function domainExpiry(array $p): ?string
    {
        if (! self::has($p, ['domain', 'days_remaining'])) {
            return null;
        }

        $days = (int) $p['days_remaining'];

        if ($days <= 0) {
            return __('reports.insights.domain_expiry_expired', ['domain' => $p['domain']], 'fr');
        }

        return trans_choice('reports.insights.domain_expiry_expiring', $days, [
            'domain' => $p['domain'],
            'days' => $days,
        ], 'fr');
    }

    private static function warmingDisabled(Insight $insight, array $p): ?string
    {
        if (! self::has($p, ['consecutive_failures'])) {
            return null;
        }

        return __('reports.insights.warming_disabled', [
            'site' => $insight->site,
            'failures' => (int) $p['consecutive_failures'],
        ], 'fr');
    }

    private static function deployRollbackFailed(array $p): ?string
    {
        if (! self::has($p, ['application_name'])) {
            return null;
        }

        return __('reports.insights.deploy_rollback_failed', [
            'application' => $p['application_name'],
        ], 'fr');
    }

    private static function vikunjaUnmappedSite(Insight $insight, array $p): ?string
    {
        $domain = $p['primary_domain'] ?? $insight->site;

        if ($domain === null) {
            return null;
        }

        return __('reports.insights.vikunja_unmapped_site', ['domain' => $domain], 'fr');
    }

    // ──────────────────────────────────────────────────────────
    // Fallback — used whenever a builder above returns null.
    // ──────────────────────────────────────────────────────────

    private static function fallback(InsightType $type): string
    {
        $label = __('reports.insights.type_labels.'.$type->value, [], 'fr');

        return __('reports.insights.fallback', ['label' => $label], 'fr');
    }

    // ──────────────────────────────────────────────────────────
    // Small shared helpers
    // ──────────────────────────────────────────────────────────

    /**
     * True when every key in $keys exists in $payload and is not null.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function has(array $payload, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null) {
                return false;
            }
        }

        return true;
    }

    private static function sign(float $value): string
    {
        return $value >= 0 ? '+' : '-';
    }

    /**
     * The path only, not the full URL — a report is scanned quickly, and
     * "/blog/mon-article" reads faster than the full https://… string. Falls
     * back to the raw value when it does not parse as a URL.
     */
    private static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $url;
    }

    private static function kpiLabel(string $metric): string
    {
        return match ($metric) {
            'clicks_28d' => 'Clics',
            'impressions_28d' => 'Impressions',
            'users_28d' => 'Visiteurs',
            default => 'Trafic',
        };
    }

    private static function perfMetricLabel(string $metric): string
    {
        return match ($metric) {
            'lcp' => 'Le LCP (temps de chargement)',
            'cls' => 'Le CLS (stabilité visuelle)',
            'inp' => "L'INP (réactivité)",
            default => 'La performance ('.strtoupper($metric).')',
        };
    }

    private static function serverMetricLabel(string $metric): string
    {
        return match ($metric) {
            'disk' => 'le disque',
            'ram' => 'la RAM',
            'cpu' => 'le processeur',
            default => $metric,
        };
    }

    private static function cmsLabel(string $cms): string
    {
        return match ($cms) {
            'wordpress' => 'WordPress',
            default => ucfirst($cms),
        };
    }

    /**
     * French label for a monitor incident cause (App\Enums\IncidentCause).
     * Deliberately re-declares the small set of values here rather than
     * calling IncidentCause::label() — that method returns English, used
     * elsewhere (status pages), and this report needs French regardless.
     */
    private static function causeLabel(string $cause): string
    {
        return match ($cause) {
            'timeout' => 'délai dépassé',
            'status_code' => 'erreur HTTP',
            'keyword' => 'contenu attendu absent',
            'ssl' => 'certificat SSL invalide',
            'error' => 'erreur de connexion',
            'functional' => 'contrôle automatique échoué',
            'business_regression' => 'régression d\'un indicateur métier',
            'failed_smoke_test' => 'test post-déploiement échoué',
            default => str_replace('_', ' ', $cause),
        };
    }

    private static function humanizeMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        if ($minutes < 1440) {
            return ReportFormatter::number(round($minutes / 60, 1), 1).' h';
        }

        return ReportFormatter::number(round($minutes / 1440, 1), 1).' j';
    }
}
