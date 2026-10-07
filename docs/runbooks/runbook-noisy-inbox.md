# Runbook: inbox bruyante (les vrais signaux sont noyés)

> **Si tu lis ça parce que l'inbox est illisible** : saute à [Quick Fix](#quick-fix-tldr).

> **Basé sur la revue 2026-07-28 → PR #64 / #65 : 63 items ouverts dont ~45 sans signal exploitable.**

---

## Symptômes

- `/inbox` affiche des dizaines d'items et personne ne la lit plus
- Des titres manifestement absurdes :
  - `Performance regression: LCP worsened (4953 → 4953)` — delta **zéro**
  - `LCP worsened (4080 → 4082)` — 2 ms
  - `CTR -43.2% (0.0% → 0.0%)` — variation entre deux valeurs qui s'affichent toutes deux à zéro
  - `Health grade dropped: A -> B (score 91 -> 89)` — 1 point
- Le même insight apparaît **2 fois** pour un site (content decay, striking distance)
- Un item `Monitor DOWN: <site>` reste affiché alors que le monitor est **UP**
- Un incident `functional / Sitemap` est actif depuis des jours sans que rien ne soit cassé
- Beaucoup d'items à `impact_score = 0`

**Distinguer de** :
- Si l'inbox est VIDE alors qu'un site est down → voir [monitoring-frozen](./runbook-monitoring-frozen.md). Ici c'est l'inverse : trop de signal, donc plus de signal.

---

## Impact

- **User-facing** : non. **Mais conséquence majeure** : une alerte critique noyée dans 45 faux positifs est une alerte perdue. Un item `CRITICAL` affiché après résolution détruit la confiance dans tout le tableau — c'est le coût réel, plus élevé que celui de chaque faux positif pris isolément.
- **Sévérité** : P2 en soi, mais P1 par effet de masquage.

---

## Diagnostic

> Read-only. Ne rien acquitter en masse avant d'avoir compris — l'acquittement est définitif et efface la preuve.

### 1. Compter et classer

```php
// artisan tinker
foreach (DB::select("select type, severity, count(*) as n from insights
    where acknowledged_at is null group by type, severity order by n desc") as $r) {
    echo str_pad($r->type, 24) . str_pad($r->severity, 12) . " n={$r->n}\n";
}
```

Un type qui domine largement = un detector mal calibré, pas une flotte en feu.

### 2. Dater les items — le réflexe qui tranche

```php
DB::select("select type, min(detected_at) as oldest, max(detected_at) as newest,
    count(*) as n from insights where acknowledged_at is null group by type");
```

**Si les items datent d'avant le dernier déploiement, un changement de seuil ne les a pas encore touchés.** Un filtre réduit les *créations* ; il ne nettoie pas l'existant. Ne conclus pas « le fix ne marche pas » avant d'avoir relancé un cycle.

### 3. Relancer un detector et comparer AVANT / APRÈS

```php
$before = DB::select("select count(*) as n from insights
    where acknowledged_at is null and type='perf_regression'")[0]->n;

$d = app(App\Services\PerfRegressionDetector::class);
$n = 0;
foreach (App\Models\Monitor::withoutGlobalScopes()
    ->where('type','http')->where('is_active',true)->get() as $m) {
    $n += $d->detectForMonitor($m);
}

$after = DB::select("select count(*) as n from insights
    where acknowledged_at is null and type='perf_regression'")[0]->n;
echo "recréés=$n | avant=$before | après=$after\n";
```

**`$after` > `$n` révèle un défaut de purge** : des lignes survivent sans être recréées. C'est exactement ce qui a mené à la PR #65 — le compteur disait 10 recréés alors que l'inbox en affichait 20.

### 4. Vérifier si des insights sont orphelins de leur incident

```php
DB::select("select i.id, i.title, mi.resolved_at from insights i
    left join monitor_incidents mi on mi.id = (i.payload->>'incident_id')::int
    where i.acknowledged_at is null and i.type = 'uptime_incident'");
```

Un `resolved_at` non nul avec un insight ouvert = projection inbox désynchronisée.

---

## Causes connues et où regarder

| Symptôme | Cause | Fichier |
|---|---|---|
| `LCP worsened (X → X)`, deltas de quelques ms | Pas de plancher de variation sur les Core Web Vitals | `PerfRegressionDetector` + `monitoring.perf_regression.min_delta_*` |
| Page à 12-16 s de LCP qui n'alerte jamais | Detector de delta aveugle au niveau absolu | `monitoring.perf_regression.critical_lcp_ms` |
| `Health grade dropped` sur 1 point | Grades = paliers durs tous les 10 pts, pas d'hystérésis | `monitoring.health_drop.min_score_delta` |
| `CTR -90%` sur un site à 0 clic | Taux gaté sur les impressions, sans clics au numérateur | `monitoring.what_changed.min_ctr_clicks` |
| Hausse de trafic classée INFO alors que c'est du bot | Aucune corroboration croisée GA4 ↔ GSC | `WhatChangedService::isImplausibleSurge()` |
| Insight en double pour un site | Idempotence par `monitor_id` alors que le signal est par site | `DispatchInsights` (dédup au dispatch, par hostname) |
| `Monitor DOWN` après résolution | Un chemin de résolution n'émet pas son event de cycle de vie | `ResolveStaleIncidentsCommand`, `CheckService` |
| Incident TIMEOUT jamais fermé | Zone morte : seuil d'ouverture ≠ complément du seuil de fermeture | `CheckService::checkThresholds()` |
| Insight qui survit au rétablissement | Purge placée après le early return | `PerfRegressionDetector` (cf. `HealthDropDetector` pour le bon ordre) |
| Incident `functional / Sitemap` permanent | `track_changes` traite un diff comme une panne | `SitemapChecker::trackChanges()` |
| `min_urls: 0 URLs found` sur un sitemap valide | Le checker ne lisait que `<url>` ; un `<sitemapindex>` n'en contient aucun, et examplestore en imbrique **deux** niveaux | `SitemapChecker::extractUrls()` |
| `Sitemap unreachable` sur un site sain | `sites.sitemap_path` pointe ailleurs que le vrai index — mesurer avant d'accuser le site | colonne `sites.sitemap_path` |
| `WordPress 2` / une version de lib | La version était lue sur un asset ou sur la ligne de licence GPL du readme | `WordPressVersionService` |
| `No consent platform` sur un site qui gate ses pubs | Seuls les CMP tiers étaient reconnus, pas Consent Mode v2 | `CmpDetector::hasConsentModeV2()` |

