# Runbook: <nom court de l'incident>

> **Si tu lis ça en pleine panne** : saute directement à la section [Quick Fix](#quick-fix-tldr-pour-panique) en bas, puis reviens ici à froid.

> Template officiel pour tout nouveau runbook. Copie-le, renomme-le `runbook-<slug>.md`, remplace les sections.
> Règle : **après chaque incident P0/P1, créer un runbook dans les 48h** (cf. `CONTRIBUTING.md`).

---

## Symptômes (comment tu sais que c'est ça)

- Symptôme 1 visible côté user / monitoring
- Symptôme 2 visible dans logs / dashboards
- Symptôme 3 (signal moins évident, "tell" du pattern)

**Distinguer de** : runbook X ou Y qui ressemble (lien interne).

---

## Impact

- **Sites/services affectés** : liste explicite
- **User-facing ou interne** ? (front public, admin, worker silencieux ?)
- **Sévérité par défaut** : P0 / P1 / P2
- **SLA business** : combien de temps avant que ça se voie (revenue, SEO, conversion) ?

---

## Diagnostic (10 min max)

> Objectif : confirmer/infirmer le diagnostic, **pas** réparer. Read-only.

1. **Commande 1** :
   ```bash
   <commande exacte copy-paste>
   ```
   Ce qu'on cherche : `<pattern attendu>`. Si on voit `<pattern problème>`, c'est confirmé.

2. **Commande 2** :
   ```bash
   <commande>
   ```
   Interprétation : ...

3. **Commande 3** : ...

---

## Causes probables (par fréquence)

1. **Cause A** (60% des cas) → fix A (voir Option 1)
2. **Cause B** (30% des cas) → fix B (voir Option 2)
3. **Cause C** (10% des cas) → fix C (voir Option 3)

---

## Fix (par ordre de risque, du moins risqué au plus)

### Option 1 — Reload (zero downtime, < 1 min)
- Quand : ...
- Risque : nul
- Commande :
  ```bash
  <reload command>
  ```

### Option 2 — Restart container (< 30s downtime)
- Quand : Option 1 inefficace
- Risque : 30s d'erreurs 502 si pas de fallback CF
- Commande :
  ```bash
  <restart command>
  ```

### Option 3 — Redeploy (3-5 min, risque régression)
- Quand : la version courante est suspectée corrompue
- Risque : peut introduire un bug si la nouvelle build est mauvaise
- Commande :
  ```bash
  <redeploy command>
  ```

### Option 4 — Rollback (1 min mais perte de données récentes possible)
- Quand : la dernière release a introduit le bug
- Risque : pertes de données écrites depuis le déploiement
- Commande :
  ```bash
  <rollback command>
  ```

---

## Validation (comment confirmer recovery)

- [ ] `curl -sf https://<domain>/api/health` → 200 + `"status":"healthy"`
- [ ] Logs container : plus d'erreur depuis X min
- [ ] Better Stack / synthetic monitor : revenu vert
- [ ] Smoke test métier : ...
- [ ] Métrique business clé revenue à baseline

---

## Quick Fix (TLDR pour panique)

```bash
# La commande à coller en premier — la plus sûre, restore en < 30s
<la commande>
```

Si ça ne fix pas, passer à `Option 2` ci-dessus.

---

## Postmortem (à remplir après)

- [ ] **Timeline** : quand le bug est arrivé, quand il a été détecté, quand il a été fixé.
- [ ] **Root cause** : la cause technique réelle (pas le symptôme).
- [ ] **Detection delay** : delta entre incident et alerte. Si > 5 min sur un P0, ouvrir une issue "améliorer monitoring".
- [ ] **Resolution time** : MTTR.
- [ ] **5 whys** : creuser jusqu'à la cause systémique.
- [ ] **Patterns à transformer en règles fleet ?** (si oui, ajouter dans `MEMORY.md` § Patterns to apply across the fleet)
- [ ] **Action items SMART** : 1-3 max, avec owner et deadline.
- [ ] **Doit-on créer/mettre à jour un runbook ?** (le présent runbook a-t-il aidé ?)

Stocker le postmortem dans `docs/postmortems/YYYY-MM-DD-<slug>.md`.
