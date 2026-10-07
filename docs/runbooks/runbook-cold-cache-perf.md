# Runbook: cold cache perf (TTFB 14s sur pages non-cachées)

> **Si tu lis ça en pleine panne** : saute à [Quick Fix](#quick-fix-tldr-pour-panique).

> **Basé sur l'incident example-shop (continu, ~mois) — TTFB 14s en cold cache, masqué par le hit ratio Cloudflare élevé sur les pages chaudes.**

---

## Symptômes

- TTFB > 5s sur pages "froides" (URLs peu visitées)
- TTFB < 500ms sur homepage et pages chaudes (cache CF hit)
- Lighthouse score Performance < 50 sur pages internes
- Plainte SEO : Core Web Vitals dégradés sur certaines URLs (`google search console > Core Web Vitals`)
- `up.example.com` Lighthouse run montre `lcp` > 4s sur pages clés
- Logs Laravel : pas d'erreur apparente, juste lent

**Distinguer de** :
- Si le site est totalement down → voir [container-died](./runbook-container-died.md)
- Si seulement quelques requêtes timeout → voir [database-locked](./runbook-database-locked.md)

---

## Impact

- **Sites/services affectés** : sites Laravel ou WordPress sans cache applicatif robuste
- **User-facing** : oui — mais perception variable : utilisateurs visitant les pages chaudes ne voient rien, ceux arrivant sur des pages internes (SEO long tail) attendent 5-15s.
- **Conséquence SEO** : Core Web Vitals dégradés → pénalité de classement Google
- **Sévérité** : P2 (pas down) mais **insidieuse** : invisible au monitoring HTTP standard

---

## Diagnostic (10 min max)

1. **Mesurer TTFB cold vs hot** :
   ```bash
   # Hot path (homepage, déjà en cache CF)
   curl -o /dev/null -s -w "TTFB: %{time_starttransfer}s\nTotal: %{time_total}s\n" https://<domain>/
   # Cold path (URL aléatoire jamais cachée)
   curl -o /dev/null -s -w "TTFB: %{time_starttransfer}s\nTotal: %{time_total}s\n" -H "Cache-Control: no-cache" https://<domain>/page-pas-cachee-$(date +%s)
   ```
   Si delta > 3s → confirmé.

2. **Vérifier le hit ratio CF** : Cloudflare Dashboard → Analytics → Caching. Si `Cached requests` < 70% → cache mal configuré.

3. **Regarder les requêtes DB lentes** :
   ```bash
   # Sur le serveur app
   docker exec <container> tail -200 storage/logs/laravel.log | grep -iE "slow|timeout|deadlock"
   # Sur Postgres
   docker exec <postgres> psql -U <user> -c "SELECT query, mean_exec_time FROM pg_stat_statements ORDER BY mean_exec_time DESC LIMIT 10;"
   ```
   Si une query > 500ms → c'est probablement le bottleneck.

4. **Profiler avec Lighthouse depuis up** :
   - Dashboard up.example.com → monitor du site → bouton "Run Lighthouse audit"
   - Vérifier `time_to_first_byte`, `largest_contentful_paint`, `total_blocking_time`

5. **Vérifier OPCache PHP / fpm** :
   ```bash
   docker exec <container> php -i | grep -E "opcache.enable|opcache.memory_consumption"
   ```
   Attendu : `opcache.enable => On`, memory >= 128MB.

---

## Causes probables

1. **Pages dynamiques sans cache applicatif** (50%) → ajouter cache Laravel `Cache::remember()` ou page cache via middleware
2. **N+1 queries SQL** (25%) → Telescope ou `enable=true` sur DB log → identifier la requête. `with()` au lieu de lazy loading
3. **OPCache désactivé ou mal sized** (10%) → `php.ini` ou `Dockerfile`
4. **Index manquant sur table grosse** (10%) → `EXPLAIN ANALYZE` sur la query lente
5. **Cloudflare cache mal configuré** (5%) → `Cache-Control: public, max-age=...` manquant côté origin

---

## Fix (par ordre de risque)

### Option 1 — Warmup cache préventif (zero downtime)
```bash
# Sur le serveur, lance un crawler interne
docker exec <container> php artisan cache:warm  # si la commande existe
# OU manuel
for url in $(curl -s https://<domain>/sitemap.xml | grep -oP '(?<=<loc>)[^<]+'); do
  curl -s -o /dev/null "$url"
done
```
- Quand : avant un événement traffic (newsletter, lancement, soldes)
- Risque : nul

### Option 2 — Activer Cloudflare "Tiered Cache" + augmenter Edge TTL (5 min)
- Quand : hit ratio < 70%
- Risque : si on cache trop long sur des pages dynamiques, info stale aux users
- Action : CF Dashboard → Caching → Configuration → Edge Cache TTL = 1 day, Tiered Cache = ON

### Option 3 — Ajouter cache applicatif Laravel (fix code)
```php
// Dans le controller
public function show(string $slug) {
    return Cache::remember("page:{$slug}", 3600, function () use ($slug) {
        return Page::where('slug', $slug)->with('relations')->firstOrFail();
    });
}
```
- Quand : la query DB est le bottleneck
- Risque : oubli d'invalidation = stale content. Toujours bind l'invalidation à un model event (saved, deleted)

### Option 4 — Ajouter index DB (fix code)
```bash
# Identifier la query lente d'abord
EXPLAIN ANALYZE SELECT ...;
# Ajouter migration
php artisan make:migration add_index_to_<table>
# Dans le up() : $table->index(['col1', 'col2'])
```
- Quand : `EXPLAIN` montre `Seq Scan` sur table > 50k rows
- Risque : `CREATE INDEX` lock la table. Sur grosse table : utiliser `CREATE INDEX CONCURRENTLY`.

### Option 5 — Augmenter OPCache memory
```dockerfile
# Dockerfile
RUN { \
  echo 'opcache.enable=1'; \
  echo 'opcache.memory_consumption=256'; \
  echo 'opcache.max_accelerated_files=20000'; \
  echo 'opcache.validate_timestamps=0'; \
} > /usr/local/etc/php/conf.d/opcache.ini
```
- Quand : `opcache_get_status()` montre `oom_restarts > 0`
- Risque : besoin redéploiement complet

---

## Validation

- [ ] TTFB cold path < 1.5s : `curl -w "%{time_starttransfer}s" -H "Cache-Control: no-cache" https://<domain>/page-aleatoire`
- [ ] CF hit ratio remonté > 70% (24h après Option 2)
- [ ] Lighthouse Performance score > 70 sur 3 pages clés
- [ ] up dashboard : pas d'incident `cause: business_regression` (si Layer 4 actif)
- [ ] Core Web Vitals GSC : LCP médian < 2.5s sur 7 jours

---

## Quick Fix (TLDR pour panique)

```bash
# Cold cache mass-warm de toutes les URLs sitemap
DOMAIN=https://<your-site>
curl -s "$DOMAIN/sitemap.xml" | grep -oP '(?<=<loc>)[^<]+' | head -200 | xargs -I {} -P 4 curl -s -o /dev/null -w "%{time_starttransfer}s {}\n" {}
```

C'est un patch temporaire. Le vrai fix demande d'ajouter du cache applicatif (Option 3) ou d'identifier le bottleneck DB (Option 4).

---

## Postmortem (à remplir après)

- [ ] Timeline : depuis quand le problème existe ? Souvent invisible "des mois" car masqué par hit ratio
- [ ] Root cause : query lente / cache absent / OPCache mal sized / asset trop gros ?
- [ ] **Detection delay** : sans Layer 4 (`BusinessSnapshot` + `RegressionDetector`), ce type d'incident reste invisible. Action item : pousser Layer 4 si pas encore fait.
- [ ] Resolution time : MTTR.
- [ ] Si query lente identifiée : ajouter au pattern `MEMORY.md` (N+1 trap dans <model>::<method>)
- [ ] Doit-on faire un audit perf trimestriel ? (recommandé)
