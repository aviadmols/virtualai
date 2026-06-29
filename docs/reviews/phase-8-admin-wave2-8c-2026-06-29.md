## 2026-06-29T16:00:00+03:00 — Phase 8 Wave 2 (UNIT A backend data-contract gaps + UNIT B 8c merchant panel UI) — VERDICT: GREEN
Reviewer: code-review-gatekeeper
Working tree (uncommitted) — diff vs HEAD 44076a9
Scope (reviewed; app/Filament/Platform/** explicitly OUT of scope per gate brief):
  UNIT A (laravel-backend):
    - app/Domain/Platform/{PlatformGuard,PlatformSiteQuery,PlatformCreditLedgerQuery,PlatformActivityQuery,PlatformCreditAdjustment}.php
    - app/Domain/Sites/{SiteKeyRegenerator,SiteSettingsService,InvalidSiteSettingsException}.php
    - app/Domain/Gallery/{MerchantGalleryQuery,GalleryItem}.php
    - app/Models/Account.php (suspend/restore/isSuspended), app/Models/Site.php (retention nullable + RETENTION_* consts)
    - app/Http/Middleware/BindMerchantAccount.php, app/Exceptions/PlatformAccessRequiredException.php
    - database/migrations/2026_06_29_120000_make_sites_retention_days_nullable.php
    - tests/Feature/{Tenancy/PlatformReadSeamsTest,Tenancy/MerchantPanelBindingTest,Tenancy/PlatformSiteQueryTest,
      Platform/AccountSuspendRestoreTest,Platform/PlatformCreditAdjustmentTest,Sites/SiteKeyRegeneratorTest,
      Sites/SiteSettingsServiceTest,Gallery/MerchantGalleryQueryTest}.php
  UNIT B (admin-design-system, app/Filament/Merchant/**):
    - Pages/Dashboard.php; Widgets/{MerchantKpiWidget,CreditBannerWidget}.php
    - Resources/SiteResource.php (+Pages/ListSites,CreateSite); Resources/EndUserResource.php (+Pages/ListEndUsers,ViewEndUser)
    - resources/views/filament/merchant/widgets/{merchant-kpi,credit-banner}.blade.php; .../resources/end-user/view.blade.php
    - resources/views/components/to/{credit-banner,lead-card}.blade.php
    - resources/css/filament/shared/components/{credit-banner,lead-card,kpi-grid}.css; resources/css/to/tokens.css (--toa-kpi-min,--toa-thumb); resources/css/filament/merchant/theme.css
    - lang/{en,he}/{dashboard,merchant,sites,leads}.php

Sweeps run (on the scoped surface):
  - withoutGlobalScope → CLEAN in product code outside the 3 audited platform seams. The only 3 bypass call-sites are
    PlatformSiteQuery:39, PlatformCreditLedgerQuery:36, PlatformActivityQuery:35 — each calls PlatformGuard::assert()
    (isSuperAdmin, Auth-only) BEFORE the bypass and throws PlatformAccessRequiredException otherwise. Every other hit is a comment NEGATING it.
  - bare User::query()/all()/find() in merchant UI + scoped domain → CLEAN (account resolved via auth()->user()->account / ->account_id only; TS-TENANCY-003 honoured)
  - DB::table/statement/select on tenant tables → CLEAN
  - inline style="" in merchant UI → CLEAN (emails not in scope)
  - Tailwind arbitrary values [#..]/[..px]/[rgb]/[var(] in merchant Blade → CLEAN
  - physical CSS props (margin/padding-left/right, left:/right:, text-align:left/right) in scoped CSS → CLEAN (logical props throughout: padding-inline, border-inline-start, inline/block-size, text-align:end)
  - widget_secret / OPENROUTER / sk-or- rendered or logged in scoped UI/domain → CLEAN (widget_secret only in comments asserting it is never touched; $hidden on the model; EncryptedString cast)
  - float/double money + /100 in scoped domain → CLEAN (integer micro-USD; usd display via CreditMath::microToUsd)
  - hardcoded markup 2.5 / MARKUP → CLEAN
  - non-deterministic idempotency keys → only PlatformCreditAdjustment:58 Str::uuid() FALLBACK when no admin ref given (documented + tested; see SUGGEST-1)
  - Blade::render on merchant input → CLEAN (none)
  - raw status writes outside transitionTo → none in scope (Account suspend/restore use forceFill on an account status, not a §5 generation/ledger machine; idempotent + activity-traced)

Tests run: C:\Users\user\.config\herd\bin\php84\php.exe artisan test → 390 passed (1231 assertions), 0 failed, 14.05s.
  Wave-2 filtered set → 48 passed (124 assertions). Each safety property has a red-when-broken test:
    - GAP-P1 PlatformReadSeamsTest: super-admin reads cross-account; merchant/unauthenticated/bound-tenant FAIL LOUD (red if PlatformGuard removed).
    - GAP-P2 AccountSuspendRestoreTest: suspended account generation CANCELLED w/ ACCOUNT_INACTIVE + NO charge; restore re-opens + charges (red if gate input not flipped).
    - GAP-P3 PlatformCreditAdjustmentTest: ledger-row (not bare balance), floor-at-0, same-ref→one-row idempotency, super-admin-only (red if floor or pre-check removed).
    - GAP-M2 SiteKeyRegeneratorTest: rotates public key, invalidates old, NEVER touches/returns widget_secret, trace omits key value.
    - GAP-M3 MerchantGalleryQueryTest: succeeded-only, signed thumb / purged flag, account B cannot see account A (red if Tenant::run bind removed).
    - GAP-M4 SiteSettingsServiceTest: validate-then-persist, whitelist blocks attacker-supplied site_key/widget_secret, typed exception.

i18n 1:1 key parity (flatten + diff both directions):
  - dashboard en=12 he=12 OK · merchant en=4 he=4 OK · sites en=21 he=21 OK · leads en=21 he=21 OK — ZERO drift; he values are genuine Hebrew with placeholders (:amount,:pct) preserved.

Blocking: NONE.

Suggestions (recorded; do NOT gate):
  - SUGGEST-1 resources/views/components/to/credit-banner.blade.php:38 — `meta` is passed ALREADY resolved by CreditBannerWidget::descriptor()
    (__('merchant.credit.balance_meta', ['amount'=>...])) and then re-wrapped in __() here, while `title` is passed as a KEY and resolved once.
    Functionally harmless (an interpolated translated string won't match a key, so __() is a no-op), but the title/meta contract is inconsistent.
    Fix (owner: admin-design-system): pass `meta` as a key + params OR drop the second __() and document meta as pre-resolved. NIT-grade.
  - SUGGEST-2 app/Domain/Platform/PlatformCreditAdjustment.php:58 — when the platform UI calls apply() with $reference=null it falls back to a
    per-call Str::uuid(), so the deterministic-idempotency guarantee depends on the (out-of-scope) platform Filament action ALWAYS passing a
    stable ref (e.g. the form/session token). The service + test are correct; cross-reference for the parallel platform-panel unit so a
    double-submitted admin form cannot double-adjust. Not a blocker on THIS gate (the UI is out of scope here).

Notes (non-findings):
  - SiteResource setup_state badge (sites.status.*): a DERIVED presentational setup indicator (selectors present = ready, else pending), explicitly
    NOT claiming a §5 status machine; resolved via __('sites.status.'.$state), not StatusBadge. ASSESSED ACCEPTABLE — it invents no model status enum
    and is clearly labelled. Optional follow-up for product-ux-architect: confirm "Site setup state" belongs in the UX spec / whether a future Site
    lifecycle enum is wanted; until then this is a display affordance, not a contract gap.
  - MerchantGalleryQuery:68 and LeadAttemptHistory both call MediaStorage::exists() — the known local-dev 500 when the s3 disk isn't configured
    (fix "treat storage exceptions as purged" being applied separately by laravel-backend). NOTED, NOT blocked per gate brief; tests fake the s3 disk so the suite is green.

Re-review: not required (GREEN). Suggestions left to owners' discretion.
Recurring → archivist: none new this gate. Cross-reference SUGGEST-2 to saas-credits-billing's isolation/idempotency audit for the platform credit-adjust action.
