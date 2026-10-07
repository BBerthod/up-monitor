# Runbook: Cloudflare Rocket Loader cassant GA4 / scripts tiers

> **Si tu lis ça en pleine panne** : saute à [Quick Fix](#quick-fix-tldr-pour-panique).

> **Basé sur l'incident GA4 cassé (analytics absent malgré tag GTM correct, à cause de Rocket Loader réécrivant les scripts).**

---

## Symptômes

- GA4 / GTM ne reçoit plus d'events (Realtime Dashboard vide)
- DevTools Console : `gtag is not defined`, `dataLayer is not a function`, ou erreurs CORS sur scripts tiers
- Le `<script>` GTM dans le HTML est présent mais réécrit en `data-cfasync="false"` ou type `text/rocketscript`
- Le bug n'arrivait pas avant — récent
- Souvent : "ça marche en local, ça marche en preview, mais pas en prod CF"

**Distinguer de** :
- Si GA4 a vraiment un tag mal configuré → Tag Assistant Chrome extension
- Si site complètement down → autre runbook

---

## Impact

- **Sites/services affectés** : tous les sites derrière Cloudflare avec Rocket Loader activé (souvent activé "par défaut" via Auto Minify package)
- **User-facing** : invisible directement (analytics manquantes)
- **Conséquence business** : pas de data marketing, pas de conversion tracking → décisions prises à l'aveugle, attribution cassée
- **Sévérité** : P2 (pas down) mais critique pour les sites e-commerce / marketing

---

## Diagnostic (5 min max)

1. **Vérifier si Rocket Loader est actif** :
   - Cloudflare Dashboard → site → Speed → Optimization → Rocket Loader → ON / OFF
   - OU via API :
   ```bash
   curl -s -X GET "https://api.cloudflare.com/client/v4/zones/<ZONE_ID>/settings/rocket_loader" \
     -H "Authorization: Bearer <CF_API_TOKEN>" | jq '.result.value'
   # "on" = activé, "off" = désactivé
   ```

2. **Inspecter le HTML servi vs HTML originel** :
   ```bash
   # HTML brut (sans CF transformations, en bypass)
   curl -s -H "Cache-Control: no-cache" "https://<domain>/?nocache=$(date +%s)" > /tmp/cf.html
   # Vérifier si scripts ont été modifiés
   grep -E 'data-cfasync|rocketscript|/cdn-cgi/' /tmp/cf.html
   ```
   Si on voit `data-cfasync="false"` ou `type="text/rocketscript"` → Rocket Loader est passé par là.

3. **DevTools Network** :
   - Charger la page en navigation privée + DevTools ouvert
   - Filter sur `gtag.js` ou `gtm.js`
   - Si la requête échoue ou si le script est délivré tardivement (après `load` event) → Rocket Loader

---

## Causes probables

1. **Rocket Loader activé site-wide** (80%) → désactiver via Page Rule ou globalement
2. **Page Rule "Speed" auto-config a réactivé Rocket Loader** (15%) → check les Page Rules
3. **Cache CF stale après désactivation** (5%) → purge cache global

---

## Fix (par ordre de risque)

### Option 1 — Désactiver Rocket Loader globalement (recommandé pour sites avec analytics)
- Cloudflare Dashboard → site → Speed → Optimization → Rocket Loader → toggle OFF
- Délai d'effet : immédiat (max 30s propagation)
- Risque : perte mineure de "perceived performance" sur connexions très lentes

### Option 2 — Désactiver via Page Rule pour pages spécifiques
- CF Dashboard → Rules → Page Rules → New rule
- URL pattern : `*<domain>/*` (ou un sous-path spécifique)
- Setting : `Rocket Loader: Off`
- Save and Deploy

### Option 3 — Filtre défensif dans le code (pour résilience long terme)
**WordPress** :
```php
// functions.php — empêche RL de modifier les scripts critiques
add_filter('script_loader_tag', function($tag, $handle) {
    if (in_array($handle, ['ga4', 'gtm', 'recaptcha'])) {
        $tag = str_replace('<script ', '<script data-cfasync="false" ', $tag);
    }
    return $tag;
}, 10, 2);
```

**Laravel/Blade** :
```blade
{{-- inline scripts critiques --}}
<script data-cfasync="false">
  // GTM bootstrap, etc.
</script>
```

L'attribut `data-cfasync="false"` indique à RL de ne PAS toucher ce script — défense en profondeur même si quelqu'un réactive RL plus tard.

### Option 4 — Purge cache CF après changement
```bash
curl -X POST "https://api.cloudflare.com/client/v4/zones/<ZONE_ID>/purge_cache" \
  -H "Authorization: Bearer <CF_API_TOKEN>" \
  -H "Content-Type: application/json" \
  --data '{"purge_everything":true}'
```
Toujours faire après désactivation RL pour éviter de servir l'ancien HTML modifié.

---

## Validation

- [ ] DevTools Console : `typeof gtag === 'function'` retourne `true`
- [ ] GA4 Realtime Dashboard : events visibles dans les 30s après une visite test
- [ ] HTML servi : plus de `rocketscript` ou `data-cfasync` injecté par CF
- [ ] (Recommandé) Tag Assistant Chrome extension : tags "Healthy"
- [ ] J+1 : data complète dans GA4 sur les events critiques (page_view, conversions)

---

## Quick Fix (TLDR pour panique)

```bash
# 1. Désactiver Rocket Loader via API
ZONE_ID=<your-zone-id>
CF_TOKEN=<your-token>
curl -s -X PATCH "https://api.cloudflare.com/client/v4/zones/$ZONE_ID/settings/rocket_loader" \
  -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{"value":"off"}' | jq '.result'

# 2. Purge cache
curl -s -X POST "https://api.cloudflare.com/client/v4/zones/$ZONE_ID/purge_cache" \
  -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{"purge_everything":true}' | jq '.success'

# 3. Vérifier dans 30s
sleep 30
curl -s -H "Cache-Control: no-cache" "https://<domain>/" | grep -E 'rocketscript|cfasync' || echo "Clean — Rocket Loader désactivé"
```

---

## Postmortem (à remplir après)

- [ ] Quand RL a-t-il été activé ? (changelog CF, audit Page Rules)
- [ ] **Pourquoi pas détecté avant** ? La perte de tracking GA4 devrait alerter Layer 4 (impressions ou conversions). Sinon : pas de monitoring GA4 → ouvrir issue.
- [ ] Doit-on ajouter un check fleet "RL status" dans `up` ? (`monitor_type: cloudflare_setting`)
- [ ] Action item : ajouter au PR template Resilience checklist : "Si touche WordPress/Laravel : vérifier compat Rocket Loader" (déjà inclus)
- [ ] Tester en game day 3 (cf. `docs/game-days.md`).
