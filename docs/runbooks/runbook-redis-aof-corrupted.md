# Runbook: Redis AOF corrompu (crash-loop au chargement)

> **Si tu lis ça en pleine panne** : saute à [Quick Fix](#quick-fix-tldr-pour-panique).

> **Basé sur l'incident 2026-06-08 (Docker dev local, `up-redis` en crash-loop, 1034 restarts avant détection).**

---

## Symptômes

- `docker compose ps` montre `up-redis` en `Restarting`, et `up-worker` + `up-lighthouse-worker` aussi en `Restarting`.
- Logs worker : `In PhpRedisConnector.php line 181: php_network_getaddresses: getaddrinfo for redis failed: Try again`
- Logs redis : `# Bad file format reading the append only file appendonly.aof.3.incr.aof: make a backup of your AOF file, then use ./redis-check-aof --fix`
- `docker inspect up-redis --format '{{.RestartCount}}'` → compteur très élevé (1034 dans le cas réel).
- **Le "tell"** : le container `app` reste *healthy*, mais tout ce qui dépend de la queue / cache / session dégrade en silence (stats cache warming à 0% hit / timeouts, etc.). L'app a l'air vivante, le reste s'effondre.

**Distinguer de** :
- [container-died](./runbook-container-died.md) : là le container est totalement **mort / ne redémarre pas**. Ici il **crash-LOOPE** au chargement de l'AOF (il redémarre, recharge, recrash).
- [database-locked](./runbook-database-locked.md) : ça c'est **Postgres**, pas Redis.

---

## Impact

- **Services affectés** : queue (warming, checks, notifications, lighthouse), cache, sessions, broadcasts Reverb — tout est backé par Redis → tout dégrade.
- **Cascade** : les workers crashent en chaîne parce qu'ils n'arrivent plus à résoudre / se connecter au container redis qui flappe.
- **User-facing ou interne** : le container `app` reste healthy → pas forcément visible en façade tout de suite, mais queue/sessions cassées.
- **Sévérité** : **P1**. P0 si c'est l'instance prod `up.example.com` et que le front tombe.
- **⚠️ Toujours vérifier QUELLE instance** : le 2026-06-08 c'est arrivé sur le **Docker dev local uniquement** — prod `up.example.com` était healthy.

---

## Diagnostic (10 min max)

> Objectif : confirmer le crash-loop AOF, **pas** réparer. Read-only.

1. **État des containers** :
   ```bash
   docker compose ps --format "{{.Name}} {{.Status}}"
   ```
   Ce qu'on cherche : `Restarting` sur redis/worker. Si c'est ça, c'est un crash-loop, pas un container mort.

2. **Logs redis** :
   ```bash
   docker compose logs redis --tail 20
   ```
   Chercher : `Bad file format reading the append only file appendonly.aof.*.incr.aof`. → AOF corrompu confirmé.

3. **Logs worker** :
   ```bash
   docker compose logs worker --tail 10
   ```
   Chercher : `getaddrinfo for redis failed`. → les workers ne joignent plus le redis qui flappe.

4. **Compteur de restarts** :
   ```bash
   docker inspect up-redis --format '{{.RestartCount}}'
   ```
   Un compteur élevé (centaines+) confirme un **crash loop**, pas un incident isolé.

---

## Causes probables

1. **Tail de l'AOF incrémental tronqué/corrompu** (quasi 100% ici) → fichier `appendonlydir/appendonly.aof.N.incr.aof` avec une fin corrompue, généralement suite à un **shutdown sale** ou une interruption disque/host **en plein milieu d'une écriture**. Redis refuse de finir le chargement → crash → restart → re-crash. Dans le cas réel, seuls les **~990 derniers octets** d'un fichier de **8.75 Mo** étaient corrompus.

---

## Fix (par ordre de risque)

> ⚠️ Le volume est `up_redisdata`. Comme le container est en **restart loop**, tu ne peux PAS `docker compose exec` dedans — il faut d'abord **l'arrêter** et travailler sur le volume via un container jetable.
>
> Aligne le tag de l'image redis (`redis:7.4-alpine`) sur la version redis du projet.

### Option 1 — Réparation non-destructive (PRÉFÉRÉE)

- Quand : par défaut. On veut garder un max de données.
- Risque : **minimal** — seuls les octets corrompus sont droppés (le tail tronqué = les dernières écritures avant le crash, en général quelques clés cache/session).

```bash
# 1. Stopper le crash loop (libère le volume)
docker compose stop redis worker lighthouse-worker

# 2. Backup du volume ENTIER d'abord (mount read-only) — ne JAMAIS sauter
mkdir -p .tmp-redis-backup
docker run --rm -v "up_redisdata:/data:ro" -v "$(pwd)/.tmp-redis-backup:/backup" alpine \
  sh -c 'cd /data && tar czf /backup/up_redisdata-$(date +%Y%m%d-%H%M).tar.gz .'

# 3. Diagnostiquer (pas encore de fix) — montre ok_up_to vs size
docker run --rm -v "up_redisdata:/data" redis:7.4-alpine \
  redis-check-aof /data/appendonlydir/appendonly.aof.manifest

# 4. Réparer — tronque le tail corrompu, garde le reste
docker run --rm -v "up_redisdata:/data" redis:7.4-alpine \
  sh -c 'echo y | redis-check-aof --fix /data/appendonlydir/appendonly.aof.manifest'

# 5. Re-vérifier que c'est entièrement valide (diff=0, "All AOF files and manifest are valid")
docker run --rm -v "up_redisdata:/data" redis:7.4-alpine \
  redis-check-aof /data/appendonlydir/appendonly.aof.manifest

# 6. Redémarrer Redis puis les workers
docker compose up -d redis && sleep 6 && docker compose up -d worker lighthouse-worker
```

### Option 2 — Destructif (fallback plus rapide si `--fix` n'arrive pas à récupérer)

- Quand : Option 1 échoue à réparer l'AOF.
- Risque : perd le backlog de queue + cache + sessions. Cache/sessions se régénèrent ; les jobs en queue en vol sont **perdus** ; les données métier sont dans **PostgreSQL** → donc safe.
- Action : après le **même backup** qu'en Option 1, supprimer l'AOF corrompu et laisser Redis repartir à neuf.

```bash
docker compose stop redis
docker run --rm -v "up_redisdata:/data" alpine rm -rf /data/appendonlydir /data/dump.rdb
docker compose up -d redis worker lighthouse-worker
```

---

## Validation

- [ ] `docker compose ps` → redis/worker `Up ... (healthy)`, plus aucun `Restarting`.
- [ ] `docker compose exec -T redis redis-cli PING` → `PONG`
- [ ] `docker compose exec -T redis redis-cli INFO persistence | grep -E "loading:|aof_last_write_status"` → `loading:0` + `aof_last_write_status:ok`
- [ ] Si prod : `curl -sf https://up.example.com/up` → 200, et `/login` charge (sessions OK).

---

## Quick Fix (TLDR pour panique)

```bash
# Stopper le crash loop, backup + réparer, relancer
docker compose stop redis worker

mkdir -p .tmp-redis-backup
docker run --rm -v "up_redisdata:/data:ro" -v "$(pwd)/.tmp-redis-backup:/backup" alpine \
  sh -c 'cd /data && tar czf /backup/up_redisdata-$(date +%Y%m%d-%H%M).tar.gz .'
docker run --rm -v "up_redisdata:/data" redis:7.4-alpine \
  sh -c 'echo y | redis-check-aof --fix /data/appendonlydir/appendonly.aof.manifest'

docker compose up -d redis worker
```

Si `--fix` ne récupère rien → Option 2 (destructif) ci-dessus.

---

## Postmortem (à remplir après)

- [ ] **Root cause** : tail de l'AOF incr corrompu (shutdown sale). Le 2026-06-08 : **1034 restarts avant détection** → le crash loop tournait silencieusement depuis longtemps parce que **rien n'alerte sur un restart-loop redis** tant que le container `app` reste healthy.
- [ ] **Detection delay** : delta entre début du crash loop et détection. Ici énorme (compteur à 1034) → à corriger.
- [ ] **Action item candidat** : **alerter sur un `RestartCount` de container qui grimpe** (pas seulement sur le health de l'app).
- [ ] **Backup** : le tarball vit dans `.tmp-redis-backup/` (gitignored) — le garder le temps de confirmer le recovery.
- [ ] **Quelle instance** : confirmer si c'était dev local ou prod (le 2026-06-08 = dev local, prod saine).
- [ ] **5 whys** : creuser pourquoi le shutdown a été sale (host interrompu ? disque ? OOM ?).

Stocker le postmortem dans `docs/postmortems/YYYY-MM-DD-redis-aof-corrupted.md`.
