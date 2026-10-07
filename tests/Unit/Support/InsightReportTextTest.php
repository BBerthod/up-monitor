<?php

namespace Tests\Unit\Support;

use App\Enums\InsightType;
use App\Models\Insight;
use App\Support\InsightReportText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One test per InsightType that has a template, using a payload fixture
 * shaped exactly like the real detector that creates it (see the file/line
 * cited in each test) — plus the fallback path for a missing key and for a
 * type whose payload doesn't match any known shape.
 *
 * RefreshDatabase is only needed because Insight::factory()->make() still
 * resolves a Team factory default; no assertion here touches the database.
 */
class InsightReportTextTest extends TestCase
{
    use RefreshDatabase;

    private function insight(InsightType $type, array $payload, ?string $site = null): Insight
    {
        return Insight::factory()->ofType($type)->make([
            'site' => $site ?? 'example.com',
            'payload' => $payload,
            'title' => 'irrelevant english title',
        ]);
    }

    // ── StrikingDistanceService::detectForMonitor() ────────────────────────

    public function test_striking_distance(): void
    {
        $insight = $this->insight(InsightType::STRIKING_DISTANCE, [
            'query' => 'agence seo jura',
            'position' => 12.3,
            'impressions' => 480,
        ]);

        $this->assertSame(
            '« agence seo jura » se classe en position 12,3 avec 480 impressions par mois — un gain de trafic rapide est possible.',
            InsightReportText::for($insight),
        );
    }

    // ── WhatChangedService::persistChangeInsight() ──────────────────────────

    public function test_traffic_change_metric(): void
    {
        $insight = $this->insight(InsightType::TRAFFIC_CHANGE, [
            'metric' => 'clicks_28d',
            'source' => 'gsc',
            'previous' => 949,
            'current' => 706,
            'delta_pct' => -25.6,
            'direction' => 'decline',
        ], 'garden-site-a.fr');

        $this->assertSame(
            'Clics : -26 % sur la période (949 → 706).',
            InsightReportText::for($insight),
        );
    }

    // ── WhatChangedService::detectGa4Broken() ───────────────────────────────

    public function test_traffic_change_ga4_broken(): void
    {
        $insight = $this->insight(InsightType::TRAFFIC_CHANGE, [
            'gsc_clicks' => 120.0,
            'ga4_users' => 0,
        ]);

        $this->assertSame(
            'Google Analytics ne détecte aucun visiteur alors que Search Console enregistre du trafic — le tag de suivi est probablement cassé.',
            InsightReportText::for($insight),
        );
    }

    // ── WhatChangedService::persistChangeInsight() (position_28d) ──────────

    public function test_position_change_metric(): void
    {
        $insight = $this->insight(InsightType::POSITION_CHANGE, [
            'metric' => 'position_28d',
            'previous' => 72.7,
            'current' => 83.0,
            'delta_pct' => 14.2,
            'direction' => 'decline',
        ], 'garden-site-a.fr');

        $this->assertSame(
            'Position moyenne dégradée : 72,7 → 83,0.',
            InsightReportText::for($insight),
        );
    }

    // ── WhatChangedService::detectCoreUpdate() ──────────────────────────────

    public function test_position_change_core_update(): void
    {
        $insight = $this->insight(InsightType::POSITION_CHANGE, [
            'sites_total' => 10,
            'sites_with_position_change' => 6,
            'fraction' => 0.6,
        ], 'portfolio');

        $this->assertSame(
            "Mouvement de positionnement détecté sur 6 sites du portefeuille sur 10 — probable mise à jour de l'algorithme Google.",
            InsightReportText::for($insight),
        );
    }

    // ── WhatChangedService::persistChangeInsight() (ctr_28d) ────────────────

    public function test_ctr_change(): void
    {
        $insight = $this->insight(InsightType::CTR_CHANGE, [
            'metric' => 'ctr_28d',
            'previous' => 2.1,
            'current' => 2.4,
            'delta_pct' => 14.3,
            'direction' => 'improvement',
        ]);

        $this->assertSame(
            'Taux de clic (CTR) : +14 % sur la période (2,10 % → 2,40 %).',
            InsightReportText::for($insight),
        );
    }

