# Runbook: affiliate evidence gate

Use this before declaring that a site has gained/lost traffic, converts well, has broken analytics, or should receive a large SEO/content investment. A conclusion is blocked unless three independent sources are fresh:

1. GSC for Google impressions/clicks and ranking visibility.
2. GA4 for measured sessions/users and acquisition sources.
3. Amazon Associates filtered on the exact tracking ID configured for the site.

Global Amazon account totals are never acceptable evidence for an individual site. Queries containing the GSC `site:` operator are inspection noise, not ranking opportunities.

## Daily collection

Run the normal KPI collector for GSC and GA4. Export/read the Amazon report with exactly one tracking ID selected, then record its values:

```bash
php artisan affiliate:kpi-record reviewsite \
  --tracking-id=reviewsite-20 \
  --clicks=2006 \
  --orders=110 \
  --earnings=75.83 \
  --ordered-revenue=2351 \
  --currency=USD \
  --period-days=30
```

When Amazon displays `-` because it masks low-volume values, pass
`--orders=unknown --ordered-revenue=unknown`. The command records only the
observed clicks and earnings; it never converts masked values into invented
zeroes.

The command rejects blank tracking IDs, IDs that differ from `sites.amazon_tag`, invalid dates and negative values. It stores clicks and earnings, plus ordered items, ordered revenue and conversion rate when Amazon exposes them, with the tracking ID and currency in snapshot metadata.

## Decision gate

```bash
php artisan affiliate:decision-check reviewsite --max-age-hours=48
```

A non-zero exit means the diagnosis must stop. Refresh the missing/stale source instead of filling the gap with an assumption. A zero exit means only that the evidence is complete and correctly scoped; interpretation still requires checking date ranges, countries, consent/ad-blocking and known bot traffic.

## Interpretation rules

- GSC down, GA4 organic down, Amazon clicks/earnings down: likely real acquisition loss.
- GSC stable, GA4 sharply up, Amazon flat: investigate bots, direct traffic and broken attribution before celebrating.
- GSC up, GA4 flat, Amazon flat: check CTR, landing intent and GA consent/tag execution.
- Amazon conversion changes on very few orders: insufficient sample; do not redesign conversion UX from a micro-cohort.
- Always compare identical windows and state the tracking ID, currency, marketplace and extraction timestamp in the report.
