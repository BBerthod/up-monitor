# Contributing — Up

> Up est un projet open-source d'uptime monitoring. Ce guide couvre le workflow de contribution + les règles de qualité.

## TL;DR

```bash
# 1. Clone + branch from main
git clone <repo> && cd up
git checkout -b feature/<description>     # ou fix/, refactor/, docs/, etc.

# 2. Install + run dev
composer install && npm install
composer dev                              # lance app + worker + scheduler + reverb + vite

# 3. Code + test + lint
composer test
./vendor/bin/pint

# 4. Commit (atomic) + push
git commit -m "feat(monitor): add DNS record validation."
git push -u origin feature/<description>

# 5. PR avec template auto-rempli + review
gh pr create
```

---

## Workflow git

### Branches

| Type | Pattern | Cible PR |
|------|---------|----------|
| Feature | `feature/<description>` | `main` |
| Hotfix | `hotfix/<description>` | `main` |
| Bugfix | `fix/<description>` | `main` |
| Refactor | `refactor/<description>` | `main` |
| Docs | `docs/<description>` | `main` |
| Tests | `test/<description>` | `main` |

**Jamais de commit direct sur `main`.** Le repo bloque les pushes directs.

### Commits atomiques

**1 purpose = 1 commit.** Si le message a besoin de "and", c'est qu'il y a 2 commits à faire.

| Situation | # commits |
|-----------|----------|
| Migration + code l'utilisant | 2 (migration d'abord) |
| Feature + ses tests | 1 |
| Bug fix + formatting non lié | 2 |
| Refactor 10 fichiers, même raison | 1 |
| Fix + config requise par le fix | 1 |
| Plusieurs bug fixes indépendants | 1 par fix |

### Format de message

```
<type>(<scope>): <description>.
<type>(<scope>): (#<issue>) <description>.
```

- Présent impératif : "add feature" pas "added feature"
- Terminer par un point
- Types : `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `ci`

Exemples :
- `feat(monitor): add DNS record validation.`
- `fix(scheduler): prevent silent worker death (#20).`
- `docs(runbooks): add 8 runbooks for known incident types.`

---

## Code style

### PHP / Laravel

- **PHP 8.4** — typed properties, match expressions, named arguments
- **PSR-12** via Pint : `./vendor/bin/pint` avant chaque commit
- **Service pattern** : business logic dans `app/Services/`, jamais dans Controllers
- **DI** : injection via constructor, jamais `app()->make()` dans les méthodes
- **Eloquent** : query scopes pour la logique réutilisable, eager loading (`with()`) systématique
- **Migrations** : test rollback obligatoire avant commit
- **Détails** : voir `~/.claude/rules/laravel.md` (si tu utilises Claude Code)

### Vue / Frontend

- Composition API uniquement
- TypeScript pour les nouveaux fichiers
- Props typing avec `defineProps<T>()`
- Cleanup dans `onUnmounted()` pour les listeners

### Sécurité (repo public — règles strictes)

**Avant chaque commit, vérifier** :
- [ ] Pas d'email réel, pas d'IP / domaine privé
- [ ] Pas de secret, token, credential
- [ ] Tests / seeders : `@example.com`, `fake()`, `https://example.com` uniquement
- [ ] Config : `env('KEY')`, jamais hardcodé
- [ ] Cf. `CLAUDE.md` § Public Repo Security Rules

---

## Tests

```bash
composer test                # PHPUnit + Pest, full suite
php artisan test --parallel  # plus rapide
```

Règle : **un fix de bug doit ajouter un test qui reproduisait le bug**.

---

## Avant chaque PR (checklist intégrée au template)

Le `.github/PULL_REQUEST_TEMPLATE.md` contient une **Resilience checklist** (Layers 2-7). Lire et cocher.

Points critiques :
- Si nouveau worker / scheduler : ajouté dans `docker/supervisor/app.conf` (PAS suffisant dans docker-compose, cf. `MEMORY.md` Failure 1)
- Si touche `/api/health` : tester la réponse 503 quand un sous-système flanche
- Si nouveau Dockerfile : `start_period` > temps de boot/migration
- Si nouveau public endpoint : smoke test
- Si nouveau site fleet : déclaré dans `~/.claude/sites/*.yml`

---

## Documentation

### Quand mettre à jour `MEMORY.md`

- Une convention nouvellement découverte (ex: "Always do X because Y")
- Une décision d'architecture (ex: "Use Redis for caching, not Memcached, because...")
- Un piège identifié (ex: "Dokploy ignores docker-compose.yml")
- Un postmortem d'incident P0/P1

### Quand créer un runbook

**Règle stricte** : après chaque incident **P0 ou P1**, créer un runbook dans `docs/runbooks/` dans les **48h** suivant la résolution.

Format : copier `docs/runbooks/runbook-incident-template.md`, remplir les 7 sections (Symptômes → Postmortem).

Si l'incident ressemble à un runbook existant : enrichir l'existant plutôt que d'en créer un nouveau.

### Quand ajouter une lesson learned dans `MEMORY.md`

À la fin du postmortem (48h après l'incident), ajouter une entrée dans `MEMORY.md § Lessons Learned — Incident Postmortems` avec :
- Date, durée, impact
- Cause racine
- Long-term rule à appliquer fleet-wide

### Quand mettre à jour `docs/runbooks/README.md`

À chaque nouveau runbook créé, ajouter une ligne dans le catalogue.

---

## Game days

Cf. [`docs/game-days.md`](./docs/game-days.md). 1 game day par trimestre minimum.

Si tu participes à un game day, remplir un fichier `docs/game-days/YYYY-MM-DD-<scenario>.md` après.

---

## Process de release

Pour l'instant : pas de release versionnée formelle. Chaque merge sur `main` = potentiel deploy.

Quand on passera à un cycle release :
1. Branche `release/v<version>`
2. Bumper `composer.json` + `package.json` versions
3. Mettre à jour `CHANGELOG.md`
4. Tag git : `git tag v<version> && git push --tags`

---

## Liens utiles

- [`STRATEGY-RESILIENCE-2026-Q2.md`](./STRATEGY-RESILIENCE-2026-Q2.md) — vision résilience 7 layers
- [`MEMORY.md`](./MEMORY.md) — décisions architecture + postmortems
- [`docs/runbooks/`](./docs/runbooks/) — procédures incident
- [`docs/game-days.md`](./docs/game-days.md) — chaos engineering trimestriel
- [`CLAUDE.md`](./CLAUDE.md) — config Claude Code (si tu utilises)