---

## Le piège de fond : mesurer avant d'accuser

Sur les quatre lignes ajoutées le 2026-08-12, **le site allait bien à chaque fois** — c'est la mesure
qui était fausse. Un détecteur qui se trompe coûte plus cher qu'un détecteur absent : il consomme de
l'attention et il apprend à ignorer la catégorie entière.

Avant de traiter un item comme un vrai problème, reproduire sa mesure à la main. Trois erreurs ont
suffi à produire quatre faux positifs ce jour-là :

- **Le mauvais conteneur.** `wp option get siteurl` d'abord, toujours. `parse-optical…` = webcompare.**fr**,
  `index-1080p…` = **.de**, `reboot-solid-state…` = **.com**, `synthesize-cross-platform…` = **reviewsite**.
  Interroger un conteneur au hasard avec un `Host:` ne donne pas le site voulu mais le vhost par défaut.
- **Cloudflare devant l'origine.** Depuis un poste, un `curl` peut recevoir un challenge ou une version
  en cache. Tester depuis le serveur avec `--resolve <domaine>:443:127.0.0.1`.
- **L'état du navigateur.** Un test de consentement dans un Chrome qui a déjà accepté montre les scripts
  chargés et fait conclure à l'absence de bandeau. Vider la clé (`consent_v1`, `cookie-consent`) et
  recharger avant de conclure.

Corollaire pour les correctifs : **valider un détecteur contre les sites réels, pas seulement contre
des fixtures**. Deux correctifs passaient leurs tests et restaient faux en production — un asset servi
depuis un cache de page, et un sitemap imbriqué sur un niveau de plus que prévu.

---

## Fix

Par ordre de risque croissant.

### 1. Ajuster un seuil (aucun déploiement)

Tous les seuils sont surchargeables par variable d'environnement, `0` désactivant la règle. Voir la section `monitoring.*` de `config/monitoring.php` — chaque clé porte en commentaire le cas de production qui l'a motivée.

Après changement : `php artisan config:clear`, puis relancer un cycle (étape 3 du diagnostic) et **mesurer** plutôt que supposer.

### 2. Corriger un detector

Deux invariants à respecter :

- **Purger dès que l'état courant est connu**, pas seulement quand il y a quelque chose à écrire. Distinguer « rien à signaler » (→ purger) de « je ne peux pas juger » (→ ne rien toucher : purger sur un manque de données supprimerait un signal valide).
- **Dédupliquer à la maille du signal.** Un signal par site (une page qui décline, un mot-clé) se déduplique par site ; un signal par endpoint (certificat, score Lighthouse d'une URL) reste par monitor.

### 3. Acquitter en masse — dernier recours

Irréversible et ça efface la preuve. À réserver aux reliquats dont la cause est corrigée et vérifiée :

```php
// Cible étroite, jamais un acknowledge global.
Insight::withoutGlobalScopes()
    ->where('type', 'perf_regression')
    ->whereNull('acknowledged_at')
    ->where('impact_score', 0)
    ->where('detected_at', '<', '<date-du-deploiement>')
    ->update(['acknowledged_at' => now()]);
```

---

## Quick Fix (TL;DR)

1. Compter par type (étape 1) → identifier le detector dominant
2. **Dater** les items (étape 2) → sont-ils antérieurs au dernier déploiement ?
3. Relancer le detector et comparer avant/après (étape 3)
4. Si `après > recréés` → défaut de purge, pas défaut de seuil

---

## Postmortem — 2026-07-28 → 2026-08-03

**Résultat mesuré en production** : `perf_regression` 24 → 10, les 14 lignes fantômes supprimées, et 4 pages réellement cassées (10 à 16 s de LCP) enfin visibles alors qu'elles n'avaient **jamais** alerté — le detector ne regardait que les deltas.

**Ce qui a été appris**

- Un faux positif ne coûte pas cher isolément ; 45 faux positifs coûtent la confiance dans l'outil entier.
- Un seuil purement relatif produit des alertes absurdes sur les grandes valeurs ; un seuil purement absolu en produit sur les petites. Exiger les deux.
- Un detector de delta est aveugle à un niveau catastrophique mais stable. Niveau et tendance sont deux questions distinctes.
- Un taux n'est pas interprétable sans son numérateur.
- Une hausse n'est pas automatiquement une bonne nouvelle : la corroborer par une source indépendante avant de la classer INFO.
- Le bug le plus coûteux (PR #65) n'a été trouvé ni par les tests ni par la CI, mais en **mesurant l'effet réel en production après déploiement**. Le signal était un écart de comptage, pas une erreur.
- Un test de régression doit être validé en **restaurant temporairement le bug** : un test qui passe dans les deux cas ne prouve rien.
