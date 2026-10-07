# Game days — Chaos engineering trimestriel

> 1 séance de 2h tous les 3 mois. Simuler des incidents en prod (de manière contrôlée) pour valider que la chaîne de défense (alerting + auto-recovery + runbooks) fonctionne réellement.

---

## Pourquoi

Au Q2 2026, on a vécu **4 incidents en silence** sur 4 semaines :

1. **2026-04-21 → 05-03** : up.example.com workers morts 10 jours, 0 alerte
2. **2026-04-21 → 04-27** : example-shop container crashé 6 jours, masqué par cache CF stale
3. **2026-04** : example.de impressions GSC -82% sans erreur HTTP visible
4. **continu** : example-shop TTFB 14s sur pages froides, invisible au monitoring uptime

**Pattern commun** : les défenses existaient *en théorie* mais personne n'avait jamais vérifié qu'elles s'activaient *en pratique*. Aucun game day n'avait été exécuté.

**Conséquence** : MTTD effectif = jours/semaines au lieu des minutes attendues.

Les game days sont la **seule façon de vérifier** que les systèmes de monitoring/alerting/recovery fonctionnent vraiment, sans attendre une vraie panne pour le découvrir.

---

## Cadence

- **1 game day toutes les 12 semaines** (4/an)
- **Format** : 2h, idéalement en milieu de semaine (mar/mer/jeu) en début d'après-midi
- **Calendrier** : récurrence dans le calendrier Radiank, premier game day = **2026-05-31**
- Si une semaine particulièrement chargée → reporter d'1 semaine, pas plus

---

## Pré-requis (à valider AVANT chaque game day)

| Pré-requis | Comment vérifier | Action si manquant |
|------------|------------------|--------------------|
| **Layer 1 watchdog actif** (Better Stack) | Compte créé, monitors verts sur up.example.com + 3 sites top | Setup avant game day (cf. STRATEGY-RESILIENCE-2026-Q2.md QW1-QW2) |
| **Layer 2 healthchecks enrichis** | `/api/health` retourne JSON détaillé sur chaque app testée | Brancher sur app concernée |
| **Layer 3 restart_policy** | `docker inspect <container> --format '{{.HostConfig.RestartPolicy}}'` montre `unless-stopped` ou `condition: any` | Ajouter dans docker-compose, redéployer |
| **Logs accessibles** | `docker logs <container>` fonctionne, Sentry actif sur l'app | Setup Sentry avant |
| **Runbooks à jour** | Lecture rapide du runbook concerné par scénario | Mettre à jour ou créer le runbook |
| **Backup récent** (si scénario DB) | Dernier backup < 24h, restore testé | Vérifier le backup AVANT de toucher à la DB |
| **Fenêtre de maintenance communiquée** | Notification 24h à l'avance (interne, et users si scénario user-facing) | Repousser si impossible |

**Règle d'or** : si Layer 1 watchdog n'est PAS actif → annuler le game day. Tester du chaos sans observer ne sert à rien.

---

## Format d'un game day

### Avant (J-1)
- 30 min : préparer le scénario (commandes exactes), identifier la fenêtre de maintenance
- Lire le runbook associé
- Préparer un fichier `docs/game-days/YYYY-MM-DD-<scenario>.md` (template ci-dessous)
- Communication : "GAME DAY demain 14h-16h, peut générer alertes Better Stack volontaires"

### Pendant (J jour, ~30 min/scenario)

1. **T0** : noter l'heure exacte de début
2. **Action chaos** : exécuter la commande prévue (kill, block, etc.)
3. **Observer** :
   - Quand l'alerte arrive (Better Stack, Slack, SMS) ? → MTTD
   - Quand l'auto-recovery se déclenche (restart_policy, retry) ? → MTTR auto
   - Si pas de recovery auto : appliquer le runbook, mesurer MTTR humain
4. **Validation** : confirmer retour à l'état nominal
5. **Documenter** dans le fichier game-day

### Après (J+1)
- 30 min : remplir le fichier de game day
- Ouvrir 1 issue par gap découvert
- Mettre à jour les runbooks si une étape manquait

---

## Scénarios initiaux (5)

