## 2026-07-06T00:00Z — Banner module PHASE 2 (merchant editor + validated writer) — VERDICT: GREEN (PASS-WITH-SUGGESTIONS, applied)

Reviewer: code-review-gatekeeper
Scope: the merchant "Banners" resource + editor + the single validated writer.
New: app/Domain/Banners/{BannerService,BannerContent,InvalidBannerException}.php ·
app/Filament/Merchant/Resources/BannerResource.php + Pages/{ListBanners,CreateBanner,EditBanner}.php ·
lang/{en,he}/banners.php · tests/Feature/Banners/BannerServiceTest.php +
tests/Feature/Filament/Merchant/BannerResourcePageTest.php.
Modified: MerchantPanelProvider (nav.marketing group) + lang/{en,he}/nav.php.

Tests: `--filter="BannerService|BannerResourcePage"` → 16 passed (57 assertions). Adjacent
merchant/tenancy/i18n suite → 126 passed. i18n parity mechanical: banners en=66 he=66, nav en=8 he=8.

FINDINGS: 0 BLOCKING. 3 SUGGESTION + 2 NIT. Applied S1, S3, N2; S2 is by-design; N1 reserved for Phase 5.
- S1 (APPLIED): EditBanner now fetches the selected candidate via `$record->assets()->find()` (banner-scoped
  query) — defense-in-depth on top of the account global scope + selectAsset's banner_id guard.
- S3 (APPLIED): the generate action logs the real throwable server-side (Log::warning) while showing the
  merchant a single friendly notice.
- N2 (APPLIED): BannerContent's http(s) scheme allow-list lifted to a const ALLOWED_SCHEMES.
- S2 (INTENDED): each Generate mints a fresh client_request_id → a new candidate (that is the "chat"
  iteration model); Filament disables the in-flight submit. Charge atomicity is Phase-1's four-layer wall.
- N1 (RESERVED): candidates.status.* + candidates.section lang keys are wired in Phase 5 (candidate polling).

CONTRACT VERIFICATION (gatekeeper, with sweeps + a green run):
- TENANT-SAFETY — PASS. Banner/BannerAsset are BelongsToAccount + site-scoped; BannerResource.getEloquentQuery
  narrows to the Filament tenant (Site) on top of the account scope (the EndUserResource idiom, $isScopedToTenant
  =false); no withoutGlobalScopes; every write routes through BannerService under the bound tenant. A foreign
  shop's banners are invisible (test) and a foreign/other-banner asset can never be copied (selectAsset banner_id
  guard + banner-scoped fetch + test).
- MONEY/AI — PASS. No CreditLedger/CreditGate/balance/markup reference anywhere in Phase 2; the editor never
  charges — generate delegates to the Phase-1 StartBannerGeneration; setStatus only moves the banner lifecycle.
- NO INLINE CSS — PASS (sweep clean; the earlier inline-style preview was removed; badge colours are Filament
  slot names). CONST-at-top + no magic strings — PASS. strtr-not-Blade — N/A (overlay is validated scalars).
- FILAMENT CORRECTNESS — PASS. Create/Edit route saves through BannerService (handleRecordCreation/Update) and
  surface InvalidBannerException as a soft Notification + halt() (never a 500); the reference upload is read
  once + deleted + fail-soft; status actions are guarded by BannerService.

GATE: GREEN — Phase 2 may advance. Placement picker (Phase 3) + targeting rules (Phase 4) + widget runtime and
analytics (Phase 5) slot into the same BannerResource/editor + Banner model.
