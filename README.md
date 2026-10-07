<p align="center">
  <img src="public/icons/icon-192.png" alt="Up" width="80" />
</p>

<h1 align="center">Up</h1>

<p align="center">
  <strong>Open-source uptime monitoring</strong><br>
  Self-hosted alternative to Uptime Robot, Pingdom, and Better Uptime.
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#quick-start">Quick Start</a> •
  <a href="#configuration">Configuration</a> •
  <a href="#cli">CLI</a> •
  <a href="#api">API</a> •
  <a href="#license">License</a>
</p>

---

## Features

- **HTTP/HTTPS Monitoring** — GET, POST, HEAD with expected status codes and keyword detection
- **SSL Certificate Monitoring** — Track expiry dates automatically
- **Response Time Alerting** — Warning and critical thresholds with consecutive check logic
- **Real-time Dashboard** — WebSocket-powered live updates via Laravel Reverb
- **Public Status Pages** — Customizable public pages with 90-day uptime bars
- **Notification Channels** — Email, Webhook, Slack, Discord, Push notifications
- **REST API** — Full CRUD API with Sanctum authentication
- **CLI Companion** — `up list`, `up add`, `up status` from your terminal
- **PWA** — Install as app on mobile, receive push notifications
- **Embeddable Badges** — shields.io-style SVG badges for your README
- **Latency Heatmap** — GitHub-style 12-month response time visualization
- **Lighthouse Audits** — Daily performance, accessibility, best practices, SEO scores
- **Multi-team** — Team-based data isolation with member management
- **Dark Mode** — Glassmorphism UI with navy + cyan design

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Backend | Laravel 12 (PHP 8.4) |
| Frontend | Vue 3 + Inertia.js v2 |
| Database | PostgreSQL 16 |
| Cache & Queues | Redis 7 |
| WebSocket | Laravel Reverb |
| Styling | TailwindCSS 4 |
| Auth | Laravel Sanctum |

## Quick Start

### Docker (Recommended)

```bash
git clone https://github.com/BBerthod/up.git
cd up
cp .env.example .env

docker compose up -d

# Run migrations
docker compose exec app php artisan migrate

# Generate app key
docker compose exec app php artisan key:generate

# Generate VAPID keys for push notifications
docker compose exec app php artisan webpush:vapid
```

Open [http://localhost:8000](http://localhost:8000) and register your first account.

### Local Development

```bash
# Prerequisites: PHP 8.4, Node 20+, PostgreSQL, Redis

composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate

# Start all services
php artisan serve &
php artisan queue:work &
php artisan reverb:start &
npm run dev
```

## Configuration

See [`.env.example`](.env.example) for all available environment variables.

Key settings:

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_CONNECTION` | Database driver | `pgsql` |
| `QUEUE_CONNECTION` | Queue driver | `redis` |
| `REVERB_APP_KEY` | WebSocket app key | — |
| `VITE_VAPID_PUBLIC_KEY` | Push notification VAPID key | — |

## CLI

Install the CLI companion globally:

```bash
cd cli && npm install && npm link
```

Usage:

```bash
up login <api-token>        # Authenticate
up list                     # List all monitors
up add https://example.com  # Add a monitor
up status                   # Overview of all monitors
up status 42                # Details of monitor #42
up pause 42                 # Pause monitoring
up resume 42                # Resume monitoring
up rm 42                    # Delete a monitor
```

## API

Full API documentation: [docs/api.md](docs/api.md)

All endpoints require a Bearer token (Sanctum). Create tokens in Settings > API Tokens.

```bash
# List monitors
curl -H "Authorization: Bearer <token>" http://localhost:8000/api/monitors

# Create monitor
curl -X POST http://localhost:8000/api/monitors \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"name":"My Site","url":"https://example.com","method":"GET","expected_status_code":200,"interval":5}'
```

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'feat: add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

[AGPL-3.0](LICENSE)
