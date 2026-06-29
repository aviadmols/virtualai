## 2026-06-29 — Phase 8 Wave 2 — 8f merchant screens (credits/gallery/privacy) + WHOLE-WAVE-2 FINAL SWEEP — VERDICT: PASS-WITH-SUGGESTIONS (Phase 8 Wave 2 MAY CLOSE)

Reviewer: code-review-gatekeeper
Working tree: uncommitted (diff vs HEAD 44076a9). FINAL phase-close gate for Wave 2; 8c/8d/8e gated separately (GREEN / PASS-WITH-SUGGESTIONS / GREEN). This entry re-confirms the whole surface and adds 8f.

### PART 1 — 8f scope (gated here)
- CreditLedgerResource.php (+ Pages/ListCreditLedger); BalanceWidget.php (+ balance.blade.php)
- BuyCredits.php (+ buy-credits.blade.php); CreditBannerWidget.php (buyHref wiring — 8c null-CTA flag CLOSED)
- Gallery.php (+ gallery.blade.php); components/to/gallery-tile.blade.php; PrivacySettings.php (+ privacy-settings.blade.php)
- merchant/theme.css + shared buy-credits.css/gallery.css/settings-form.css/buttons.css
- lang/en|he/{credits,settings,sites}.php; tests/Feature/Filament/Merchant/CreditsGalleryPrivacyTest.php

### 8f findings

| # | Severity | File:line | Rule | What | Fix owner applies |
|---|----------|-----------|------|------|-------------------|
| 1 | SUGGEST | tests/Feature/Gallery/MerchantGalleryQueryTest.php:95 | sec3.7 test hygiene | Order-dependent FLAKE: passes in isolation + in Filament/Widget+Gallery subsets; intermittently RED only in FULL suite (run A 422 passed/1 failed; run B same code 423 passed). Cross-test global-state leak (Storage::fake s3 / config trayon.media.disk), NOT product code — MerchantGalleryQuery::resolveThumbnail fails closed to purged placeholder (never 500). | laravel-backend: set+restore trayon.media.disk per test / one fake instance so the suite is deterministic. |
| 2 | NIT (carried 8c) | resources/views/components/to/credit-banner.blade.php:38 | sec3.4 i18n | meta arrives ALREADY resolved from CreditBannerWidget::descriptor then re-wrapped in __() here; harmless no-op, contract inconsistent. | admin-design-system: pass meta as key+params OR drop the second __(). |
| 3 | NIT | app/Filament/Merchant/Widgets/MerchantKpiWidget.php:99 | cosmetic | int() docblock says locale-aware but number_format() has no locale arg. Doc/impl drift. | admin-design-system: fix comment or pass a locale. |

8f correctness — every required property PROVEN:
- TENANT-SAFETY: PASS. Every screen account-scoped by bound tenant (BindMerchantAccount) + BelongsToAccount global scope. Scalar-ids-in-Livewire + find/findOrFail-through-scope (Gallery.php:71-86, PrivacySettings.php:99-118; CreditLedgerResource IS BelongsToAccount). NO manual where(account_id), NO withoutGlobalScopes in any 8f file. Foreign ?site= resolves to null then falls back to OWN first site — proven by test_privacy_form_only_resolves_the_merchants_own_site and test_a_foreign_accounts_ledger_rows_are_not_listed (RED if scope removed).
- MONEY-SAFETY: PASS. CreditLedgerResource canCreate/canEdit/canDelete all false (test_ledger_is_read_only). BuyCredits::checkout routes through PurchaseInitiator::initiate — nothing credited at init: provider refusal persists nothing; success writes only a pending credit_purchases row keyed by the deterministic purchase:{account}:{provider}:{provider_ref} key inside Tenant::run; the credit_ledger purchase row is the idempotent webhook job on confirmed paid. test_checkout_without_an_amount_does_not_initiate uses shouldNotReceive(initiatePurchase). Integer micro-USD throughout (usdToMicro returns int; microToUsd returns float for DISPLAY only).
- SiteSettingsService VALIDATION: PASS. PrivacySettings::save catches typed InvalidSiteSettingsException to a field error (no 500); other Throwable to generic notice; validate-then-persist, forceFills only the 4 whitelisted columns (never site_key/widget_secret/allowed_origins). test_invalid_free_generations_is_a_field_error_not_a_500 seeds retention=7, attempts 90 with a bad value, asserts field error AND retention STILL 7 — proves NO partial save. The -1 sentinel forces server-side rejection (fail-closed).
- GALLERY PURGED: PASS. gallery-tile renders placeholder glyph + purged note when !resultThumbnailUrl || purged, never a broken img. resolveThumbnail wraps disk in try/catch, degrades any miss/exception to [false,null] -> purged. Succeeded-only.
- DESIGN SYSTEM: PASS. Zero inline style=, zero Tailwind arbitrary, no raw hex/px/rgb outside token files, logical props only. buy-credits/gallery/settings-form css each open with a TOKENS header; every new PHP file opens with a CONSTANTS block.
- i18n credits/settings/sites: PASS. credits 34/34, settings 33/33, sites 36/36 — 1:1, zero drift both directions; genuine Hebrew, placeholders preserved.