    public function test_ctr_change_keeps_small_rates_distinguishable(): void
    {
        $insight = $this->insight(InsightType::CTR_CHANGE, [
            'metric' => 'ctr_28d',
            'previous' => 0.11,
            'current' => 0.13,
            'delta_pct' => 19.0,
            'direction' => 'improvement',
        ]);

        $this->assertSame(
            'Taux de clic (CTR) : +19 % sur la période (0,11 % → 0,13 %).',
            InsightReportText::for($insight),
        );
    }

    // ── HealthDropDetector::detectForMonitor() ──────────────────────────────

    public function test_health_drop(): void
    {
        $insight = $this->insight(InsightType::HEALTH_DROP, [
            'current_grade' => 'D',
            'previous_grade' => 'B',
            'current_score' => 42,
            'previous_score' => 71,
        ]);

        $this->assertSame(
            'La note de santé du site est passée de B à D (score 71 → 42).',
            InsightReportText::for($insight),
        );
    }

    // ── PerfRegressionDetector::detect() — performance score regression ────

    public function test_perf_regression_score(): void
    {
        $insight = $this->insight(InsightType::PERF_REGRESSION, [
            'regressions' => [
                ['metric' => 'performance', 'from' => 82, 'to' => 55],
            ],
        ]);

        $this->assertSame(
            'Le score de performance a chuté de 82 à 55 points.',
            InsightReportText::for($insight),
        );
    }

    // ── PerfRegressionDetector::detect() — absolute LCP breach ──────────────

    public function test_perf_regression_lcp_critical(): void
    {
        $insight = $this->insight(InsightType::PERF_REGRESSION, [
            'regressions' => [
                ['metric' => 'lcp_absolute', 'from' => 3000, 'to' => 16000, 'threshold' => 4000],
            ],
        ]);

        $this->assertSame(
            'Le temps de chargement (LCP) est critique : 16,0 s (seuil : 4,0 s).',
            InsightReportText::for($insight),
        );
    }

    // ── PerfRegressionDetector::detect() — CLS worsened (prod title example) ─

    public function test_perf_regression_metric_cls(): void
    {
        $insight = $this->insight(InsightType::PERF_REGRESSION, [
            'regressions' => [
                ['metric' => 'cls', 'from' => 0.013, 'to' => 0.387],
            ],
        ]);

        $this->assertSame(
            'Le CLS (stabilité visuelle) s\'est dégradé (0,013 → 0,387).',
            InsightReportText::for($insight),
        );
    }

    // ── CreateUptimeInsight::handle() — real monitor DOWN ───────────────────

    public function test_uptime_incident_down(): void
    {
        $insight = $this->insight(InsightType::UPTIME_INCIDENT, [
            'incident_id' => 1,
            'monitor_id' => 1,
            'cause' => 'status_code',
            'functional_check_id' => null,
        ], 'example.com');

        $this->assertSame(
            'Le site example.com a subi une interruption (erreur HTTP).',
            InsightReportText::for($insight),
        );
    }

    // ── CreateUptimeInsight::handle() — FunctionalCheck failure ─────────────

    public function test_uptime_incident_functional(): void
    {
        $insight = $this->insight(InsightType::UPTIME_INCIDENT, [
            'incident_id' => 1,
            'monitor_id' => 1,
            'cause' => 'functional',
            'functional_check_id' => 7,
        ], 'example.com');

        $this->assertSame(
            'Une vérification automatique a échoué sur example.com.',
            InsightReportText::for($insight),
        );
    }

    // ── ContentDecayService::detectForMonitor() (prod title example) ───────

    public function test_content_decay(): void
    {
        $insight = $this->insight(InsightType::CONTENT_DECAY, [
            'page' => 'https://example.com/blog/mon-article',
            'clicks_before' => 100,
            'clicks_now' => 0,
            'impressions_before' => 500,
            'impressions_now' => 200,
            'decline_pct' => 100,
            'weeks' => 12,
        ]);

        $this->assertSame(
            'La page /blog/mon-article a perdu 100 % de ses clics en 12 semaines.',
            InsightReportText::for($insight),
        );
    }

    // ── BrokenPageService::detectForMonitor() — HTTP status ─────────────────

    public function test_revenue_at_risk_status(): void
    {
        $insight = $this->insight(InsightType::REVENUE_AT_RISK, [
            'page' => 'https://example.com/produit/best-seller',
            'status_code' => 404,
            'clicks' => 320,
            'impressions' => 4000,
            'error' => null,
        ]);

        $this->assertSame(
            'La page /produit/best-seller renvoie une erreur HTTP 404 — 320 clics mensuels menacés.',
            InsightReportText::for($insight),
        );
    }