### Scénario 1 — `kill -9 up-app` (Layer 1 + Layer 3)

**Objectif** : valider que Better Stack alerte en < 5 min, et que `restart_policy` redémarre le container.

**Pré-requis** :
- Better Stack monitor actif sur `up.example.com/api/health` avec polling 1 min
- `restart_policy: condition: any` configuré sur le container

**Action** :
```bash
# Sur le serveur Dokploy
CONTAINER=$(docker ps --filter name=up-app --format '{{.Names}}' | head -1)
echo "T0 = $(date -Iseconds), killing $CONTAINER" | tee -a game-day.log
docker kill -s SIGKILL "$CONTAINER"
```

**Métriques attendues** :
- Auto-restart container : < 30s (Docker restart_policy)
- Alerte Better Stack arrivée : < 2 min (1 polling cycle après le kill)
- `/api/health` revenu vert : < 1 min après restart
- **MTTD cible** : < 2 min
- **MTTR auto cible** : < 1 min (sans intervention humaine)

**Durée prévue** : 15 min (5 prep + 5 exec + 5 doc)

**Si ça échoue** :
- Pas d'auto-restart → bug `restart_policy` ou container en crash-loop. Voir [container-died](../runbooks/runbook-container-died.md)
- Pas d'alerte Better Stack → bug config monitor. Voir [monitoring-frozen](../runbooks/runbook-monitoring-frozen.md)

---

### Scénario 2 — Down du Redis example-shop (Layer 2 + Layer 4)

**Objectif** : valider que `/api/health` retourne 503 quand Redis flanche, et que ça déclenche une alerte.

**Pré-requis** :
- `/api/health` enrichi sur example-shop (sub-check Redis)
- Better Stack alerte sur freshness keyword `"status":"healthy"`

**Action** :
```bash
# Sur le serveur example-shop
REDIS=$(docker ps --filter name=redis --format '{{.Names}}' | head -1)
echo "T0 = $(date -Iseconds), pausing $REDIS for 90s"
docker pause "$REDIS"
sleep 90
docker unpause "$REDIS"
echo "T1 = $(date -Iseconds), Redis back"
```

**Métriques attendues** :
- `/api/health` retourne `unhealthy` dans les 30s : `curl -sf https://example-shop/api/health | jq .checks.redis`
- Alerte Better Stack reçue : < 2 min
- Recovery auto après unpause : < 30s
- **MTTD cible** : < 2 min

**Durée prévue** : 20 min

---

### Scénario 3 — Cloudflare Rocket Loader ON sur example.com (Layer 2 + Layer 6)

**Objectif** : valider que le filtre défensif `data-cfasync="false"` protège GA4 même si quelqu'un réactive RL par erreur.

**Pré-requis** :
- Filtre défensif WP en place (cf. [runbook-cloudflare-rocket-loader.md](../runbooks/runbook-cloudflare-rocket-loader.md) Option 3)
- Tag Assistant Chrome installé sur le poste de test

**Action** :
```bash
# Activer Rocket Loader via API
curl -s -X PATCH "https://api.cloudflare.com/client/v4/zones/$ZONE_ID/settings/rocket_loader" \
  -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{"value":"on"}'

echo "T0 = $(date -Iseconds), RL activé. Tester maintenant."
# 10 min d'observation : GA4 Realtime, Tag Assistant
read -p "Appuyer sur Enter pour désactiver RL"

curl -s -X PATCH "https://api.cloudflare.com/client/v4/zones/$ZONE_ID/settings/rocket_loader" \
  -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{"value":"off"}'
```

**Métriques attendues** :
- HTML servi : scripts critiques **NON** modifiés (filtre `data-cfasync` actif)
- GA4 Realtime continue de recevoir des events
- **Validation principale** : la défense en profondeur tient

**Si ça échoue** : le filtre WP n'est pas appliqué → fix code immédiat puis retest.

**Durée prévue** : 30 min

---

### Scénario 4 — Container example-shop kill (Layer 3 — restart_policy)

