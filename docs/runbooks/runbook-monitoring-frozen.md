# Runbook: monitoring frozen (up.example.com ne checke plus rien)

> **Si tu lis ça en pleine panne** : saute à [Quick Fix](#quick-fix-tldr-pour-panique).

> **Basé sur l'incident 2026-04-21 → 2026-05-03 (10j de blackout silencieux).**

---

## Symptômes

- `https://up.example.com/api/health` retourne `{"status":"unhealthy"}` ou 503
- Dashboard up.example.com : **dernier check** affiché il y a > 5 min sur tous les monitors
- Aucune nouvelle ligne dans `monitor_checks` table : `MAX(checked_at)` > 2 min
- Aucun incident détecté côté up alors qu'on sait qu'un site est down (preuve par audit `/monitor`)
- Better Stack : alerte freshness keyword (`"status":"healthy"` absent)

**Distinguer de** :
- Si Postgres est réellement down → voir [database-locked](./runbook-database-locked.md)
- Si seul le container app a crashé → voir [container-died](./runbook-container-died.md). Le **monitoring frozen** est un superset : ça inclut le cas où l'app répond mais les workers ne tournent plus.

---

## Impact

- **Sites/services affectés** : monitoring de toute la fleet (13 sites). Aucun site client direct, mais aveuglement total.
- **User-facing** : non (côté admin). **Mais conséquence majeure** : les vraies pannes fleet ne déclenchent plus d'alerte → MTTD passe de 1 min à des jours.
- **Sévérité** : P0 (le watchdog est aveugle).

---

## Diagnostic (10 min max)

> Read-only. Ne rien restart avant d'avoir compris.

1. **Vérifier l'âge du dernier check** :
   ```bash
   curl -s https://up.example.com/api/health | jq '.checks.scheduler'
   ```
   Si `last_run_age_s > 600` → scheduler mort. Si la clé n'existe pas → l'app est trop ancienne (PR #20 pas déployée).

2. **Vérifier que les workers tournent dans le container Dokploy** :
   ```bash
   # Sur le serveur Dokploy
   docker ps --filter name=up- --format '{{.Names}}'
   docker exec <container_name> supervisorctl status
   ```
   Attendu : `queue-worker RUNNING`, `lighthouse-worker RUNNING`, `scheduler RUNNING`.
   **Red flag** : si `scheduler` est absent → on est dans le pattern de l'incident 04/2026 (cf. PR #23).

3. **Vérifier qu'aucun process ne tourne en zombie** :
   ```bash
   docker exec <container_name> ps aux | grep -E "queue:work|schedule:work"
   ```
   Si processus présent **mais** `last_run_age_s` énorme : worker frozen sur un job qui boucle.

4. **Vérifier Redis (queue/cache)** :
   ```bash
   docker exec <redis_container> redis-cli ping
   docker exec <redis_container> redis-cli LLEN queues:default
   ```
   Si la queue grossit sans s'épuiser → consumer mort.

5. **Logs récents** :
   ```bash
   docker logs --tail 200 <container_name> | grep -iE "error|fatal|killed|oom"
   ```

---

## Causes probables (par fréquence)

1. **Workers manquants dans `docker/supervisor/app.conf`** (60% — c'est l'incident historique)
   → Le `Dockerfile` ne lance pas `schedule:work`. Vérifier que les programs `[program:scheduler]` et `[program:queue-worker]` sont déclarés.
2. **Worker frozen sur un job qui ne termine jamais** (25%)
   → `--max-time` jamais atteint, pas de timeout sur le job lui-même. Restart résout.
3. **Redis injoignable** (10%)
   → Le worker tourne mais ne peut pas dequeue. Voir [database-locked](./runbook-database-locked.md) pour Postgres équivalent.
4. **Container OOM-killed et redémarré sans worker** (5%)
   → Voir [container-died](./runbook-container-died.md).

---

## Fix (par ordre de risque, du moins risqué au plus)

### Option 1 — Restart supervisor scheduler (< 5s downtime sur les checks)
```bash
docker exec <container_name> supervisorctl restart scheduler queue-worker lighthouse-worker
```
- Quand : workers présents mais frozen.
- Risque : nul (les jobs en cours seront re-tentés via le retry pattern).

### Option 2 — Restart container complet (< 30s downtime)
```bash
docker restart <container_name>
```
- Quand : Option 1 inefficace ou supervisorctl ne répond pas.
- Risque : 30s d'erreurs sur l'admin web.

### Option 3 — Redeploy via Dokploy (3-5 min)
- Quand : on suspecte une corruption d'image ou un fichier supervisor malformé.
- Risque : si la dernière build est mauvaise, on peut empirer.
- Action : Dashboard Dokploy → app `up` → bouton Redeploy.

### Option 4 — Patch immédiat de `docker/supervisor/app.conf`
Si le diagnostic montre que `[program:scheduler]` manque, le runbook ne suffit pas — c'est un fix code.
```bash
# 1. Vérifier en local
cat /Users/me/Dev/radiank/up/docker/supervisor/app.conf | grep -A4 program
# 2. Si scheduler absent : reproduire le fix de la PR #23
# 3. Commit + push + redeploy
```

---

## Validation

- [ ] `curl -s https://up.example.com/api/health | jq` → `"status":"healthy"`, tous les sub-checks `ok`
- [ ] `last_run_age_s` < 120 dans la réponse health
- [ ] Dashboard up.example.com : un nouveau check est apparu dans la dernière minute
- [ ] Better Stack revient au vert (≤ 2 min après le fix)
- [ ] `docker exec <container> supervisorctl status` : tous les programs `RUNNING`

---

## Quick Fix (TLDR pour panique)

```bash
# Sur le serveur Dokploy — restart les workers sans toucher l'app web
CONTAINER=$(docker ps --filter name=up- --format '{{.Names}}' | head -1)
docker exec "$CONTAINER" supervisorctl restart scheduler queue-worker lighthouse-worker
sleep 30
curl -s https://up.example.com/api/health | jq '.checks.scheduler'
```

Si `last_run_age_s` reste > 300 après 1 min → passer à Option 2 (restart container complet).

---

## Postmortem (à remplir après)

- [ ] Timeline : quand workers ont stoppé vs quand on l'a vu (dans l'incident historique : 10 jours de delta)
- [ ] Root cause : workers manquants supervisor / job frozen / OOM ?
- [ ] Detection delay : Better Stack a alerté en combien de temps ? Si > 5 min, le watchdog externe doit être renforcé.
- [ ] Resolution time : combien de minutes entre alerte et `status:healthy` rendu ?
- [ ] **Patterns à transformer en règles fleet** : check `MEMORY.md` § Patterns to apply across the fleet — vérifier que les 7 règles y figurent toujours.
- [ ] Tester en game day quand ? (cf. `docs/game-days.md` scenario 1)