    // ── BrokenPageService::detectForMonitor() — connection failure ──────────

    public function test_revenue_at_risk_unreachable(): void
    {
        $insight = $this->insight(InsightType::REVENUE_AT_RISK, [
            'page' => 'https://example.com/produit/best-seller',
            'status_code' => null,
            'clicks' => 320,
            'impressions' => 4000,
            'error' => 'unreachable',
        ]);

        $this->assertSame(
            'La page /produit/best-seller est injoignable — 320 clics mensuels menacés.',
            InsightReportText::for($insight),
        );
    }

    // ── AffiliateAuditService::raiseInsight() ───────────────────────────────

    public function test_affiliate_leak(): void
    {
        $insight = $this->insight(InsightType::AFFILIATE_LEAK, [
            'page' => 'https://example.com/comparatif',
            'reference_tag' => 'radiank-21',
            'foreign_tags' => ['other-21'],
            'untagged_count' => 2,
            'leak_count' => 3,
            'clicks' => 150,
            'impressions' => 900,
            'sample_urls' => [],
        ]);

        $this->assertSame(
            'Des liens affiliés sur /comparatif portent un tag différent du vôtre (3 liens) et aucun tag (2 liens) — 150 clics mensuels concernés.',
            InsightReportText::for($insight),
        );
    }

    // ── BrokenRedirectService::raise() ───────────────────────────────────────

    public function test_affiliate_redirect_broken(): void
    {
        $insight = $this->insight(InsightType::AFFILIATE_REDIRECT_BROKEN, [
            'tested' => 12,
            'broken' => 5,
            'failure_ratio' => 0.417,
            'samples' => [],
        ]);

        $this->assertSame(
            '5 redirections affiliées sur 12 testées ne mènent plus au marchand.',
            InsightReportText::for($insight),
        );
    }

    // ── CmpDetector — Consent Mode v2 present, no certified CMP (prod title) ─

    public function test_cmp_missing_consent_mode_without_cmp(): void
    {
        $insight = $this->insight(InsightType::CMP_MISSING, [
            'url' => 'https://example.com',
            'reason' => 'consent_mode_without_cmp',
            'ad_networks' => ['adsense'],
            'vendors_found' => [],
        ], 'example.com');

        $this->assertSame(
            "Le mode de consentement Google est actif sur example.com, mais aucune plateforme de consentement certifiée n'est détectée — Google en exige une pour servir des publicités dans l'UE.",
            InsightReportText::for($insight),
        );
    }

    public function test_cmp_missing_no_cmp_detected(): void
    {
        $insight = $this->insight(InsightType::CMP_MISSING, [
            'url' => 'https://example.com',
            'reason' => 'no_cmp_detected',
            'ad_networks' => ['adsense'],
            'vendors_found' => [],
        ], 'example.com');

        $this->assertSame(
            'Aucune plateforme de gestion du consentement détectée sur example.com — les publicités servies ne peuvent être que non personnalisées.',
            InsightReportText::for($insight),
        );
    }

    public function test_cmp_missing_multiple_cmp_detected(): void
    {
        $insight = $this->insight(InsightType::CMP_MISSING, [
            'url' => 'https://example.com',
            'reason' => 'multiple_cmp_detected',
            'ad_networks' => ['adsense'],
            'vendors_found' => ['OneTrust', 'Cookiebot'],
        ], 'example.com');

        $this->assertSame(
            'Plusieurs plateformes de consentement coexistent sur example.com (OneTrust, Cookiebot) et se neutralisent mutuellement.',
            InsightReportText::for($insight),
        );
    }

    // ── SitemapHealthService — empty/unreachable ────────────────────────────

    public function test_sitemap_health_empty(): void
    {
        $insight = $this->insight(InsightType::SITEMAP_HEALTH, [
            'sitemap' => 'https://example.com/sitemap.xml',
            'reason' => 'empty_or_unreachable',
        ], 'example.com');

        $this->assertSame(
            'Le sitemap de example.com est injoignable ou vide.',
            InsightReportText::for($insight),
        );
    }

    // ── SitemapHealthService — staleness (prod title example) ──────────────

