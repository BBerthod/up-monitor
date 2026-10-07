# Promtail Rollout to Existing Dokploy Hosts

Ship container logs from each Dokploy host to the central Loki instance.

This guide also installs `node_exporter` and `cAdvisor` so Prometheus can scrape host and container metrics.

---

## Prerequisites

- SSH access to each host
- Central Loki running at `obs.example.com:3100` (or internal IP)
- Docker installed on the target host

---

## One-Command Install Script

Run on each target host (replace `LOKI_URL` with your instance):

```bash
#!/usr/bin/env bash
# install-obs-agents.sh
# Install Promtail + node_exporter + cAdvisor on a Dokploy host

set -euo pipefail

LOKI_URL="${LOKI_URL:-http://obs.example.com:3100}"
HOST_LABEL="${HOST_LABEL:-$(hostname)}"
PROMTAIL_VERSION="2.9.8"

echo "==> Installing observability agents on $HOST_LABEL"
echo "    Loki target: $LOKI_URL"

# ── node_exporter ──────────────────────────────────────────────
docker run -d \
  --name obs-node-exporter \
  --restart unless-stopped \
  --pid="host" \
  -v "/:/host:ro,rslave" \
  -p 9100:9100 \
  prom/node-exporter:v1.8.0 \
  --path.rootfs=/host \
  --collector.filesystem.mount-points-exclude='^/(sys|proc|dev|host|etc)($$|/)' \
  2>/dev/null || echo "node-exporter already running"

# ── cAdvisor ───────────────────────────────────────────────────
docker run -d \
  --name obs-cadvisor \
  --restart unless-stopped \
  --privileged \
  --device /dev/kmsg \
  -v /:/rootfs:ro \
  -v /var/run:/var/run:ro \
  -v /sys:/sys:ro \
  -v /var/lib/docker:/var/lib/docker:ro \
  -p 8081:8080 \
  gcr.io/cadvisor/cadvisor:v0.49.1 \
  2>/dev/null || echo "cadvisor already running"

# ── Promtail config ────────────────────────────────────────────
mkdir -p /etc/promtail

cat > /etc/promtail/promtail-config.yml << EOF
server:
  http_listen_port: 9080
  grpc_listen_port: 0

positions:
  filename: /tmp/promtail-positions.yaml

clients:
  - url: ${LOKI_URL}/loki/api/v1/push
    batchwait: 1s
    batchsize: 1048576
    timeout: 10s
    backoff_config:
      min_period: 500ms
      max_period: 5m
      max_retries: 10

scrape_configs:
  - job_name: docker-containers
    docker_sd_configs:
      - host: unix:///var/run/docker.sock
        refresh_interval: 5s
        filters:
          - name: status
            values: [running]
    relabel_configs:
      - source_labels: [__meta_docker_container_name]
        regex: /(.*)
        target_label: container
      - source_labels: [__meta_docker_container_label_com_docker_compose_service]
        target_label: service
      - source_labels: [__meta_docker_container_label_com_docker_compose_project]
        target_label: compose_project
      - source_labels: [__meta_docker_container_id]
        target_label: container_id
      - target_label: host
        replacement: ${HOST_LABEL}
    pipeline_stages:
      - json:
          expressions:
            log: log
            stream: stream
            time: time
      - timestamp:
          source: time
          format: RFC3339Nano
      - match:
          selector: '{service=~".+"}'
          stages:
            - json:
                expressions:
                  level: level
                  message: message
                  channel: channel
                source: log
            - labels:
                level:
                channel:
      - output:
          source: log
EOF

# ── Promtail container ─────────────────────────────────────────
docker run -d \
  --name obs-promtail \
  --restart unless-stopped \
  -v /etc/promtail:/etc/promtail:ro \
  -v /var/lib/docker/containers:/var/lib/docker/containers:ro \
  -v /var/run/docker.sock:/var/run/docker.sock:ro \
  -v /var/log:/var/log:ro \
  -v /tmp/promtail-positions:/tmp \
  grafana/promtail:${PROMTAIL_VERSION} \
  -config.file=/etc/promtail/promtail-config.yml \
  2>/dev/null || echo "promtail already running"

echo ""
echo "==> Agents started. Verify:"
echo "    node_exporter : curl http://$(hostname -I | awk '{print $1}'):9100/metrics | head -5"
echo "    cAdvisor      : curl http://$(hostname -I | awk '{print $1}'):8081/healthz"
echo "    Promtail      : curl http://$(hostname -I | awk '{print $1}'):9080/ready"
echo ""
echo "==> Loki labels check (run from obs VPS):"
echo "    curl 'http://obs.example.com:3100/loki/api/v1/labels' | jq '.data[]'"
```

---

## Run the Script

```bash
# On each Dokploy host:
ssh root@<host-ip> "LOKI_URL=http://obs.example.com:3100 HOST_LABEL=<friendly-name> bash -s" < install-obs-agents.sh
```

Example for each known host:

```bash
ssh root@<up-radiank-ip>  "LOKI_URL=http://obs.example.com:3100 HOST_LABEL=up-radiank  bash -s" < install-obs-agents.sh
ssh root@<example-shop-repo-ip>      "LOKI_URL=http://obs.example.com:3100 HOST_LABEL=example-shop-repo       bash -s" < install-obs-agents.sh
ssh root@<example-ip>   "LOKI_URL=http://obs.example.com:3100 HOST_LABEL=example    bash -s" < install-obs-agents.sh
```

---

## Verify Ingestion

After running the script, verify from the obs VPS:

```bash
# Labels should include host names
curl -s 'http://obs.example.com:3100/loki/api/v1/labels' | jq '.data[]'

# Query recent logs from a specific host
curl -sG 'http://obs.example.com:3100/loki/api/v1/query_range' \
  --data-urlencode 'query={host="up-radiank"}' \
  --data-urlencode 'limit=5' \
  --data-urlencode "start=$(date -d '5 minutes ago' +%s)000000000" \
  --data-urlencode "end=$(date +%s)000000000" \
  | jq '.data.result[].stream'
```

---

## Prometheus Scrape Config Update

Once agents are running, add each host to `prometheus/prometheus.yml` under the appropriate job:

```yaml
- job_name: node-exporter-<host-label>
  static_configs:
    - targets: ["<host-ip>:9100"]
      labels:
        host: <host-label>
```

Then reload Prometheus:

```bash
curl -X POST http://obs.example.com:9090/-/reload
```

---

## Firewall Considerations

The obs VPS only needs **inbound** access on:
- `:3000` — Grafana (restrict to your IP or put behind Caddy)
- `:9090` — Prometheus (internal use only, do not expose publicly)
- `:3100` — Loki (must be reachable from all Dokploy hosts)
- `:9093` — Alertmanager (internal)

Each Dokploy host needs **inbound** access from the obs VPS on:
- `:9100` — node_exporter (Prometheus scrape)
- `:8081` — cAdvisor (Prometheus scrape)

Configure Hetzner firewall rules accordingly.
