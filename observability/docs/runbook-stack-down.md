# Runbook — Observability Stack Down

Use this when the observability stack (Loki/Grafana/Prometheus) is itself unavailable.

---

## Severity Assessment

| Symptom | Severity | Action |
|---------|----------|--------|
| Grafana unreachable | P2 | Restart grafana service |
| Prometheus scraping stopped | P2 | Check prometheus container + config |
| Loki not ingesting | P2 | Restart loki + promtail |
| All services down | P1 | Full restart procedure below |
| VPS unreachable | P1 | Hetzner console + boot |

The observability stack is a single-VPS SPOF. Production sites are NOT affected when obs is down — monitoring loses visibility, but services keep running.

---

## Quick Diagnosis

```bash
ssh root@<obs-vps-ip>

# Check container states
docker compose -f /opt/up/observability/docker-compose.yml ps

# Recent logs per service
docker compose -f /opt/up/observability/docker-compose.yml logs --tail=50 loki
docker compose -f /opt/up/observability/docker-compose.yml logs --tail=50 prometheus
docker compose -f /opt/up/observability/docker-compose.yml logs --tail=50 grafana
docker compose -f /opt/up/observability/docker-compose.yml logs --tail=50 alertmanager

# Disk space (most common cause)
df -h /
du -sh /var/lib/docker/volumes/observability_*/
```

---

## Scenario 1 — Single Service Crashed

```bash
cd /opt/up/observability

# Restart the affected service
docker compose restart <service>   # loki | prometheus | grafana | alertmanager | promtail

# Verify health
docker compose ps
```

Common causes:
- **Loki**: OOM (CX11 full), corrupt index → increase memory limit or `docker volume rm observability_loki-data` (DESTROYS logs)
- **Prometheus**: Config syntax error → `docker compose exec prometheus promtool check config /etc/prometheus/prometheus.yml`
- **Grafana**: DB corruption → `docker volume rm observability_grafana-data` (DESTROYS dashboards — re-provision from git)

---

## Scenario 2 — Full Stack Restart

```bash
cd /opt/up/observability
docker compose down
docker compose up -d

# Watch convergence
docker compose logs -f --tail=20
```

Expected startup sequence: redis/postgres (N/A) → loki → promtail → prometheus → alertmanager → grafana (~90s total).

---

## Scenario 3 — VPS Unreachable

1. Open [Hetzner Cloud Console](https://console.hetzner.cloud/)
2. Navigate to obs-radiank server
3. Use the built-in web console to check boot errors
4. If kernel panic / OOM: power cycle via console
5. After boot, verify: `docker ps` → all containers should auto-restart (restart: unless-stopped)

---

## Scenario 4 — Disk Full

Loki and Prometheus are the biggest consumers.

```bash
# Emergency: reduce Prometheus retention
docker compose exec prometheus promtool tsdb list /prometheus | tail -5

# Edit .env: PROMETHEUS_RETENTION=7d (from 15d)
# Then reload (no restart)
docker compose restart prometheus

# Loki chunks cleanup (last resort — deletes old logs)
docker compose exec loki sh -c "find /loki/chunks -mtime +7 -delete"
docker compose restart loki

# Nuclear option: wipe and restart (all historical data lost)
docker compose down
docker volume rm observability_loki-data observability_prometheus-data
docker compose up -d
```

---

## Scenario 5 — Alerts Not Firing

```bash
# Check Alertmanager config is valid
docker compose exec alertmanager amtool check-config /etc/alertmanager/alertmanager.yml

# Check active alerts
curl -s http://localhost:9093/api/v1/alerts | jq '.data[] | {name: .labels.alertname, state: .status.state}'

# Test webhook manually
curl -X POST https://up.example.com/api/alerts/prometheus \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"alerts":[{"labels":{"alertname":"TestAlert","severity":"warning"},"status":"firing"}]}'

# Verify Prometheus rules are loaded
curl -s http://localhost:9090/api/v1/rules | jq '.data.groups[].rules[] | {name: .name, state: .state}'
```

---

## Recovery Checklist

- [ ] All 7 containers showing `healthy` in `docker compose ps`
- [ ] Grafana accessible at `http://obs.example.com:3000`
- [ ] Prometheus targets page: all jobs green at `http://localhost:9090/targets`
- [ ] Loki labels endpoint returning data: `curl http://localhost:3100/loki/api/v1/labels`
- [ ] Alertmanager config valid: `amtool check-config`
- [ ] Test alert fired and received by Up webhook

---

## Contacts / Escalation

- Infrastructure owner: your-account@example.com
- Hetzner support: https://console.hetzner.cloud/support
- Stack source: `/opt/up/observability/` (git-managed, changes should be committed)