    public function test_sitemap_health_stale(): void
    {
        $insight = $this->insight(InsightType::SITEMAP_HEALTH, [
            'sitemap' => 'https://example.com/sitemap.xml',
            'total_urls' => 500,
            'lastmod_age_days' => 390,
        ], 'example.com');

        $this->assertSame(
            "Le sitemap de example.com n'a pas été mis à jour depuis 390 jours.",
            InsightReportText::for($insight),
        );
    }

    public function test_sitemap_health_broken(): void
    {
        $insight = $this->insight(InsightType::SITEMAP_HEALTH, [
            'sitemap' => 'https://example.com/sitemap.xml',
            'total_urls' => 500,
            'broken_ratio' => 0.12,
        ], 'example.com');

        $this->assertSame(
            '12 % des URL du sitemap de example.com renvoient une erreur.',
            InsightReportText::for($insight),
        );
    }

    // ── KeywordTrendService::detectDropsForMonitor() — single (prod example) ─

    public function test_keyword_drop_single(): void
    {
        $insight = $this->insight(InsightType::KEYWORD_DROP, [
            'window_days' => 28,
            'comparison_period_days' => 28,
            'drops' => [
                ['query' => 'agence seo besançon', 'from' => 30.3, 'to' => 41.0, 'click_decline_pct' => 80, 'impressions' => 200],
            ],
        ]);

        $this->assertSame(
            'Le mot-clé « agence seo besançon » est passé de la position 30,3 à 41,0.',
            InsightReportText::for($insight),
        );
    }

    // ── KeywordTrendService::detectDropsForMonitor() — multiple ─────────────

    public function test_keyword_drop_multiple(): void
    {
        // 6 drops — matches the real-world title example given in the brief
        // ("6 keywords lost rank — worst: ..."), worst first (see $worst =
        // $drops[0] in KeywordTrendService::detectDropsForMonitor()).
        $filler = ['query' => 'other keyword', 'from' => 8.0, 'to' => 9.0, 'click_decline_pct' => 10, 'impressions' => 50];

        $insight = $this->insight(InsightType::KEYWORD_DROP, [
            'window_days' => 28,
            'comparison_period_days' => 28,
            'drops' => [
                ['query' => 'agence seo besançon', 'from' => 30.3, 'to' => 41.0, 'click_decline_pct' => 80, 'impressions' => 200],
                $filler, $filler, $filler, $filler, $filler,
            ],
        ]);

        $this->assertSame(
            '6 mots-clés ont perdu des positions — le plus touché : « agence seo besançon » (30,3 → 41,0).',
            InsightReportText::for($insight),
        );
    }

    // ── KeywordTrendService::detectCannibalisation() ─────────────────────────

    public function test_keyword_cannibalisation(): void
    {
        // 10 findings — matches the real-world title example given in the
        // brief ("10 keywords served by multiple pages — worst: ...").
        $filler = ['query' => 'other query', 'pages' => 2, 'impressions' => 50, 'best_position' => 8.0];

        $insight = $this->insight(InsightType::KEYWORD_CANNIBALISATION, [
            'captured_at' => '2026-09-16',
            'findings' => [
                ['query' => 'consultant seo jura', 'pages' => 7, 'impressions' => 300, 'best_position' => 4.2],
                $filler, $filler, $filler, $filler, $filler, $filler, $filler, $filler, $filler,
            ],
        ]);

        $this->assertSame(
            '10 recherches Google font concourir plusieurs de vos pages entre elles — la plus concernée : « consultant seo jura » (7 pages).',
            InsightReportText::for($insight),
        );
    }

    public function test_keyword_cannibalisation_singular(): void
    {
        $insight = $this->insight(InsightType::KEYWORD_CANNIBALISATION, [
            'captured_at' => '2026-09-16',
            'findings' => [
                ['query' => 'consultant seo jura', 'pages' => 7, 'impressions' => 300, 'best_position' => 4.2],
            ],
        ]);

        $this->assertSame(
            '1 recherche Google fait concourir plusieurs de vos pages entre elles — la plus concernée : « consultant seo jura » (7 pages).',
            InsightReportText::for($insight),
        );
    }

    // ── HreflangAuditService::raise() — single problem ──────────────────────

    public function test_hreflang_broken_single(): void
    {
        $insight = $this->insight(InsightType::HREFLANG_BROKEN, [
            'locales_audited' => ['fr', 'en'],
            'problems' => [
                ['type' => 'missing_return_tag', 'locale' => 'en', 'detail' => 'no return link to fr'],
            ],
        ]);

        $this->assertSame(
            'Problème hreflang sur en : no return link to fr.',
            InsightReportText::for($insight),
        );
    }

