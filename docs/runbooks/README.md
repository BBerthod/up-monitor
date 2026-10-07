# Runbooks — Procédures incident

> Réflexes à froid, pour pouvoir lire **en panique**. Chaque incident vécu = 1 runbook ici.

## Comment l'utiliser quand ça brûle

1. **Identifier le pattern** dans la liste ci-dessous (Symptômes au début de chaque runbook)
2. **Lire la section "Quick Fix"** (en bas de chaque runbook) — c'est la commande la plus sûre
3. Si ça ne suffit pas, **remonter dans la section "Fix"** par ordre de risque croissant
4. **Une fois revenu en stable**, prendre 30 min pour remplir la section Postmortem

## Catalogue

| Runbook | Quand l'utiliser | Sévérité typique | Origine |
|---------|------------------|------------------|---------|
| [monitoring-frozen](./runbook-monitoring-frozen.md) | up.example.com ne checke plus rien | P0 | Incident 2026-04-21 → 05-03 (10j) |
| [container-died](./runbook-container-died.md) | Un container Dokploy a crashé sans restart | P0/P1 | Incident example-shop 2026-04-21 → 04-27 (6j) |
| [cold-cache-perf](./runbook-cold-cache-perf.md) | TTFB lent sur pages non-cachées | P2 | Incident example-shop cold-cache (continu) |
| [impressions-crash](./runbook-impressions-crash.md) | Impressions GSC -30%+ sans erreur HTTP | P1 | Incident example.de 2026-04 (-82%) |
| [cloudflare-rocket-loader](./runbook-cloudflare-rocket-loader.md) | GA4/GTM cassé sur sites CF | P2 | Incident GA4 silencieux |
| [affiliate-evidence-gate](./runbook-affiliate-evidence-gate.md) | Décision SEO/conversion sans données GSC + GA4 + Amazon correctement filtrées | P1 | Faux diagnostics Webcompare/Reviewsite 2026 |
| [dokploy-deploy-failed](./runbook-dokploy-deploy-failed.md) | Déploiement bloqué/échoué | P1/P2 | Generic |
| [database-locked](./runbook-database-locked.md) | Postgres lent / locked / unreachable | P0 | Generic |
| [redis-aof-corrupted](./runbook-redis-aof-corrupted.md) | Redis en crash-loop, AOF corrompu (worker/cache/queue cassés) | P1 | Incident 2026-06-08 (dev local, 1034 restarts) |
| [noisy-inbox](./runbook-noisy-inbox.md) | Inbox illisible, faux positifs qui noient les vrais signaux | P2 (P1 par masquage) | Revue 2026-07-28 (63 items dont ~45 sans signal) |
| [incident-template](./runbook-incident-template.md) | **Template** pour créer un nouveau runbook | — | — |

## Quand créer un nouveau runbook

**Règle** : après chaque incident **P0 ou P1**, créer un runbook dans les **48h** suivant la résolution.

Si un incident similaire à un runbook existant : enrichir le runbook existant plutôt que d'en créer un nouveau.

## Format obligatoire

Tous les runbooks suivent la structure de [`runbook-incident-template.md`](./runbook-incident-template.md) :

```
Symptômes → Impact → Diagnostic (10 min max, read-only)
→ Causes probables (par fréquence)
→ Fix (par ordre de risque)
→ Validation
→ Quick Fix (TLDR pour panique, en bas)
→ Postmortem (à remplir après)
```

## Liens vers la stratégie globale

- [`STRATEGY-RESILIENCE-2026-Q2.md`](../../STRATEGY-RESILIENCE-2026-Q2.md) — vue 7 layers résilience
- [`MEMORY.md` § Lessons Learned](../../MEMORY.md) — postmortems détaillés des 3 incidents historiques
- [`game-days.md`](../game-days.md) — playbook pour exercer ces runbooks en mode game day
