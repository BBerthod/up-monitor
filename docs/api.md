# Up API Reference

Base URL: `http://localhost:8000/api`

All authenticated endpoints require a Bearer token:
```
Authorization: Bearer <your-api-token>
```

Create tokens in **Settings > API Tokens**.

---

## Health Check

```
GET /health
```

No authentication required.

**Response:**
```json
{ "status": "ok", "timestamp": "2026-02-15T12:00:00Z" }
```

---

## Monitors

### List Monitors

```
GET /monitors
```

### Create Monitor

```
POST /monitors
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | yes | Monitor display name |
| `url` | string | yes | URL to monitor |
| `method` | string | yes | `GET`, `POST`, or `HEAD` |
| `expected_status_code` | integer | yes | Expected HTTP status (e.g. 200) |
| `interval` | integer | yes | Check interval in minutes (1-60) |
| `keyword` | string | no | Expected keyword in response body |
| `warning_threshold_ms` | integer | no | Warning response time threshold |
| `critical_threshold_ms` | integer | no | Critical response time threshold |
| `notification_channels` | array | no | Array of notification channel IDs |

### Get Monitor

```
GET /monitors/{id}
```

### Update Monitor

```
PUT /monitors/{id}
```

### Delete Monitor

```
DELETE /monitors/{id}
```

### Pause / Resume

```
POST /monitors/{id}/pause
POST /monitors/{id}/resume
```

### Get Monitor Checks

```
GET /monitors/{id}/checks?from=2026-02-01&to=2026-02-15
```

---

## Notification Channels

### List Channels

```
GET /notification-channels
```

### Create Channel

```
POST /notification-channels
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | yes | Channel display name |
| `type` | string | yes | `email`, `webhook`, `slack`, `discord`, `push` |
| `settings` | object | yes | Type-specific settings |
| `is_active` | boolean | no | Enable/disable (default: true) |

**Settings by type:**
- Email: `{ "email": "user@example.com" }`
- Webhook: `{ "url": "https://..." }`
- Slack: `{ "webhook_url": "https://hooks.slack.com/..." }`
- Discord: `{ "webhook_url": "https://discord.com/api/webhooks/..." }`

### Update / Delete Channel

```
PUT /notification-channels/{id}
DELETE /notification-channels/{id}
```

---

## Status Pages

### CRUD

```
GET    /status-pages
POST   /status-pages
GET    /status-pages/{id}
PUT    /status-pages/{id}
DELETE /status-pages/{id}
```

### Public Status Page (No Auth)

```
GET /status-pages/public/{slug}
```

---

## Push Subscriptions

```
POST   /push-subscriptions    # Subscribe
DELETE /push-subscriptions    # Unsubscribe (body: { endpoint })
```

---

## Sites

A Site is a managed website with its SEO/audit configuration (GSC, Bing, GA4,
locales, affiliation, ad networks) and zero or more uptime monitors. Team-scoped.

### CRUD

```
GET    /sites
POST   /sites
GET    /sites/{id}
PUT    /sites/{id}
DELETE /sites/{id}
```

### Create / Update fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `alias` | string | yes | Unique identifier per team (e.g. `examplestore`) |
| `organization` | string | no | Owning organization (e.g. `Radiank`) |
| `primary_domain` | string | yes | Canonical host (e.g. `examplestore.com`) |
| `domains` | array | yes | Domains, supports `{locale}` template |
| `locales` | array | no | Locale codes (e.g. `["fr","us"]`) |
| `primary_locale` | string | no | Substituted into `{locale}` domains |
| `type` | string | yes | `laravel`, `wordpress`, `static`, `compose` |
| `gsc_property` | string | no | `sc-domain:example.com` or `https://example.com/` |
| `bing_url` | string | no | Bing Webmaster URL |
| `ga4_property` | string | no | GA4 property ID (`p123456789`) |
| `sitemap_path` | string | no | e.g. `/sitemap.xml` |
| `key_pages` | array | no | Important paths to audit |
| `ad_networks` | array | no | e.g. `["adsense"]` |
| `merchant_domains` | array | no | e.g. `["amazon"]` |
| `dokploy_app_id` | string | no | Dokploy application id |
| `is_active` | boolean | no | Default: true |

Each site response includes a `kpis` block with the latest collected metrics:
`gsc_clicks_28d`, `gsc_impressions_28d`, `gsc_ctr_28d`, `gsc_position_28d`,
`bing_clicks_28d`, `bing_impressions_28d`, `bing_ctr_28d`,
`ga4_users_28d`, `ttfb_p95_ms` (null when not yet collected).

---

## KPI Collection

Trigger an on-demand KPI collection run (TTFB + GSC + GA4 per site) instead of
waiting for the scheduled 03:00 UTC run. Returns `202 Accepted`; the job is queued.

```
POST /kpi/collect
```

> **Cloudflare note:** the production host (`up.example.com`) sits behind a
> Cloudflare WAF that rejects requests with `Python-urllib`/default User-Agents
> (error 1010). Always send a browser-like `User-Agent` header from scripts.
> `curl` works out of the box.
