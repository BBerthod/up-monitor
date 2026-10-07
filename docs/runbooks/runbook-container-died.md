# Runbook: container died (crashé sans restart auto)

> **Si tu lis ça en pleine panne** : saute à [Quick Fix](#quick-fix-tldr-pour-panique).

> **Basé sur l'incident example-shop 2026-04-21 → 2026-04-27 (6 jours masqués par cache CF).**

---

## Symptômes

- `/api/health` (uncached) renvoie 502 ou 503 sur tous les locales / paths non-cachés
- Pages d'accueil servies "normalement" (cache Cloudflare actif `stale-if-error`)
- `docker ps` : container absent ou en état `Exited (137)` / `Exited (1)`
- Dokploy dashboard : status rouge / "Stopped"
- Better Stack : alerte sur uncached endpoint, monitor cached (`/`) reste vert (cf. `MEMORY.md` règle 7)

**Distinguer de** :
- Si le container tourne mais l'app a freeze → voir [monitoring-frozen](./runbook-monitoring-frozen.md) (pour up) ou ce runbook
- Si déploiement Dokploy a échoué → voir [dokploy-deploy-failed](./runbook-dokploy-deploy-failed.md)

---

## Impact

- **Sites/services affectés** : un site spécifique de la fleet (1 container = 1 site en architecture Dokploy)
- **User-facing** : oui, mais peut être MASQUÉ pendant 1-12h par le cache Cloudflare `stale-if-error`. C'est ce qui rend cet incident vicieux.
- **Sévérité** : P0 si site business critique (example-shop, example.com). P1 sinon.

---

## Diagnostic (10 min max)

1. **Confirmer que le container est down** :
   ```bash
   # Sur le serveur Dokploy
   docker ps -a --filter name=<site-alias> --format '{{.Names}}\t{{.Status}}'
   ```
   - `Up X minutes` → pas down, voir un autre runbook
   - `Exited (137)` → OOM kill (memory)
   - `Exited (1)` → crash applicatif
   - `Exited (143)` → SIGTERM clean (probable redeploy en cours)
   - Absent → container supprimé

2. **Voir la dernière sortie** :
   ```bash
   CONTAINER=$(docker ps -a --filter name=<site-alias> --format '{{.Names}}' | head -1)
   docker logs --tail 200 "$CONTAINER"
   ```
   Chercher : `Killed`, `out of memory`, `Fatal error`, `PHP Fatal`, `composer install`.

3. **Vérifier la memory du host** :
   ```bash
   free -h
   docker stats --no-stream
   ```
   Si free < 200MB → OOM probable. Vérifier les `mem_limit` dans `docker-compose.production.yml`.

4. **Vérifier la `restart_policy`** :
   ```bash
   docker inspect "$CONTAINER" --format='{{json .HostConfig.RestartPolicy}}{{"\n"}}{{json .Spec.TaskTemplate.RestartPolicy}}'
   ```
   Attendu : `"Name":"unless-stopped"` (standalone) OU `"Condition":"any"` (Swarm/Dokploy). Si `"Name":"no"` → c'est l'incident historique. Cf. `MEMORY.md` règle 2.

5. **Cache Cloudflare en train de masquer ?**
   ```bash
   curl -I https://<domain>/ -H "Cache-Control: no-cache"  # purge force
   curl -sf https://<domain>/api/health  # uncached endpoint
   ```
   Si `/` répond 200 mais `/api/health` répond 502 → confirmé : cache stale masque la panne.

---

## Causes probables

1. **OOM kill** (40%) → augmenter `mem_limit` ou identifier la fuite mémoire (Sentry helps)
2. **Crash au boot (composer/migration/asset)** (30%) → Dockerfile ou migration cassée par dernier deploy
3. **`restart_policy` manquant** (15%) → cf. règle 2, fix à appliquer dans `docker-compose.production.yml`
4. **Healthcheck `start_period` trop court → restart-loop fail** (10%) → cf. règle 3
5. **Erreur applicative fatale (config manquante, secret expiré)** (5%) → check `.env` et logs

---

## Fix (par ordre de risque)

### Option 1 — Restart manuel (< 30s downtime, 0 risque)
```bash
docker start "$CONTAINER"
sleep 10
docker ps --filter name=<site-alias>
```
- Quand : crash isolé, container existe toujours.
- Risque : nul si la cause est transitoire. Si crash récurrent → ne pas s'arrêter là, investiguer.

### Option 2 — Forcer Dokploy à redéployer
- Quand : container absent ou crash récurrent en boucle.
- Risque : 3-5 min downtime.
- Action : Dashboard Dokploy → site → "Redeploy".

### Option 3 — Rollback à la version précédente
- Quand : le crash a commencé après le dernier deploy (timestamp coincide).
- Risque : pertes de données écrites depuis le deploy (rare si DB indépendante).
- Action : Dashboard Dokploy → site → Deployments → version N-1 → "Redeploy this version".

### Option 4 — Ajouter `restart_policy` si absent (fix code)
Si le diagnostic 4 montre `"Name":"no"` → c'est un bug structurel, pas un incident isolé.
```yaml
# docker-compose.production.yml
services:
  app:
    restart: unless-stopped
    deploy:
      restart_policy:
        condition: any
        delay: 10s
        max_attempts: 0
        window: 60s
```
Commit + redeploy → futur crash auto-healed.

---

## Validation

- [ ] `docker ps --filter name=<site-alias>` → `Up X seconds/minutes`
- [ ] `curl -sf https://<domain>/api/health` → 200 + JSON `"status":"healthy"`
- [ ] `curl -I https://<domain>/` → 200 (cache repeuplé)
- [ ] Better Stack : monitor uncached repassé vert (≤ 2 min)
- [ ] Sentry : pas d'exception fraîche depuis le restart (5 min observation)
- [ ] Si OOM : `docker stats` montre memory usage < 80% de la limit

---

## Quick Fix (TLDR pour panique)

```bash
# Sur le serveur Dokploy
CONTAINER=$(docker ps -a --filter name=<site-alias> --format '{{.Names}}' | head -1)
docker logs --tail 50 "$CONTAINER"        # comprendre vite
docker start "$CONTAINER"                  # redémarrage simple
sleep 15
curl -sf https://<domain>/api/health && echo OK || echo STILL_DOWN
```

Si `STILL_DOWN` après 30s → Option 2 (redeploy via Dokploy dashboard).

---

## Postmortem (à remplir après)

- [ ] Timeline : crash time vs detection time (l'incident example-shop = 6 jours, à cause du masque CF)
- [ ] Root cause : OOM ? config ? code ? deploy récent ?
- [ ] Detection delay : Better Stack a-t-il alerté sur l'**uncached** endpoint ? Si non, c'est urgent à corriger.
- [ ] **CF cache était-il en train de masquer ?** Si oui, vérifier que `stale-if-error` est cap à 600s sur ce site.
- [ ] Resolution time : MTTR depuis détection.
- [ ] Mémoire du process : justifie-t-elle la `mem_limit` actuelle ?
- [ ] Action items : ajouter `restart_policy` partout où ça manque ? Cap CF stale-if-error ?
