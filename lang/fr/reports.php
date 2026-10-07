<?php

/**
 * Copy for the per-site periodic report (mail + PDF).
 *
 * Always requested with an explicit 'fr' locale (__('reports.x', [], 'fr')) —
 * the report is French regardless of config('app.locale'), same as the rest
 * of the mail/PDF templates which hardcode French copy.
 *
 * Kept in one place so the HTML mail and the PDF never drift on wording.
 */
return [

    // Section intros — one plain-French line under each section title,
    // explaining what the numbers mean to a non-technical reader.
    'sections' => [
        'health' => 'Note globale sur 100 qui combine disponibilité, vitesse, référencement et alertes en cours. Au-dessus de 70 : très bien.',
        'uptime' => "Part du temps où votre site a répondu correctement à nos vérifications, faites chaque minute. 99,9 % ≈ 10 min d'arrêt par semaine.",
        'response_time' => 'Délai moyen avant que votre serveur commence à répondre. Google le juge bon sous 800 ms.',
        'monitors' => 'Chaque ligne est un point de contrôle de votre site : disponibilité sur la période et temps de réponse moyen.',
        'incidents' => 'Détail des interruptions détectées sur la période.',
        'gsc' => "Vos performances dans les résultats de recherche Google. Clics = visites venant de Google. Impressions = nombre d'affichages dans les résultats. CTR = part des affichages qui donnent un clic. Position = rang moyen (plus le chiffre est bas, mieux c'est). Chiffres cumulés sur les 28 jours précédant la fin de la période.",
        'ga4' => 'Le trafic mesuré par Google Analytics : visiteurs = nombre de personnes uniques venues sur le site ; sessions = nombre de visites, une même personne pouvant revenir plusieurs fois. Chiffres cumulés sur les 28 jours précédant la fin de la période.',
        'lighthouse' => 'Notes Google sur 100 pour la version mobile du site, sur les mêmes échelles que les jauges « Où se situe votre site » ci-dessus (vert : très bien · jaune : correct · orange : à améliorer · rouge : critique).',
        'insights' => 'Points détectés automatiquement qui méritent une action.',
        'gauges' => 'Chaque jauge place votre site sur une échelle de référence : vert = très bien, jaune = correct, orange = à améliorer, rouge = critique.',
    ],

    // "Where does your site stand" gauge section title + world-comparison
    // sentences (App\Support\ReportBenchmarks 'good_share' world type —
    // comparison against OTHER Radiank sites was removed entirely; every
    // number here is HTTP Archive Web Almanac 2025 data, never invented).
    'gauges' => [
        'title' => 'Où se situe votre site',
        'world' => [
            'within_good' => 'Vous faites partie des :share % de sites dans le monde qui atteignent ce niveau.',
            'outside_good' => ':share % des sites dans le monde atteignent le seuil « bon » de Google.',
            'tick_label' => 'Moyenne mondiale',
            'source' => 'HTTP Archive, Web Almanac 2025',
        ],
    ],

    // How the comparison period is labelled next to a delta.
    'comparison' => [
        'weekly' => 'vs semaine précédente',
        'monthly' => 'vs mois précédent',
    ],

    // Health score trend, in words rather than a symbol — coloured good/bad/neutral.
    'trend' => [
        'up' => 'en hausse',
        'down' => 'en baisse',
        'flat' => 'stable',
    ],

    // PDF-only lexicon, repeating the definitions in one block.
    'lexicon' => [
        'health' => 'Score de santé — note globale sur 100 combinant disponibilité, vitesse, référencement et alertes en cours.',
        'uptime' => 'Disponibilité — part du temps où le site a répondu correctement à nos vérifications.',
        'response_time' => 'Temps de réponse — délai moyen avant que le serveur commence à répondre.',
        'incidents' => "Incidents — nombre d'interruptions détectées et leur durée cumulée.",
        'gsc_clicks' => 'Clics (Search Console) — visites venant des résultats Google.',
        'gsc_impressions' => "Impressions (Search Console) — nombre d'affichages dans les résultats Google.",
        'gsc_ctr' => 'CTR (Search Console) — part des affichages qui donnent un clic.',
        'gsc_position' => 'Position moyenne (Search Console) — rang moyen dans les résultats (plus le chiffre est bas, mieux c\'est).',
        'ga4_users' => 'Utilisateurs (Analytics) — nombre de visiteurs uniques.',
        'ga4_sessions' => 'Sessions (Analytics) — nombre de visites, une même personne pouvant revenir plusieurs fois.',
        'lighthouse' => 'Lighthouse — notes Google sur 100 pour la version mobile du site, sur les mêmes échelles que les jauges « Où se situe votre site » (vert : très bien · jaune : correct · orange : à améliorer · rouge : critique).',
        'insights' => 'À surveiller — points détectés automatiquement qui méritent une action.',
        'gauges' => 'Jauges « Où se situe votre site » — chaque jauge compare une mesure à une échelle construite à partir des seuils Google quand ils existent (Core Web Vitals, TTFB, Lighthouse), sinon à des repères usuels du marché. Le niveau vert regroupe la zone « bonne » de Google et la moitié de la zone « à améliorer » la plus proche du bon : vert (très bien), jaune (correct), orange (à améliorer), rouge (critique).',
        'gauges_world' => 'Comparaison mondiale — source : HTTP Archive, Web Almanac 2025 (données de juillet 2025, mobile). Le repère « Moyenne mondiale » indique la médiane mondiale (accessibilité uniquement) ; les autres jauges (temps de réponse, LCP, INP, CLS) indiquent la part des sites dans le monde qui atteignent le seuil « bon » de Google.',
    ],

    // "À surveiller" insight sentences — App\Support\InsightReportText turns a
    // detector's `type` + `payload` into ONE of these, never the raw (English)
    // `title` column the detectors write for the Up app UI/Vikunja. A type's
    // French label (used by the `fallback` sentence when the payload is
    // missing an expected key, or when a type carries more than one payload
    // shape and none matched) lives in `type_labels`, keyed by InsightType
    // value.
    'insights' => [
        'fallback' => 'Point détecté : :label.',

        'type_labels' => [
            'striking_distance' => 'opportunité de positionnement',
            'traffic_change' => 'changement de trafic',
            'position_change' => 'changement de position',
            'ctr_change' => 'changement de taux de clic',
            'health_drop' => 'baisse de la note de santé',
            'perf_regression' => 'régression de performance',
            'uptime_incident' => 'incident de disponibilité',
            'content_decay' => 'déclin de contenu',
            'revenue_at_risk' => 'revenu menacé',
            'affiliate_leak' => 'fuite de commission affiliée',
            'affiliate_redirect_broken' => 'redirection affiliée cassée',
            'cmp_missing' => 'gestion du consentement manquante',
            'sitemap_health' => 'problème de sitemap',
            'keyword_drop' => 'perte de positions sur des mots-clés',
            'keyword_cannibalisation' => 'cannibalisation de mots-clés',
            'hreflang_broken' => 'erreur de balisage hreflang',
            'outdated_cms' => 'CMS obsolète',
            'heartbeat_missed' => 'tâche planifiée silencieuse',
            'zombie_page' => 'pages sans visibilité',
            'server_health' => 'alerte serveur',
            'ssl_expiry' => 'expiration du certificat SSL',
            'domain_expiry' => 'expiration du nom de domaine',
            'warming_disabled' => 'préchauffage du cache désactivé',
            'deploy_rollback_failed' => 'échec du retour en arrière du déploiement',
            'vikunja_unmapped_site' => 'site non relié au suivi interne',
        ],

        'striking_distance' => '« :query » se classe en position :position avec :impressions impressions par mois — un gain de trafic rapide est possible.',

        'traffic_change' => ':label : :sign:delta_pct % sur la période (:previous → :current).',
        'traffic_change_ga4_broken' => 'Google Analytics ne détecte aucun visiteur alors que Search Console enregistre du trafic — le tag de suivi est probablement cassé.',

        'position_change' => 'Position moyenne :verb : :previous → :current.',
        'position_change_core_update' => "Mouvement de positionnement détecté sur :count sites du portefeuille sur :total — probable mise à jour de l'algorithme Google.",

        'ctr_change' => 'Taux de clic (CTR) : :sign:delta_pct % sur la période (:previous % → :current %).',

        'health_drop' => 'La note de santé du site est passée de :previous_grade à :current_grade (score :previous_score → :current_score).',

        'perf_regression_score' => 'Le score de performance a chuté de :previous à :current points.',
        'perf_regression_lcp_critical' => 'Le temps de chargement (LCP) est critique : :to s (seuil : :threshold s).',
        'perf_regression_metric' => ':label s\'est dégradé (:from → :to).',

        'uptime_incident_down' => 'Le site :site a subi une interruption (:cause).',
        'uptime_incident_functional' => 'Une vérification automatique a échoué sur :site.',

        'content_decay' => 'La page :path a perdu :decline_pct % de ses clics en :weeks semaines.',

        'revenue_at_risk_status' => 'La page :path renvoie une erreur HTTP :status — :clicks clics mensuels menacés.',
        'revenue_at_risk_unreachable' => 'La page :path est injoignable — :clicks clics mensuels menacés.',

        'affiliate_leak' => 'Des liens affiliés sur :path portent :anomaly — :clicks clics mensuels concernés.',
        'affiliate_leak_foreign_tags' => 'un tag différent du vôtre (:count lien)|un tag différent du vôtre (:count liens)',
        'affiliate_leak_untagged' => 'aucun tag (:count lien)|aucun tag (:count liens)',

        'affiliate_redirect_broken' => ':broken redirection affiliée sur :tested testée ne mène plus au marchand.|:broken redirections affiliées sur :tested testées ne mènent plus au marchand.',

        'cmp_missing_consent_mode_without_cmp' => 'Le mode de consentement Google est actif sur :domain, mais aucune plateforme de consentement certifiée n\'est détectée — Google en exige une pour servir des publicités dans l\'UE.',
        'cmp_missing_no_cmp_detected' => 'Aucune plateforme de gestion du consentement détectée sur :domain — les publicités servies ne peuvent être que non personnalisées.',
        'cmp_missing_multiple_cmp_detected' => 'Plusieurs plateformes de consentement coexistent sur :domain (:vendors) et se neutralisent mutuellement.',

        'sitemap_health_empty' => 'Le sitemap de :domain est injoignable ou vide.',
        'sitemap_health_stale' => "Le sitemap de :domain n'a pas été mis à jour depuis :days jours.",
        'sitemap_health_broken' => ':pct % des URL du sitemap de :domain renvoient une erreur.',
        'sitemap_health_redirect' => ':pct % des URL du sitemap de :domain sont redirigées au lieu de répondre directement.',

        'keyword_drop_single' => 'Le mot-clé « :query » est passé de la position :from à :to.',
        'keyword_drop_multiple' => ':count mots-clés ont perdu des positions — le plus touché : « :query » (:from → :to).',

        'keyword_cannibalisation' => ':count recherche Google fait concourir plusieurs de vos pages entre elles — la plus concernée : « :query » (:pages pages).|:count recherches Google font concourir plusieurs de vos pages entre elles — la plus concernée : « :query » (:pages pages).',

        'hreflang_broken_single' => 'Problème hreflang sur :locale : :detail.',
        'hreflang_broken_multiple' => 'Problèmes hreflang sur :count langues — le plus important : :locale (:detail).',

        'outdated_cms' => ':cms :installed sur :domain est dépassé (dernière version disponible : :latest).',

        'heartbeat_missed_never' => 'La tâche planifiée « :name » n\'a jamais transmis de signal depuis sa création (:age) — elle est probablement mal configurée.',
        'heartbeat_missed_late' => 'La tâche planifiée « :name » n\'a pas transmis de signal depuis :age.',

        'zombie_page' => ':zombies pages publiées sur :published n\'apparaissent pas dans les résultats Google.',

        'server_health_heartbeat' => 'Le serveur :server ne transmet plus de métriques depuis :minutes minutes — l\'agent est peut-être arrêté.',
        'server_health_load' => 'Le serveur :server : charge élevée — :load sur :cores cœurs.',
        'server_health_metric' => 'Le serveur :server : :label à :value % (seuil : :threshold %).',

        'ssl_expiry_expired' => 'Le certificat SSL de :hostname a expiré.',
        'ssl_expiry_expiring' => 'Le certificat SSL de :hostname expire dans :days jour.|Le certificat SSL de :hostname expire dans :days jours.',

        'domain_expiry_expired' => 'Le nom de domaine :domain a expiré.',
        'domain_expiry_expiring' => 'Le nom de domaine :domain expire dans :days jour.|Le nom de domaine :domain expire dans :days jours.',

        'warming_disabled' => 'Le préchauffage du cache a été désactivé automatiquement sur :site après :failures échecs consécutifs.',

        'deploy_rollback_failed' => 'Le retour en arrière automatique a échoué après un déploiement raté de « :application » — le site reste sur la version cassée.',

        'vikunja_unmapped_site' => 'Les alertes de :domain ne sont reliées à aucun projet de suivi interne.',
    ],

    // Rule-based "En bref" summary — fragments assembled by SiteReportService,
    // never an LLM narration.
    //
    // 'incidents_count' and 'alerts_open' are pluralization strings (used via
    // trans_choice, not __()): French treats 0 the same as 1 ("0 incident",
    // not "0 incidents"), which is exactly Laravel's built-in 'fr' plural
    // rule for a two-form "singular|plural" string — no explicit [0,1]/[2,*]
    // ranges needed.
    'summary' => [
        'intro' => 'En bref : ',
        'uptime_ok' => 'votre site a été disponible en continu',
        'uptime_incidents' => ":incidents, totalisant :minutes min d'arrêt",
        'incidents_count' => ':count incident|:count incidents',
        'best_change' => 'meilleure progression : :label (:diff :comparison)',
        'worst_change' => 'point de vigilance : :label (:diff :comparison)',
        'alerts_open' => ':count alerte ouverte à surveiller|:count alertes ouvertes à surveiller',
        'alerts_none' => 'aucune alerte ouverte',
    ],
];
