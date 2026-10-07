# Runbook: déploiement Dokploy en échec

> **Si tu lis ça en pleine panne** : saute à [Quick Fix](#quick-fix-tldr-pour-panique).

> Generic — couvre les échecs de déploiement Dokploy quelle que soit la cause (build, healthcheck, migration, network).

---

## Symptômes

- Dokploy dashboard : déploiement en état "Failed" / "Error"
- Container nouveau ne démarre pas, ancien container tourne toujours OU les deux sont morts
- `git push` a déclenché un build qui n'aboutit pas
- Logs Dokploy : `build error`, `pull access denied`, `no space left`, `healthcheck failed`
- Web app : 502 Bad Gateway si l'ancien container est mort aussi

**Distinguer de** :
- Si l'ancien container tourne et le site répond → ce n'est PAS down, juste le nouveau deploy a échoué
- Si site totalement down après deploy → critique, voir [container-died](./runbook-container-died.md)

---

## Impact

- **Sites/services affectés** : un site spécifique
- **User-facing** : variable
  - Si rolling deploy + ancien container vivant → invisible aux users (le nouveau code n'est juste pas déployé)
  - Si recreate strategy + nouveau échoue → 502 jusqu'à intervention
- **Sévérité** : P1 si site down, P2 si juste deploy bloqué

---

## Diagnostic (10 min max)

1. **Identifier l'étape qui a échoué** :
   - Dokploy Dashboard → app → Deployments → cliquer sur le déploiement Failed
   - Onglet "Build logs" → Scroll jusqu'à la première ERROR
   
2. **Vérifier l'état des containers** :
   ```bash
   docker ps -a --filter name=<site-alias> --format 'table {{.Names}}\t{{.Status}}\t{{.CreatedAt}}'
   ```
   - 2 containers (1 Up, 1 Exited) → ancien tourne, nouveau a échoué (cas favorable)
   - 0 container Up → site est DOWN, urgence

3. **Catégoriser l'erreur** :
   ```bash
   # Sur le serveur Dokploy
   docker logs <new-container> 2>&1 | tail -100
   ```
   Patterns fréquents :
   - `npm ERR!` → build front cassé
   - `Composer dependency installation failed` → composer.lock vs composer.json mismatch
   - `migration error` → SQL incompatible (cf. [database-locked](./runbook-database-locked.md))
   - `unhealthy` après timeout → healthcheck `start_period` trop court (cf. règle 3 `MEMORY.md`)
   - `Cannot connect to Docker daemon` → Dokploy en panne
   - `No space left on device` → disque plein
   - `pull access denied` → registry auth expirée

4. **Vérifier les ressources host** :
   ```bash
   df -h | head -5     # disque
   free -h             # mémoire
   docker system df    # taille images/volumes
   ```

5. **Vérifier les secrets / .env** :
   - Dokploy → app → Environment → vérifier que les vars critiques sont là (DB_PASSWORD, APP_KEY, etc.)
   - Souvent : nouvelle var manquante dans Dokploy alors qu'elle est dans `.env.example`

---

## Causes probables

1. **Build front (npm/vite) en échec** (25%) — node_modules cache corrompu, version Node mismatch
2. **Composer install en échec** (15%) — lock vs json mismatch, version PHP, package abandonné
3. **Migration SQL** (15%) — voir [database-locked](./runbook-database-locked.md)
4. **Healthcheck `start_period` trop court** (15%) — l'app boot mais pas dans le délai
5. **Var d'env manquante** (10%) — nouvelle var dans le code, pas ajoutée dans Dokploy
6. **Disque host plein** (10%) — souvent old images Docker non purgées
7. **Registry auth expirée** (5%) — Github token révoqué, GHCR auth cassée
8. **Dokploy lui-même en panne** (5%) — restart Dokploy

---

## Fix (par ordre de risque)

### Option 1 — Dokploy "Redeploy" (relancer le même build)
- Quand : suspicion d'un échec transient (network, registry timeout)
- Risque : nul si l'ancien container tourne encore
- Action : Dokploy → app → Redeploy

### Option 2 — Rollback Dokploy à la version précédente
- Quand : la build a fini mais le code nouveau est cassé
- Risque : pertes de données si migrations DB faites (rare, mais à vérifier)
- Action : Dokploy → app → Deployments → version précédente → "Redeploy this version"

### Option 3 — Free disk space (si "No space left")
```bash
docker system prune -af --volumes  # ATTENTION: supprime images/volumes inutilisés
df -h  # vérifier qu'on a > 2GB libre
# Puis redeploy
```

### Option 4 — Fix code + push
Si la cause est un bug code :
```bash
# En local
git revert <bad-commit>
# OU corriger le bug
git push
# Dokploy va auto-redéployer
```

### Option 5 — Fix env var manquante
- Dokploy → app → Environment → ajouter la var manquante
- Cliquer "Save" puis "Redeploy"

### Option 6 — Augmenter `start_period` du healthcheck
Si le diagnostic montre "unhealthy timeout" : le `start_period` est trop court.
```yaml
# docker-compose.production.yml
healthcheck:
  start_period: 360s  # > max(migration_timeout, asset_compile, dependency_wait)
```
Cf. règle 3 dans `MEMORY.md`. Commit + redeploy.

---

## Validation

- [ ] Dokploy dashboard : déploiement marqué "Success"
- [ ] `docker ps --filter name=<site-alias>` : exactement 1 container `Up X minutes`
- [ ] `curl -sf https://<domain>/api/health` → 200 + `"status":"healthy"`
- [ ] `curl -sf https://<domain>/` → 200, contenu attendu
- [ ] Better Stack monitors : tous verts dans les 5 min
- [ ] (Si Layer 5 actif) : smoke test post-deploy passe (`/api/deploy/smoke-test/<alias>`)
- [ ] Sentry : aucune nouvelle erreur dans les 5 min suivant le deploy

---

## Quick Fix (TLDR pour panique)

```bash
# 1. État containers (le site est-il down ?)
docker ps -a --filter name=<site-alias>

# 2. Si ancien container Up → pas de panique, deploy juste bloqué → checker logs Dokploy UI
# 3. Si TOUT down → restart le dernier container connu working
LAST_GOOD=$(docker ps -a --filter name=<site-alias> --format '{{.Names}}\t{{.Status}}' | grep -v 'Exited (137)\|Exited (1)' | head -1 | awk '{print $1}')
[ -n "$LAST_GOOD" ] && docker start "$LAST_GOOD"

# 4. Sinon rollback via Dokploy UI (Deployments → version N-1 → Redeploy)
```

---

## Postmortem (à remplir après)

- [ ] Quelle était la cause exacte ? (catégorie ci-dessus)
- [ ] Combien de temps pour identifier ? (cible : < 5 min via logs Dokploy)
- [ ] Y avait-il des smoke tests Layer 5 ? Si oui, ont-ils alerté ?
- [ ] **Le bug était-il évitable ?** (ex: var d'env manquante = ajouter check pré-deploy)
- [ ] Action item : si pattern récurrent → automatiser (script pré-deploy, hook CI)
- [ ] Faut-il documenter une nouvelle "cause probable" dans ce runbook ?
