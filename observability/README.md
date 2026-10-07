# Radiank Observability Stack

Self-hosted log aggregation, metrics, and alerting for the Radiank fleet.
Layer 6 of the Q2 2026 resilience strategy.

## Stack

| Component       | Role                          | Port  |
|-----------------|-------------------------------|-------|
| Loki 2.9.8      | Log aggregation               | 3100  |
| Promtail 2.9.8  | Log shipping (per host)       | 9080  |
| Prometheus 2.51 | Metrics collection            | 9090  |
| node_exporter   | Host metrics (CPU/RAM/disk)   | 9100  |
| cAdvisor        | Container metrics             | 8081  |
| Grafana 10.4.3  | Dashboards + alerts           | 3000  |
| Alertmanager    | Alert routing → Up webhook    | 9093  |

## Quick Start

```bash
cp .env.example .env          # Set GRAFANA_ADMIN_PASSWORD + UP_ALERTMANAGER_TOKEN
docker compose up -d
open http://localhost:3000     # admin / <your-password>
```

## Dashboards

Pre-provisioned in Grafana under the "Radiank" folder:

- **Fleet Uptime** — HTTP probe status + p95 latency for all sites
- **TTFB p95** — TTFB breakdown by phase across the fleet
- **example-shop Deep Dive** — example-shop-repo queue, scheduler freshness, container health, error logs
- **Example Fleet** — .com / .de / .fr uptime, TTFB, SSL TTL

## Alerts

6 Prometheus alert rules defined:

| Alert | Condition | Severity |
|-------|-----------|----------|
| `UpRadiankSchedulerStalled` | scheduler age > 10 min | critical |
| `SiteDown` | probe_success == 0 for 2m | critical |
| `example-shopOriginCold` | TTFB > 5s for 5m | warning |
| `ContainerRestarting` | > 3 restarts in 10m | warning |
| `DiskSpaceLow` | < 10% free | warning |
| `MemoryHigh` | > 80% RAM for 5m | warning |
| `LokiIngestionDown` | Loki unreachable for 2m | warning |

Alerts route to `https://up.example.com/api/alerts/prometheus` via bearer token.

## Documentation

- [Setup on Hetzner CX11](docs/setup.md) — full install walkthrough
- [Promtail rollout to existing hosts](docs/promtail-on-existing-hosts.md) — ship logs from each Dokploy server
- [Runbook: stack down](docs/runbook-stack-down.md) — incident response

## Cost

~4€/month (Hetzner CX11). All software open-source, zero licensing fees.

## Architecture Note

This is a single-VPS deployment. The observability stack is itself a SPOF.
Production sites run independently — if obs goes down, you lose visibility but services keep running.
For redundancy, consider Hetzner CX21 with a secondary node exporter sending to a cloud-hosted Grafana Cloud free tier as backup.