### PART 2 — WHOLE-WAVE-2 FINAL SWEEP
- withoutGlobalScope(s): PASS. Whole-tree — ONLY real call-sites are PlatformSiteQuery.php:39, PlatformCreditLedgerQuery.php:36, PlatformActivityQuery.php:35, each preceded by PlatformGuard::assert (super-admin from Auth only, else PlatformAccessRequiredException). Every other hit is a NEGATING comment. No inline bypass in any Filament resource.
- bare User::query()/all()/find()/where() in Filament: PASS. NONE. Account via auth()->user()->account / ->account_id only (TS-TENANCY-003).
- inline style= / Tailwind arbitrary in admin Blade: PASS. Zero in resources/views/filament/** and components/to/**. The only style=/[..] hits are welcome.blade.php (stock Laravel landing page, NOT admin/widget, out of scope).
- Blade::render / ->render / eval on prompt/template/merchant value: PASS. NONE — all hits are strtr-not-Blade::render comments; resolver-preview is strtr only via Blade auto-escape. No ->render( anywhere in app/.
- i18n 1:1 (flatten EVERY en/he pair, both directions): PASS. 16 pairs, miss-in-he=0, miss-in-en=0: actions 10/10, activity 20/20, credits 34/34, dashboard 12/12, embed 14/14, leads 21/21, merchant 4/4, nav 7/7, platform 239/239, scan 53/53, settings 33/33, sites 36/36, states 6/6, status 17/17, widget 71/71, widget_api 27/27. 3 English-leak candidates ALL false positives: sites.field.domain_placeholder + origins_placeholder = https://shop.example.com (URL example); platform.logs.actor.webhook = Webhook (technical noun, cleared in 8d). No HE file without an EN counterpart.
- secrets (widget_secret / OpenRouter key): PASS. Never rendered or logged on the admin surface. All app/Filament hits are NEGATING comments; Site casts widget_secret EncryptedString + hidden; embed renders PUBLIC site_key only (e()-escaped); ActivityEvent renders class_basename#id, no payload.
- money: PASS. Integer micro-USD everywhere; float only in display *Usd helpers (microToUsd) and ratios (percent). No hardcoded markup; no /100; usdToMicro returns int.
- CONST-at-top: PASS. Present in every new 8f PHP/Blade/CSS file.

### Tests + boot
- artisan test -> 423 passed (1352 assertions), 0 failed on the FINAL run. One prior run of identical code showed 422 passed / 1 failed at MerchantGalleryQueryTest:95 — order-dependent flake (Finding #1), NOT stable, NOT product code. 8f CreditsGalleryPrivacyTest = 13 tests, all meaningful (assert absence of cross-account rows, the no-provider-call path, the no-partial-save property; each goes RED if its guard is removed).
- Boot: clean (Laravel 11.54.0 / PHP 8.4.15, no missing class). BOTH panels register: merchant (dashboard, buy-credits, credit-ledgers, gallery, privacy-settings, sites, end-users) + platform (accounts, ai-models, ai-operations, prompts, sites, credit-ledgers, activity, login). 32 Filament routes.

### Cross-references
- Tenant-isolation RELEASE BLOCKER independently proven by saas-credits-billing parallel isolation audit (TS-TENANCY-003). This gate is the contract/convention sweep + 8f correctness; both agree on merchant-panel scoping and the 3 audited platform seams.
- Carried money SUGGESTION from 8c/8d (PlatformCreditAdjustment per-render UUID idempotency anchor) is a PLATFORM admin-action concern, out of 8f scope; remains routed to admin-design-system + saas-credits-billing. Not re-opened here.

### Verdict
- Blocking: NONE. No tenant-leak, no charge-on-failure, no double-charge, no missing ledger row, no float money, no hardcoded model/markup, no secret on any surface, no Blade::render, no i18n drift, no inline CSS. 8f correct on every release-blocker axis; app boots; both panels register.
- Suggestions: #1 flaky MerchantGalleryQueryTest isolation (laravel-backend); #2 credit-banner meta double-__ (admin-design-system, carried); #3 MerchantKpiWidget int locale comment (admin-design-system).

GATE: PASS-WITH-SUGGESTIONS — Phase 8 Wave 2 MAY CLOSE. Zero blocking findings; 8f screens are tenant-safe, money-safe, validation-correct, design-conformant, i18n-mirrored 1:1. The single non-blocking risk is a test-isolation flake (product code is correct, fails closed). Re-review NOT required to close; #1 routed to laravel-backend so the gate signal stays trustworthy.

Recurring -> archivist: MediaStorage/Storage::fake cross-test config leak — record as a known flaky-suite class (set+restore trayon.media.disk per test; isolate the s3 fake). Good pattern reused across 8f: scalar-ids-in-Livewire + find()-through-global-scope + a foreign-id-resolves-to-own/null test is the reference tenancy shape for merchant Filament pages.