    // ── WordPressVersionService::checkSite() (prod title example) ──────────

    public function test_outdated_cms(): void
    {
        $insight = $this->insight(InsightType::OUTDATED_CMS, [
            'cms' => 'wordpress',
            'installed_version' => '6.9.4',
            'latest_version' => '7.1.1',
            'status' => 'outdated',
            'url' => 'https://example.com',
        ], 'example.com');

        $this->assertSame(
            'WordPress 6.9.4 sur example.com est dépassé (dernière version disponible : 7.1.1).',
            InsightReportText::for($insight),
        );
    }

    // ── CheckHeartbeatsCommand::buildAlert() — overdue ──────────────────────

    public function test_heartbeat_missed_late(): void
    {
        $insight = $this->insight(InsightType::HEARTBEAT_MISSED, [
            'heartbeat_id' => 1,
            'name' => 'daily-crux-collect',
            'last_ping_at' => '2026-09-10T00:00:00Z',
            'expected_period_minutes' => 1440,
            'grace_minutes' => 60,
            'minutes_since_last_ping' => 2880,
            'never_pinged' => false,
        ]);

        $this->assertSame(
            'La tâche planifiée « daily-crux-collect » n\'a pas transmis de signal depuis 2,0 j.',
            InsightReportText::for($insight),
        );
    }

    // ── ZombiePageService::detectForSite() (prod title example) ─────────────

    public function test_zombie_page(): void
    {
        $insight = $this->insight(InsightType::ZOMBIE_PAGE, [
            'zombie_count' => 28,
            'published_count' => 113,
            'ratio' => 0.248,
            'window_days' => 90,
            'sample' => [],
        ], 'radiank.com');

        $this->assertSame(
            "28 pages publiées sur 113 n'apparaissent pas dans les résultats Google.",
            InsightReportText::for($insight),
        );
    }

    // ── CheckServerHeartbeatCommand — server silent ──────────────────────────

    public function test_server_health_heartbeat(): void
    {
        $insight = $this->insight(InsightType::SERVER_HEALTH, [
            'metric' => 'heartbeat',
            'server_id' => 1,
            'server_name' => 'prod-server',
            'last_seen_minutes' => 15,
            'sites' => [],
        ]);

        $this->assertSame(
            "Le serveur prod-server ne transmet plus de métriques depuis 15 minutes — l'agent est peut-être arrêté.",
            InsightReportText::for($insight),
        );
    }

    // ── ServerHealthDetector::evaluateDisk() ────────────────────────────────

    public function test_server_health_metric(): void
    {
        $insight = $this->insight(InsightType::SERVER_HEALTH, [
            'metric' => 'disk',
            'server_id' => 1,
            'server_name' => 'prod-server',
            'cpu' => 10.0,
            'ram' => 40.0,
            'disk' => 92.5,
            'threshold' => 85.0,
            'value' => 92.5,
            'sites' => [],
        ]);

        $this->assertSame(
            'Le serveur prod-server : le disque à 92,5 % (seuil : 85,0 %).',
            InsightReportText::for($insight),
        );
    }

    // ── ServerHealthDetector::evaluateLoad() ────────────────────────────────

    public function test_server_health_load(): void
    {
        $insight = $this->insight(InsightType::SERVER_HEALTH, [
            'metric' => 'load',
            'server_id' => 1,
            'server_name' => 'prod-server',
            'cpu' => 10.0,
            'ram' => 40.0,
            'disk' => 40.0,
            'threshold' => 1.0,
            'value' => 1.4,
            'sites' => [],
            'load_avg_5' => 14.0,
            'cores' => 10,
            'ratio' => 1.4,
        ]);

        $this->assertSame(
            'Le serveur prod-server : charge élevée — 14,0 sur 10 cœurs.',
            InsightReportText::for($insight),
        );
    }

    // ── SslExpiryDetector::checkMonitor() — expired ─────────────────────────

    public function test_ssl_expiry_expired(): void
    {
        $insight = $this->insight(InsightType::SSL_EXPIRY, [
            'hostname' => 'example.com',
            'expires_at' => '2026-09-01T00:00:00Z',
            'days_remaining' => -3,
            'threshold' => 7,
        ]);

        $this->assertSame(
            'Le certificat SSL de example.com a expiré.',
            InsightReportText::for($insight),
        );
    }