**Objectif** : mesurer MTTR avec `restart_policy: any` réel (et constater que Cloudflare cache stale masque l'incident user-facing pendant le restart court).

**Pré-requis** :
- `restart_policy: condition: any` sur app example-shop
- `start_period` >= 360s sur le healthcheck
- Better Stack monitor sur uncached endpoint (`/api/health`)

**Action** :
```bash
CONTAINER=$(docker ps --filter name=example-shop-app --format '{{.Names}}' | head -1)
echo "T0 = $(date -Iseconds)" | tee -a game-day.log
docker stop --time=2 "$CONTAINER"  # SIGTERM puis SIGKILL après 2s
# Observer le restart auto
watch -n 2 'docker ps --filter name=example-shop-app --format "{{.Names}} {{.Status}}"'
```

**Métriques attendues** :
- Container restart : observé en < 30s
- `/api/health` revient vert : < 90s (start_period inclus)
- Better Stack alerte uncached : déclenchée puis auto-resolved en < 5 min
- Pages cachées CF (homepage) : restent up tout le temps (validation du `stale-if-error`)
- **MTTR auto cible** : < 90s

**Durée prévue** : 20 min

---

### Scénario 5 — Faux DNS sur 1 domaine pendant 5 min (Layer 1 + Layer 4)

**Objectif** : simuler une panne DNS provider, vérifier que Better Stack alerte.

**Pré-requis** :
- Better Stack monitor sur ce domaine (DNS check OU HTTP check qui touche le domaine)
- Choisir un domaine **non-critique** ou un sous-domaine de test

**Action** :
```bash
# Option A — modifier l'enregistrement DNS via Cloudflare API (faire pointer vers IP invalide)
RECORD_ID=<id du record A>
ZONE_ID=<zone>
ORIGINAL_IP=<ip réelle, à noter>

# Backup
echo "Backup IP : $ORIGINAL_IP"

# Casser
curl -s -X PUT "https://api.cloudflare.com/client/v4/zones/$ZONE_ID/dns_records/$RECORD_ID" \
  -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{"type":"A","name":"<sous-domaine>","content":"203.0.113.1","ttl":60,"proxied":false}'

echo "T0 = $(date -Iseconds), DNS cassé. Attendre 5 min."
sleep 300

# Restaurer
curl -s -X PUT "https://api.cloudflare.com/client/v4/zones/$ZONE_ID/dns_records/$RECORD_ID" \
  -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" \
  --data "{\"type\":\"A\",\"name\":\"<sous-domaine>\",\"content\":\"$ORIGINAL_IP\",\"ttl\":60,\"proxied\":true}"
```

**Métriques attendues** :
- Better Stack alerte : < 2 min après TTL DNS expiré (≤ 1 min si TTL bas)
- Recovery alerte après restauration : < 2 min
- **MTTD cible** : < 3 min

**Durée prévue** : 30 min (avec attente 5 min de panne)

---

## Template fichier game-day

À créer dans `docs/game-days/YYYY-MM-DD-<scenario-slug>.md` :

```markdown
# Game day — <date> — <scenario>

## Pré-requis vérifiés
- [ ] ...

## Exécution
- T0 (action chaos) : <heure>
- T1 (alerte reçue) : <heure>
- T2 (auto-recovery déclenchée) : <heure>
- T3 (état nominal restauré) : <heure>

## Métriques mesurées
| KPI | Cible | Mesuré | Verdict |
|-----|-------|--------|---------|
| MTTD | < 5 min | ... | ✓ / ✗ |
| MTTR auto | < 1 min | ... | ✓ / ✗ |
| Alerte canal correct | Better Stack | ... | ✓ / ✗ |

## Gaps identifiés
1. ...

## Action items
- [ ] ... (owner: ..., deadline: ...)

## Runbook utilisé
- [link]
- A-t-il aidé ? Améliorations à apporter ?
```

---

## Anti-patterns (game days qui ne servent à rien)

- **Game day sans alerting actif** : on casse, mais personne n'observe
- **Game day prévenu trop largement** : tout le monde "fixe" en mode panique au lieu d'observer
- **Pas de fichier post-game-day** : on apprend rien
- **Toujours le même scénario** : alterner les 5 scénarios + ajouter de nouveaux à mesure que la fleet évolue
- **Casser sans backup** : surtout pour les scénarios DB
