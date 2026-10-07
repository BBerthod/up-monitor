# Runbook: database locked / unreachable / lent

> **Si tu lis ça en pleine panne** : saute à [Quick Fix](#quick-fix-tldr-pour-panique).

> Generic — couvre les pannes Postgres : connexion impossible, deadlock, lock table, full disk, slow query.

---

## Symptômes

- App répond 500 / 503 sur la majorité des requêtes
- Logs : `SQLSTATE[08006]`, `Connection refused`, `too many connections`, `deadlock detected`, `relation does not exist`
- `/api/health` : `checks.db.status: "fail"` ou latency > 1000ms
- Sentry : pic d'exceptions `Doctrine\DBAL\Exception` ou `PDOException`
- Migration en cours qui ne termine pas

**Distinguer de** :
- Si Postgres tourne mais lent → c'est plus [cold-cache-perf](./runbook-cold-cache-perf.md)
- Si seul Redis est down → erreur "Connection refused" sur port 6379, pas 5432

---

## Impact

- **Sites/services affectés** : tous les services pointant vers cette DB. Souvent multiple sites en architecture mutualisée.
- **User-facing** : oui, immédiatement (500 errors)
- **Sévérité** : P0

---

## Diagnostic (10 min max)

1. **Le container Postgres tourne-t-il ?**
   ```bash
   docker ps --filter name=postgres
   # Doit montrer "Up X minutes" et port 5432
   ```
   Si absent → voir [container-died](./runbook-container-died.md) sur le service postgres.

2. **Postgres répond-il ?**
   ```bash
   docker exec <postgres_container> pg_isready -U <db_user>
   # "accepting connections" = OK
   ```

3. **Connections saturées ?**
   ```bash
   docker exec <postgres_container> psql -U <user> -c "SELECT count(*), state FROM pg_stat_activity GROUP BY state;"
   ```
   Si `idle in transaction` > 50 → fuite de connexions / transactions non-commit.

4. **Locks bloquantes ?**
   ```bash
   docker exec <postgres_container> psql -U <user> -c "
   SELECT blocked_locks.pid AS blocked_pid,
          blocked_activity.usename AS blocked_user,
          blocking_activity.usename AS blocking_user,
          blocked_activity.query AS blocked_query,
          blocking_activity.query AS blocking_query
   FROM pg_catalog.pg_locks blocked_locks
   JOIN pg_catalog.pg_stat_activity blocked_activity ON blocked_activity.pid = blocked_locks.pid
   JOIN pg_catalog.pg_locks blocking_locks ON blocking_locks.locktype = blocked_locks.locktype
   JOIN pg_catalog.pg_stat_activity blocking_activity ON blocking_activity.pid = blocking_locks.pid
   WHERE NOT blocked_locks.granted AND blocking_locks.granted;"
   ```

5. **Disque plein** ?
   ```bash
   df -h
   docker exec <postgres_container> du -sh /var/lib/postgresql/data
   ```

6. **Migration bloquée** ?
   ```bash
   docker exec <postgres_container> psql -U <user> -c "
   SELECT pid, now() - pg_stat_activity.query_start AS duration, state, query
   FROM pg_stat_activity
   WHERE state != 'idle'
   ORDER BY duration DESC LIMIT 5;"
   ```
   Si une query tourne depuis > 5 min → probable lock.

---

## Causes probables

1. **Migration trop longue qui lock une table** (25%) → cf. PR #71 example-shop-repo
2. **Connections leak (`idle in transaction`)** (20%) → app ne ferme pas ses transactions
3. **Disque host plein** (15%) → WAL files accumulés, logs, dump
4. **Container Postgres crashé** (15%) → voir [container-died](./runbook-container-died.md)
5. **Deadlock applicatif** (10%) → 2 transactions s'attendent mutuellement
6. **Connection pool max atteint** (10%) → augmenter `max_connections` ou ajouter PgBouncer
7. **OOM kill du container** (5%) → augmenter `mem_limit`

---

## Fix (par ordre de risque)

### Option 1 — Killer une query bloquante (zero downtime, 0 risque pour les autres requêtes)
```bash
# 1. Identifier le PID via diag #4
# 2. Kill cette query
docker exec <postgres_container> psql -U <user> -c "SELECT pg_cancel_backend(<pid>);"
# Si pg_cancel ne suffit pas (query résistante) :
docker exec <postgres_container> psql -U <user> -c "SELECT pg_terminate_backend(<pid>);"
```
- Quand : une query identifiée bloque tout
- Risque : la transaction est rollback, peut perdre du travail récent

### Option 2 — Restart le service applicatif (libère les connexions leak)
```bash
docker restart <app_container>
```
- Quand : connections `idle in transaction` accumulées
- Risque : 30s downtime app

### Option 3 — Restart Postgres (downtime ~30s, risque WAL replay)
```bash
docker restart <postgres_container>
sleep 20
docker exec <postgres_container> pg_isready -U <user>
```
- Quand : Postgres frozen, ne répond plus à pg_isready
- Risque : downtime + recovery WAL si crash sale

### Option 4 — Free disque (si full)
```bash
# Identifier les gros consommateurs
docker exec <postgres_container> du -sh /var/lib/postgresql/data/* | sort -h | tail
# Vacuum agressif
docker exec <postgres_container> psql -U <user> -c "VACUUM FULL;"   # ATTENTION: lock toutes les tables
# Mieux : VACUUM ANALYZE par table
docker exec <postgres_container> psql -U <user> -c "VACUUM ANALYZE;"
```

### Option 5 — Restore depuis backup
- Quand : corruption DB, perte d'intégrité
- Risque : pertes de données depuis le dernier backup
- Action : voir le runbook backup spécifique au site (à créer site-par-site)

---

## Validation

- [ ] `docker exec <postgres> pg_isready` → "accepting connections"
- [ ] `curl -sf https://<domain>/api/health | jq .checks.db` → `status: ok`, latency < 50ms
- [ ] `pg_stat_activity` : pas de query > 1 min en `active`
- [ ] App : pas d'erreur SQL dans les logs depuis 5 min
- [ ] Sentry : taux d'erreur DB retombé à zéro
- [ ] Better Stack : monitor revenu vert

---

## Quick Fix (TLDR pour panique)

```bash
# Sur le serveur DB
PG_CONTAINER=$(docker ps --filter name=postgres --format '{{.Names}}' | head -1)
DB_USER=<your-db-user>

# 1. Postgres répond ?
docker exec "$PG_CONTAINER" pg_isready -U "$DB_USER"

# 2. Si pas répond → restart
docker restart "$PG_CONTAINER"
sleep 20
docker exec "$PG_CONTAINER" pg_isready -U "$DB_USER"

# 3. Si répond mais lent → checker queries longues + kill
docker exec "$PG_CONTAINER" psql -U "$DB_USER" -c "
  SELECT pid, now() - query_start AS dur, query
  FROM pg_stat_activity
  WHERE state = 'active' AND now() - query_start > interval '30 seconds'
  ORDER BY dur DESC;"
```

---

## Postmortem (à remplir après)

- [ ] Cause exacte (lock / leak / disk / OOM ?)
- [ ] Impact : combien de requêtes ratées ? Combien de users impactés ?
- [ ] Detection delay : Better Stack a-t-il alerté en < 5 min ? `/api/health` retournait-il 503 ?
- [ ] Resolution : MTTR
- [ ] **Action items** :
  - Ajouter monitoring `pg_stat_activity` count dans `/api/health` ?
  - PgBouncer si connections leak récurrent ?
  - Backup automatisé fonctionnel ? Tester restore en game day.
  - Alertes Disk > 80% sur le host ?
- [ ] Faut-il créer un runbook plus spécifique (ex: `runbook-postgres-deadlock.md`) ?