    // ── SslExpiryDetector::checkMonitor() — expiring soon ───────────────────

    public function test_ssl_expiry_expiring(): void
    {
        $insight = $this->insight(InsightType::SSL_EXPIRY, [
            'hostname' => 'example.com',
            'expires_at' => '2026-09-28T00:00:00Z',
            'days_remaining' => 5,
            'threshold' => 7,
        ]);

        $this->assertSame(
            'Le certificat SSL de example.com expire dans 5 jours.',
            InsightReportText::for($insight),
        );
    }

    // ── DomainExpiryService::checkSite() (prod title example) ──────────────

    public function test_domain_expiry_expiring(): void
    {
        $insight = $this->insight(InsightType::DOMAIN_EXPIRY, [
            'domain' => 'example.com',
            'expires_at' => '2026-09-26T00:00:00Z',
            'days_remaining' => 5,
            'threshold' => 'critical_7d',
        ]);

        $this->assertSame(
            'Le nom de domaine example.com expire dans 5 jours.',
            InsightReportText::for($insight),
        );
    }

    // ── NotificationService::createWarmingDisabledInsight() ────────────────

    public function test_warming_disabled(): void
    {
        $insight = $this->insight(InsightType::WARMING_DISABLED, [
            'warm_site_id' => 1,
            'consecutive_failures' => 5,
            'already_notified_directly' => true,
        ], 'example.com');

        $this->assertSame(
            'Le préchauffage du cache a été désactivé automatiquement sur example.com après 5 échecs consécutifs.',
            InsightReportText::for($insight),
        );
    }

    // ── TriggerDokployRollback::handleRollbackFailure() ─────────────────────

    public function test_deploy_rollback_failed(): void
    {
        $insight = $this->insight(InsightType::DEPLOY_ROLLBACK_FAILED, [
            'deploy_event_id' => 1,
            'application_id' => 'app-1',
            'application_name' => 'webcompare-fr',
            'commit_sha' => 'abc123',
            'rollback_error' => 'timeout',
        ]);

        $this->assertSame(
            'Le retour en arrière automatique a échoué après un déploiement raté de « webcompare-fr » — le site reste sur la version cassée.',
            InsightReportText::for($insight),
        );
    }

    // ── SiteProjectMapper::raiseUnmapped() ──────────────────────────────────

    public function test_vikunja_unmapped_site(): void
    {
        $insight = $this->insight(InsightType::VIKUNJA_UNMAPPED_SITE, [
            'site_id' => 1,
            'primary_domain' => 'example.com',
            'alias' => 'Example',
        ]);

        $this->assertSame(
            'Les alertes de example.com ne sont reliées à aucun projet de suivi interne.',
            InsightReportText::for($insight),
        );
    }

    // ── Fallback paths ───────────────────────────────────────────────────────

    public function test_fallback_when_a_required_payload_key_is_missing(): void
    {
        $insight = $this->insight(InsightType::ZOMBIE_PAGE, [
            'published_count' => 20,
            // 'zombie_count' missing.
        ]);

        $this->assertSame(
            'Point détecté : pages sans visibilité.',
            InsightReportText::for($insight),
        );
    }

    public function test_fallback_for_a_type_with_an_unrelated_payload_shape(): void
    {
        // HEALTH_DROP's OTHER payload shape (OrphanMonitorDetector) — an
        // internal ops signal that never matches the health-score shape.
        $insight = $this->insight(InsightType::HEALTH_DROP, [
            'marker' => 'orphan-monitors',
            'monitor_ids' => [1, 2],
            'monitor_count' => 2,
        ]);

        $this->assertSame(
            'Point détecté : baisse de la note de santé.',
            InsightReportText::for($insight),
        );
    }

    public function test_fallback_with_empty_payload_covers_every_type(): void
    {
        // VIKUNJA_UNMAPPED_SITE is the one exception: it can build its
        // sentence from the Insight's own `site` column alone (always
        // present, NOT NULL) even with an empty payload — see
        // vikunjaUnmappedSite(), which is why it is excluded here rather
        // than asserted to fall back.
        foreach (InsightType::cases() as $type) {
            if ($type === InsightType::VIKUNJA_UNMAPPED_SITE) {
                continue;
            }

            $insight = $this->insight($type, []);

            $this->assertStringStartsWith('Point détecté :', InsightReportText::for($insight));
        }
    }
}
