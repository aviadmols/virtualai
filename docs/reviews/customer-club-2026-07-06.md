## 2026-07-06T00:00Z — Customer-Club module (backend foundation + widget/merchant UI) — VERDICT: GREEN

Reviewer: code-review-gatekeeper
Commits: 86633ca (backend foundation: member email-OTP + per-site club config) · 04c00c0 (widget + merchant UI: banner + email login, member pricing, price-zone picker)
Scope: app/Domain/Club/* (ClubVerification, ClubMembership, ClubVerifyResult) · app/Domain/Sites/ClubConfig.php · app/Http/Controllers/Widget/{ClubRequestCode,ClubVerifyCode,Bootstrap}Controller.php · app/Http/Requests/Widget/Club*Request.php · app/Mail/ClubVerificationCodeMail.php + email blade · app/Models/{EndUser,Site,ActivityEvent}.php · app/Filament/Merchant/Pages/ClubSettings.php + blade · resources/widget/src/{pricing,club,constants,i18n,api,state,track}.js · resources/widget/picker/picker.js · resources/css/filament/shared/components/place-visually.css · lang/{en,he}/club.php · 2 migrations

Sweeps run (all clean for the club surface):
- withoutGlobalScope in club code -> NONE (only the audited PlatformGuard seam + "no withoutGlobalScopes" comments; ClubSettings.php:46 is a comment stating none is used)
- Blade::render on club/merchant/mail templates -> NONE (email is a static developer blade with {{ }}-escaped scalars; strtr rule N/A)
- raw DB::table/statement/select in club domain -> NONE (BootstrapController:98 sites-heartbeat is PRE-EXISTING, PK-filtered on the bound site, non-tenant column — out of this diff)
- inline style= in club merchant blade -> NONE; arbitrary tailwind/hex in blade -> NONE
- secrets (OPENROUTER/sk-or-/widget_secret/api_key) in club/pricing/api/picker JS -> NONE (only "no secret" comments)
- place-visually.css added lines -> tokens only (30 var(--) refs; one 220px is a grid minmax structural dimension, not a theme token)

Tests run: php artisan test --filter=Club -> 33 passed (136 assertions). Meaningful (red-when-broken) safety tests confirmed by reading:
- "club verification is account isolated b cannot verify a": runs Tenant::run($B) and asserts 0 KIND_CLUB_JOINED in B (WidgetClubAuthTest.php:185-189)
- "bootstrap member state is account isolated"
- wrong/expired code asserts endUserFor(...) === null -> a bad guess mints NO lead (WidgetClubAuthTest.php:121, :185)
- attempt cap -> Locked; per-email throttle -> code_sent:false + only 1 mail sent; typed 422 on missing field; wrong code is typed invalid not a 500
- happy path asserts verified_at + marketing_consent + marketing_consent_at stamped (WidgetClubAuthTest.php:91-97)
- ClubConfig sanitize: rejects negative/non-int discount, unknown surface, scriptable selector, >cap zones; drops blanks
Widget gates: verify.mjs enforces gz <= 25600 (project budget; unchanged by these commits) + CLS < 0.02 incl. a member-price-rewrite-specific CLS gate. Commit reports 22.1 KB gz, all gates pass.

FOCUS-AREA FINDINGS:

1. MONEY-SAFETY — PASS. The member discount is provably DISPLAY-ONLY. pricing.js reads the existing host price node's text, recomputes it locale-aware, rewrites ONLY the text + appends one inline badge; it never touches checkout, cart, a discount code, an API mutation, the credit ledger, or the generation charge. Recompute is from a stashed original (no double-discount). ClubConfig.discount_percent is a display int 0..100; it never enters any charge math. No money moves from a discount. SiteSettingsService writes only whitelisted keys (never site_key/widget_secret).

2. PRIVACY / PII / CONSENT — PASS. Joining is an EXPLICIT marketing opt-in: ClubMembership.join() stamps marketing_consent=true + marketing_consent_at ONLY on the first flip, with consent_basis='club_join' traced on the KIND_CLUB_JOINED activity event; GDPR-off-by-default is preserved (DEFAULTS club OFF, no pre-check elsewhere). OTP is cache-based (no DB row): 6-digit, TTL 600s, single-use (forget on match), attempt cap 5 (burns the code), per-email throttle 60s via atomic cache add(), constant-time hash_equals compare. Key = server-resolved site_id + sha1(anon|email) — never a client id. No PII beyond email is stored (verified_at is a timestamp; no name/phone). A failed/expired verify resolves NO EndUser and mints no lead (EndUserResolver::resolve is called only after a proven match).

3. TENANT-SAFETY — PASS. club_config is a cast JSON column on Site (BelongsToAccount); EndUser.verified_at is on the account-scoped EndUser. Club routes live inside the widget group in bootstrap/app.php with ResolveWidgetSite (resolves site by site_key+Origin and binds the tenant) + WidgetRateLimit, so both controllers act on WidgetContext::of($request)->site (server-resolved, tenant-bound) — account B cannot request/verify against or read A's club (proven by the isolation tests). No withoutGlobalScopes anywhere in club code. ClubSettings uses Filament::getTenant() (Site) + Site::query()->find() through the global scope (no manual where(account_id)); preview cache key namespaced by the bound site id; latestScannedProduct() is site-scoped. orderByRaw at ClubSettings:483 interpolates only class constants (no user input).

4. CONVENTIONS — PASS. CONST-at-top across all PHP + widget constants.js. i18n en/he 1:1: lang club.php 52=52 keys (no gaps); widget i18n.js club.* 22 EN keys mirror 22 HE keys. No inline CSS in the merchant blade or the widget shadow-DOM UI (club.js banner/modal are class/token-based). The picker iframe is sandbox="allow-scripts" ONLY (no allow-same-origin), srcdoc from sanitized-cached HTML. Picked selectors are verified SERVER-SIDE (SelectorTester count===1) before storage, deduped, capped 5/surface, and only ever counted as a DOM query — NEVER executed; the sanitize() SELECTOR_PATTERN allow-list rejects scriptable chars. strtr-not-Blade upheld (email is a static developer blade, no merchant text). No secret in the browser. Widget under its declared gzip budget; price rewrite is CLS-safe (gated < 0.02).

Suggestions: NONE.

Nits:
- N1 pricing.js:186-188 — the "club price" badge sets 3 inline styles (font-size:0.75em; opacity:0.7; white-space:nowrap) via style.setProperty. JUSTIFIED and NOT a blocker: the badge is injected into the HOST page's LIGHT DOM (outside the widget shadow root), so it cannot reach the widget's --ton-* tokens or a shadow-scoped class; the CLAUDE.md no-inline-CSS rule targets admin/widget-shadow UI. Uses relative units only, no color/theme literal, no physical-direction property (RTL-safe). Recorded for awareness; the code comments the rationale. No action required.

Re-review: not required.
Recurring -> archivist: none new. (The locale-aware money parse cites the existing TS-PDPSCAN-001 scar and correctly reuses that pattern in pricing.js — a good sign the registry is being consulted.)

FLAG (carried from the commit, infra not gatekeeper): prod email OTP needs a real SMTP transport in the Railway env (MAIL_MAILER is 'log' today) before the club is enabled in production — otherwise codes never leave the log. Route to railway-infra; not a code blocker.

GATE: GREEN — 0 blocking findings across money-safety, privacy/consent, tenant-safety, conventions. The module is inert-by-default (club OFF), display-only pricing, tenant-isolated, and consent-correct. Phase gate may advance.
