# Runbook: impressions crash (chute brutale GSC sans erreur HTTP)

> **Si tu lis ça en pleine panne** : saute à [Quick Fix](#quick-fix-tldr-pour-panique).

> **Basé sur l'incident example.de 2026-04 — impressions GSC -82% sur 28 jours, og:image dupliqué (4-6 tags), site répondait 200.**

---

## Symptômes

- Google Search Console : impressions chutent de >30% sur 7 jours glissants
- Site répond 200, monitoring HTTP est vert
- Lighthouse SEO score normal (le crawler perçoit pas le bug subtil)
- Trafic organique en chute libre
- (Si Layer 4 actif) `up.example.com` génère un incident `cause: business_regression`

**Distinguer de** :
- Si SE 503/timeout → c'est plutôt [container-died](./runbook-container-died.md) ou [monitoring-frozen](./runbook-monitoring-frozen.md)
- Si Lighthouse SEO score chute → fix plus visible, voir audit `/seo-audit`

---

## Impact

- **Sites/services affectés** : sites WordPress (surtout avec SEOPress / Yoast / Rank Math), parfois Laravel
- **User-facing** : invisible directement. **Conséquence business** : revenue / leads en chute lente.
- **Sévérité** : P1 (impact business confirmé mais pas de panne immédiate)

---

## Diagnostic (10 min max)

1. **Confirmer la chute via GSC** :
   - Search Console → Performance → comparer période 7j vs 7j précédents
   - Identifier les URLs affectées : queries dont les impressions ont chuté
   - Note la date pivot (où la chute commence)

2. **Cross-référencer avec git log** :
   ```bash
   git log --since="<date pivot - 1 semaine>" --until="<date pivot + 2 jours>" --oneline
   ```
   Cherche commits touchant : `functions.php`, `header.php`, plugin SEO, sitemap, robots.

3. **Inspecter le HTML servi** :
   ```bash
   # Compter og:image, canonical, title
   curl -s -A "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" https://<domain>/<page-affectee> > /tmp/page.html
   grep -c 'og:image' /tmp/page.html        # Attendu : 1
   grep -c '<link rel="canonical"' /tmp/page.html  # Attendu : 1
   grep -c '<title>' /tmp/page.html         # Attendu : 1
   ```
   **Red flag classique** (incident example.de) : `og:image count = 4-6`.

4. **Vérifier le robots.txt et sitemap** :
   ```bash
   curl -s https://<domain>/robots.txt
   curl -s https://<domain>/sitemap.xml | grep -c '<loc>'
   ```
   Si Disallow: / par accident → catastrophe. Si sitemap a perdu des URLs → indexation chutera.

5. **Inspecter rendering Googlebot via GSC** :
   - GSC → URL Inspection → "Test live URL" → "View tested page" → Screenshot + HTML
   - Comparer avec le HTML local

6. **Cloudflare Rocket Loader actif ?**
   - Cf. [runbook-cloudflare-rocket-loader.md](./runbook-cloudflare-rocket-loader.md)
   - Si actif sur ce site, peut perturber le SEO de Googlebot rendering

---

## Causes probables (par fréquence)

1. **og:image / canonical / title dupliqués** (35%) → conflit plugin SEO + theme custom (incident historique)
2. **robots.txt accidentellement Disallow: /** (15%) → check immédiat
3. **Sitemap cassé / perte d'URLs** (15%) → plugin SEO mal configuré
4. **Cloudflare Rocket Loader perturbant le rendering** (10%) → cf. runbook dédié
5. **Migration URL sans 301 propres** (10%) → audit redirects
6. **Hreflang cassé sur multi-locale** (5%) → check `<link rel="alternate" hreflang="...">` cohérence
7. **Pénalité Google manuelle** (5%) → GSC > Sécurité et actions manuelles
8. **Algorithm update Google** (5%) → check actualités SEO sur la date pivot

---

## Fix (par ordre de risque)

### Option 1 — Rollback du commit suspect (si identifié à l'étape 2)
```bash
git revert <commit-sha>
git push
# Puis redeploy via Dokploy / Github Actions
```
- Quand : commit identifié, rollback safe
- Risque : faible si le commit était isolé

### Option 2 — Fix code immédiat (og:image dupliqué)
**Pattern WordPress + SEOPress** (cf. règle `MEMORY.md` "example.de") :
```php
// AVANT (wrong)
add_action('wp_head', 'mytheme_og_image_fallback', 5);

// APRÈS (right) — utiliser le filter du plugin SEO
add_filter('seopress_social_og_thumb', function($value) {
    if (!empty($value)) return $value;
    return get_template_directory_uri() . '/assets/img/og-fallback.jpg';
});
```
Toujours utiliser le filter du plugin SEO, jamais `wp_head` action si le plugin gère déjà la meta.

### Option 3 — Soumettre URL re-indexation après fix
- GSC → URL Inspection → URL → "Request indexing"
- Limit : ~10/jour, prioriser pages les plus impactées
- Délai d'effet : 1-7 jours

### Option 4 — Audit fleet-wide preventif
```bash
# Pour chaque site fleet, check og:image count
for site in $(cat ~/.claude/sites/radiank.yml | grep -E '^- domain:' | awk '{print $3}'); do
  count=$(curl -s "https://$site/" | grep -c 'og:image')
  echo "$site : $count og:image"
done
```
Si plusieurs sites ont count != 1 → fix pattern, pas un cas isolé.

---

## Validation

- [ ] HTML : exactement 1 `og:image`, 1 canonical, 1 title par page
- [ ] GSC URL Inspection sur 3 pages clés → "Indexed", screenshot OK, HTML rendu correct
- [ ] (Si Layer 4 actif) `BusinessSnapshot` quotidien : `og_image_count = 1` partout
- [ ] J+7 : impressions GSC remontent (lent, peut prendre 14-28 jours full recovery)
- [ ] Smoke test post-deploy ajoute la vérification `og:image count == 1` (Layer 5)

---

## Quick Fix (TLDR pour panique)

```bash
# 1. Vérifier ce qui est dans le HTML
DOMAIN=https://<your-site>
curl -s -A "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" "$DOMAIN/" \
  | grep -E 'og:|canonical|<title>' | head -20

# 2. Si og:image count > 1 → identifier les sources via WP plugin disable
# Désactiver les plugins SEO un par un en local et observer le HTML
```

Le fix code prend 1-3h. La récupération SEO prend 2-4 semaines (Google ré-indexe lentement).

---

## Postmortem (à remplir après)

- [ ] Timeline : date pivot impressions vs date deploy responsable
- [ ] Root cause : quel commit ? quel pattern ?
- [ ] **Detection delay** : sans monitoring GSC quotidien (Layer 4), c'était 28 jours. Cible : < 24h.
- [ ] Resolution time : commit fix + temps recovery indexation Google (lent)
- [ ] **Pattern à transformer en règle fleet** : si og:image / canonical / title dupliqué : ajouter au PR template `Resilience checklist`
- [ ] Action item : monitoring `og_image_count` dans `BusinessSnapshot` actif sur ce site ?
