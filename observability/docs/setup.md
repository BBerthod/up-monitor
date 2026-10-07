# Setup — Self-Hosted Observability Stack on Hetzner CX11

Estimated time: 30 minutes. Cost: ~4€/month.

---

## 1. Provision VPS (Hetzner CX11)

Via Hetzner Cloud console or API:

```bash
# API example (requires HCLOUD_TOKEN env var)
curl -X POST https://api.hetzner.cloud/v1/servers \
  -H "Authorization: Bearer $HCLOUD_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "obs-radiank",
    "server_type": "cx11",
    "image": "ubuntu-22.04",
    "location": "nbg1",
    "ssh_keys": ["<your-ssh-key-name>"]
  }'
```

Specs: 2 vCPU / 4 GB RAM / 20 GB NVMe / 20 TB traffic. IPv4 + IPv6 included.

---

## 2. Install Docker

```bash
ssh root@<VPS_IP>

# Install Docker (official method)
curl -fsSL https://get.docker.com | sh

# Add current user to docker group (if not root)
usermod -aG docker $USER

# Verify
docker --version && docker compose version
```

---

## 3. Configure DNS

In Cloudflare (or your DNS provider):

```
obs.example.com  A  <VPS_IP>  (Proxied: OFF — direct IP needed for Grafana/Prometheus)
```

Wait for DNS propagation (~2 min with Cloudflare).

---

## 4. Deploy the Stack

```bash
# Clone the repo
git clone https://github.com/BBerthod/up-monitor.git /opt/up
cd /opt/up/observability

# Configure environment
cp .env.example .env
nano .env   # Set GRAFANA_ADMIN_PASSWORD and UP_ALERTMANAGER_TOKEN

# Create the Alertmanager token file (bearer auth)
echo -n "$UP_ALERTMANAGER_TOKEN" > alertmanager/webhook-token
chmod 600 alertmanager/webhook-token

# Start everything
docker compose up -d

# Watch startup logs
docker compose logs -f --tail=50
```

Expected startup time: ~60s for Grafana (Prometheus and Loki start first).

---

## 5. Verify Ingestion

```bash
# Prometheus: check all targets are UP
curl -s http://obs.example.com:9090/api/v1/targets | jq '.data.activeTargets[] | {job: .labels.job, health: .health}'

# Loki: check labels are being ingested
curl -s 'http://obs.example.com:3100/loki/api/v1/labels' | jq '.data[]'

# Grafana: health
curl -s http://obs.example.com:3000/api/health | jq .
```

---

## 6. Open Grafana

Navigate to `http://obs.example.com:3000`.

1. Login with `admin` / `<GRAFANA_ADMIN_PASSWORD>`
2. Go to Dashboards — you should see 4 pre-provisioned dashboards in the "Radiank" folder:
   - Fleet Uptime
   - TTFB p95
   - example-shop Deep Dive
   - Example Fleet

---

## 7. Configure Alertmanager Token

The Alertmanager sends alerts to `https://up.example.com/api/alerts/prometheus`.

The endpoint does not exist yet — you need to create it in the Up application (a simple webhook receiver that creates incidents/notifications). Until then, alerts will fail gracefully (logged by Alertmanager).

To create a bearer token:
1. Open `https://up.example.com/settings`
2. Create an API token labeled "alertmanager"
3. Update `observability/.env` with `UP_ALERTMANAGER_TOKEN=<token>`
4. Update `alertmanager/webhook-token`: `echo -n "<token>" > alertmanager/webhook-token`
5. `docker compose restart alertmanager`

---

## 8. Add Caddy for HTTPS + Basic Auth (optional but recommended)

Expose Grafana over HTTPS with Caddy as a reverse proxy:

```bash
# Install Caddy
apt install -y caddy

# /etc/caddy/Caddyfile
cat > /etc/caddy/Caddyfile << 'EOF'
obs.example.com {
  reverse_proxy localhost:3000
  basicauth /* {
    # Generate: caddy hash-password --plaintext <password>
    admin <bcrypt-hash>
  }
}
EOF

systemctl enable --now caddy
```

After this, Grafana is at `https://obs.example.com` with TLS from Let's Encrypt (auto).

---

## 9. Prometheus Remote Hosts

By default the scrape config targets `${UP_RADIANK_HOST}:9100` (node_exporter) and `:8081` (cAdvisor). These must be running on each production host. See `docs/promtail-on-existing-hosts.md` for the rollout script.

---

## Maintenance

```bash
# Update all images
docker compose pull && docker compose up -d

# View Prometheus alerts
curl -s http://localhost:9093/api/v1/alerts | jq '.data[]'

# Force Prometheus config reload (no restart needed)
curl -X POST http://localhost:9090/-/reload

# Disk usage
docker system df
du -sh /var/lib/docker/volumes/observability_*
```

---

## Estimated Resource Usage (idle, CX11)

| Service         | RAM     | CPU      |
|-----------------|---------|----------|
| Loki            | ~120 MB | < 2%     |
| Prometheus      | ~180 MB | < 3%     |
| Grafana         | ~150 MB | < 1%     |
| Alertmanager    | ~20 MB  | < 0.5%   |
| cAdvisor        | ~60 MB  | < 1%     |
| node_exporter   | ~15 MB  | < 0.5%   |
| Promtail        | ~40 MB  | < 1%     |
| **Total**       | ~585 MB | < 10%    |

Leaves ~3.4 GB free on a CX11 (4 GB RAM). Sufficient for 6-12 months of growth.
