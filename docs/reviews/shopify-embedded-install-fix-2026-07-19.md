# Review log — Shopify Embedded install fix (stay-in-iframe + CHIPS cookie contract)

## 2026-07-19T14:10:00+03:00 — Unit: Shopify Embedded install fix — VERDICT: GREEN
Reviewer: code-review-gatekeeper
Scope (in-unit only): `.env.example`, `app/Console/Commands/PredeployCheck.php`,
`app/Domain/Shopify/Auth/ShopifyInstaller.php`,
`app/Http/Shopify/Controllers/OAuthController.php`, `config/session.php`,
`docs/TROUBLESHOOTING.md`, `resources/views/shopify/embedded/app.blade.php`,
`tests/Feature/Infra/PredeployCheckTest.php`,
`tests/Feature/Shopify/ShopifyAutoProvisionTest.php`,
`tests/Feature/Shopify/ShopifyEmbeddedApiTest.php`,
`tests/Feature/Shopify/ShopifyEmbeddedEntryTest.php`,
`tests/Feature/Shopify/ShopifyInstallNewShopTest.php`.
Explicitly OUT of unit (prior user changes, not attributed here): `shopify/shopify.app.toml`, `.tmpmeasure/`.

Sweeps run: dangling `ROUTE_CLAIM` after const removal (clean — no references) ·
`style="`/arbitrary-value inline CSS in blade (clean — `<style>` token block + logical props only) ·
orphaned lang keys after fallback-link removal (2 mirrored, harmless) ·
tenant-bind on install path (`installFreshShop`→`connect`→`Tenant::run`, clean) ·
secret in browser/log (clean).

Tests run (my own hands): `php84 artisan test tests/Feature/Shopify tests/Feature/Infra/PredeployCheckTest.php`
→ 232 passed / 1048 assertions. Consistent with reported 237/1077 full-suite evidence.

Findings:
- No BLOCKING findings.
- SUGGEST: `config/session.php:202` — `same_site` default is now `none` GLOBALLY (all
  panels), a defense-in-depth reduction required for the embedded iframe. Acceptable:
  Laravel `VerifyCsrfToken` still guards web POSTs, cookie is `Secure` + `Partitioned`,
  and the contract is predeploy-gated. Awareness only.
- NIT: `lang/en/shopify_embedded.php:56` + `lang/he/shopify_embedded.php:56`
  (`errors.open_new_tab`) and `errors.session_failed` are no longer referenced after the
  `toe-error-fallback` link was removed from `app.blade.php`. Mirrored 1:1 so no i18n
  violation — cleanup candidates.

Contract checks:
- Tenant-safety: install attaches via `installFreshShop` inside `Tenant::run($accountId)`;
  cross-account wall (`assertShopIsClaimableBy` via pre-bind router) intact; session bridge
  resolves the account from the connection, never the browser; webhook job dispatched with
  explicit `account_id`. PASS.
- Auth/session security: callback still fails closed on HMAC + signed browser-bound state;
  connect_existing_site store-theft wall unchanged; auth branch reads `Auth::user()->account_id`
  and rejects `<=0`. All three `install_new_shop` branches (known / authenticated / guest)
  return to `https://{shop}/admin/apps/{client_id}` — no park/claim/login top-level escape. PASS.
- Session bridge: `authedFetch` sends `credentials: 'include'`; `boot()` HALTS on a failed
  `POST /shopify/app/session` (`if (!session.ok) return fail()` + `ok`/`dashboard_url` guard)
  before render; dashboard CTA `target="_self"` stays in-iframe. PASS.
- Cookie contract: `SameSite=None; Secure; Partitioned` pinned in `.env.example` +
  `config/session.php`; predeploy guard now hard-fails when any of the three drift. PASS.
- Legacy claim: `park()`/`claim()` + `InstallClaimController` + `shopify.install.claim`
  route preserved; legacy pending rows still consumable exactly once (proven by test). PASS.
- CONST-at-top / i18n / RTL / no inline CSS: PASS.
- Docs: TROUBLESHOOTING TS-INFRA-004 is honest — status `open`, explicitly
  "NOT YET VERIFIED IN PRODUCTION", no false green claim. PASS.

Re-review: not required.
Follow-up (owner discretion): the SUGGEST + NIT above; and the documented post-deploy
release step — perform ONE real install from Shopify Admin and confirm the app stays
embedded before flipping TS-INFRA-004 to `resolved`.
