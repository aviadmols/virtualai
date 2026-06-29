# Phase 8 Wave 2 — Tenant-Isolation Re-Audit (RELEASE BLOCKER)

- **Date:** 2026-06-29
- **Auditor:** `saas-credits-billing`
- **Scope:** every Wave-2 admin surface — merchant Filament resources/pages/actions,
  the domain queries the merchant screens call, the platform super-admin read seams,
  and the money-safety lane (admin credit-adjust idempotency + media degradation).
- **Method:** model census + static hunt + adversarial runtime proof. Tried to
  CONSTRUCT a cross-account leak, not just confirm the happy path.

## VERDICT: **PASS — no cross-account read or write path found. Clear to ship Wave 2.**

No leak. Every Wave-2 surface is fail-closed by the `BelongsToAccount` global scope
(merchant) or fail-loud by `PlatformGuard` (platform). The one sanctioned global-scope
bypass lives in exactly three audited, super-admin-guarded seams and nowhere else.
Money-safety (the admin credit-adjust nonce race the prior gate flagged) is closed and
proven. 433 tests pass (1404 assertions), including 10 new adversarial isolation tests
added by this audit.

---

## 1. Model census — every tenant model carries `account_id` + `BelongsToAccount`

| Model | Classification | `BelongsToAccount` | Result |
|---|---|---|---|
| `Account` | TENANT ROOT (not scoped — correct) | n/a | ✓ |
| `Site` | tenant-owned | yes (`Site.php:24`) | ✓ |
| `Product` | tenant-owned | yes (`Product.php:27`) | ✓ |
| `ProductVariant` | tenant-owned | yes (`ProductVariant.php:20`) | ✓ |
| `Generation` | tenant-owned | yes (`Generation.php:32`) | ✓ |
| `EndUser` | tenant-owned | yes (`EndUser.php:28`) | ✓ |
| `CreditLedger` | tenant-owned | yes (`CreditLedger.php:26`) | ✓ |
| `CreditPurchase` | tenant-owned | yes (`CreditPurchase.php:27`) | ✓ |
| `ActivityEvent` | tenant-owned | yes (`ActivityEvent.php:19`) | ✓ |
| `User` | GLOBAL allow-list (auth resolves before tenant; super-admins global) | no (by design — TS-TENANCY-003) | ✓ |
| `AiModel` / `AiOperation` / `Prompt` / `PlatformSetting` | GLOBAL allow-list (control-plane catalogs) | no (by design) | ✓ |

`app/Support/GlobalModels.php::ALLOW_LIST` equals exactly the documented global set.
No tenant model is exempt; no non-allow-listed model is unscoped. **Census: ✓ clean.**

## 2. Static hunt — forbidden constructs in product code

| Sweep | Result |
|---|---|
| `withoutGlobalScope(s)` CALL (not comment) | **3 hits, all sanctioned:** `PlatformSiteQuery.php:39`, `PlatformCreditLedgerQuery.php:36`, `PlatformActivityQuery.php:35`. Every other match in `app/` is a comment NEGATING it. Runtime-enforced by `PlatformSiteQueryTest::test_platform_site_query_is_the_only_new_withoutglobalscope_bypass` (greps `app/`, asserts the set == the three seams). ✓ |
| Bare `User::query()/all()/where()/find()/first()` in merchant code | **NONE.** Every `EndUser::` hit is the tenant-scoped `BelongsToAccount` model, not `User`. The only `User` query scope is `User::scopeForAccount` (the TS-TENANCY-003 isolation tool). ✓ |
| `DB::table()/select()/statement()` on a tenant table | **3 hits, all integer/aggregate-only seams (no hydrated PII):** `PurchaseRouter.php:38` (returns only `account_id` — routing), `CostsMetricsBuilder.php:40` (platform `COUNT/SUM` aggregate), `SiteRouter.php:39` (returns only `account_id` — routing). Plus `HealthController.php:58` (`select 1`). ✓ |
| `Tenant::set()/bind()` outside middleware | **NONE.** All binding is `Tenant::run()` (clears in `finally`). `Tenant::current()` is read only inside the `BelongsToAccount` scope itself. ✓ |
| Hardcoded markup (`2.5`/`* 2.5`) on a charge path | **NONE** (Wave-1 confirmed; no new charge path in Wave 2; admin-adjust takes an explicit signed amount, no markup). ✓ |
| Float money on the charge path | **NONE.** Integer micro-USD; floats only in display-only `usd()` helpers. ✓ |

**Static hunt: ✓ clean.**

## 3. Runtime proof — merchant surfaces (adversarial)

Every record-bound merchant page resolves `{record}` through the resource's
`getEloquentQuery()` (Filament `resolveRecordRouteBinding`), which honors the global
scope; a foreign id → `null` → `ModelNotFoundException` (404). Scalar-id +
`findOrFail`/`find` is the pattern everywhere; **no manual `where(account_id)` is relied
on, and no `withoutGlobalScopes()` is used.**

| Surface | Isolation mechanism | Proof |
|---|---|---|
| `SiteResource` (list/view/embed/regenerate) | global scope; `ViewSite` record-bound; `Product::where(site_id, $record)` double-scoped | `ScanReviewAndEmbedTest`, **new** `MerchantResourceRecordIsolationTest::test_view_site_hub_404s_on_a_foreign_account_site` |
| `EndUserResource` (leads list + `ViewEndUser` attempt history) | global scope; CSV export uses `Auth::user()->account`; `LeadAttemptHistory` binds `Tenant::run($endUser->account_id)` | `LeadsExportAndHistoryTest`, **new** `…::test_view_end_user_lead_card_404s_on_a_foreign_account_lead` + `…::test_lead_attempt_history_is_isolated_to_the_leads_own_account` |
| `CreditLedgerResource` (read-only ledger) | global scope; `canCreate/Edit/Delete = false` | `CreditsGalleryPrivacyTest::test_a_foreign_accounts_ledger_rows_are_not_listed` + `…::test_ledger_is_read_only` |
| `ReviewProduct` page (scan-review + confirm) | scalar ids + `findOrFail` under bound tenant; gate re-evaluated server-side in `ConfirmScanAction` | `ScanReviewAndEmbedTest::test_a_foreign_accounts_product_is_not_reachable` + confirm-gate tests |
| `Gallery` page | `find` under bound tenant (foreign → null → empty); `MerchantGalleryQuery` runs in `Tenant::run($site->account_id)` | `CreditsGalleryPrivacyTest`, `MerchantGalleryQueryTest::test_gallery_is_tenant_isolated_account_b_cannot_see_account_a` |
| `PrivacySettings` page | `find` under bound tenant; `SiteSettingsService` force-fills only 4 whitelisted columns (never `site_key`/`widget_secret`/`allowed_origins`) | `CreditsGalleryPrivacyTest::test_privacy_form_only_resolves_the_merchants_own_site` + validate-then-persist |
| `BuyCredits` page | account = `Auth::user()->account`; `PurchaseInitiator` stamps under `Tenant::run` | `CreditsGalleryPrivacyTest` checkout tests |

Control tests (`own_site_hub_renders`, `own_lead_card_renders`) prove the 404s are
isolation, not blanket failure. **Merchant surfaces: ✓ PASS.**

## 4. Runtime proof — domain queries the merchant screens call

- **`MerchantGalleryQuery::forSite()`** — runs the read inside `Tenant::run($site->account_id)` through the global scope; B's gallery contains none of A's generations. The thumbnail-resolution path swallows ANY storage `\Throwable` into a `purged` placeholder (`[false, null]`) — it can never surface another account's object or a signed URL. Proven by `MerchantGalleryQueryTest` + **new** `…::test_a_thrown_storage_exception_degrades_to_purged_never_a_foreign_object` (ANTI-THEATER: removing the `try/catch` makes the storage exception propagate as a 500 → test RED; restored → green).
- **`ConfirmScanAction::confirm()`** — re-runs the no-auto-approve gate server-side inside `Tenant::run($product->account_id)`, re-loading the product via `findOrFail` in that scope. A merchant of B can never obtain A's product (the page's `findOrFail` 404s upstream), so the action is never reached with a foreign product. Variant sync stamps `account_id => Tenant::id()` (the product's account), never the payload. Proven by `ScanReviewAndEmbedTest`.
- **`SiteSettingsService::update()` / `SiteKeyRegenerator::regenerate()`** — operate only on the `Site` handed in, which the merchant page resolves under the bound tenant (foreign → null/404). `forceFill` writes only the whitelisted columns; `widget_secret` is never touched or returned. Proven by `SiteSettingsServiceTest`, `SiteKeyRegeneratorTest`, `ScanReviewAndEmbedTest::test_regenerate_rotates_the_public_key_without_touching_the_secret`.

**Domain queries: ✓ PASS.**

## 5. Runtime proof — platform seams (the ONLY sanctioned cross-account reads)

`PlatformSiteQuery`, `PlatformCreditLedgerQuery`, `PlatformActivityQuery` each call
`PlatformGuard::assert()` (resolves super-admin from `Auth` ONLY — never the request)
**before** any `withoutGlobalScope(AccountScope::class)`. A merchant, an unauthenticated
caller, or a merchant with a bound tenant all fail loud with `PlatformAccessRequiredException`.

- Super-admin reads across all accounts: `PlatformSiteQueryTest`, `PlatformReadSeamsTest` (super-admin sees A+B).
- Merchant/unauth/bound-tenant denied: `PlatformSiteQueryTest` (4 cases) + `PlatformReadSeamsTest` (ledger + activity, incl. the shared-guard test) — all expect the typed exception.
- The three seams are the ONLY `withoutGlobalScope` call-sites: grep-proven at runtime by `PlatformSiteQueryTest::test_platform_site_query_is_the_only_new_withoutglobalscope_bypass`.
- Platform `SiteResource` is read-only (`index` only) and routes `getEloquentQuery()` through `PlatformSiteQuery::withAccount()` — no inline bypass.

**Platform seams: ✓ PASS.**

## 6. TS-TENANCY-003 — `User` is allow-list-exempt (a bare `User::` read is GLOBAL)

- **No bare `User::` read exists in ANY merchant Filament code.** Grep across `app/` for `\bUser::(query|all|where|find|first|get|count|pluck)` returns NONE. The account is taken only from `auth()->user()->account` / `Auth::user()->account` (BuyCredits, EndUser export).
- Back-to-back two-account proof: `MerchantPanelBindingTest::test_two_merchants_back_to_back_never_cross_contaminate` (owner-A sees only A, owner-B only B) + `…::test_merchant_sees_only_their_own_account_across_every_tenant_model` (all 5 tenant models) + the fail-closed paths (no auth user / super-admin-without-account → empty set, never another account's rows).

**TS-TENANCY-003 (Phase-8 release-blocker entry): the Phase-8 call-site enforcement is
satisfied — no bare merchant `User` read landed. The entry's open condition (a
merchant User surface + a back-to-back two-account read test) is met by
`MerchantPanelBindingTest`; recommend the archivist move TS-TENANCY-003 to `resolved`.**

## 7. Money-safety (saas-credits-billing lane)

- **`PlatformCreditAdjustment::apply()`** writes only through `CreditLedgerService::adjustment()` (one append-only `adjustment` row via the row-locked `append()` writer) — **never a bare balance**. Integer micro-USD. Downward adjust floored at 0 (`clampToFloor`). `account_id` is stamped by `BelongsToAccount` under `Tenant::run($account)`, so an adjustment can never land on the wrong account. Guarded by `PlatformGuard` (super-admin only).
- **Idempotency nonce fix (the prior gate's per-call-UUID race) — VERIFIED CLOSED in `AccountResource.php`.** The adjust action's form carries `Hidden::make('idempotency_nonce')->default(fn () => Str::uuid())` (evaluated once per form open), and the handler passes `reference: $data['reference'] ?: $data['idempotency_nonce']` — a STABLE anchor folded into the deterministic ledger key `adjustment:{account}:admin-adjust:{ref}`. A double-submit reuses the same nonce → `CreditLedgerService` returns the existing row → ONE ledger row.
  - **New runtime proof:** `AdminCreditAdjustNonceAndMediaIsolationTest` drives the REAL Livewire table action twice with identical form data → exactly one adjustment row, balance moved once (`test_double_submit_with_a_stable_nonce_writes_exactly_one_ledger_row`); a typed reference also anchors across distinct opens (`…_a_typed_reference_anchors_…`); two genuine separate opens are two adjustments (`…_distinct_form_opens_…`); a source guard forbids reverting to a per-call UUID (`…_anchors_idempotency_on_the_nonce_not_a_per_call_uuid`).
  - **ANTI-THEATER PROVEN:** patching the call site to `reference: (string) Str::uuid()` turns the double-submit, typed-reference, AND source-guard tests RED (the prior race reproduced); restored → green.
- **Lead-card + gallery media degradation cannot leak.** A thrown storage exception → `purged` placeholder + null URL, scoped to the caller's own generation (proven in §4, anti-theater verified).

**Money-safety: ✓ PASS.**

## 8. Tests

- **Full suite:** `php artisan test` → **433 passed (1404 assertions)**, 0 failures (two consecutive clean full runs; deterministic).
- **Targeted isolation/tenancy filter** (`Tenancy|Isolation|Platform|Gallery|Sites|Widget`) → **162 passed (534 assertions)**, 0 failures.
- **New tests added by this audit (10, in `tests/Feature/Tenancy/`):**
  - `MerchantResourceRecordIsolationTest` (5): foreign-account `ViewSite` 404, foreign-account `ViewEndUser` 404, own-site/own-lead control renders, lead-attempt-history account isolation.
  - `AdminCreditAdjustNonceAndMediaIsolationTest` (5): double-submit→one row, typed-reference anchor, distinct-opens→two rows, source-level nonce-anchor guard, storage-exception→purged-never-foreign-object.
- **No `app/Filament/*` or `app/Domain/*` feature code was modified** — tests only. (Temporary break/restore probes were reverted; the working tree's feature code is unchanged from the audited state.)

### Note on a Windows-only test flake (NOT an isolation defect)
On one interleaved full run, 3 tests errored with
`FilesystemIterator::__construct(...testing/disks/s3...): cannot find the file` — the
known `Storage::fake('s3')` directory-teardown race on Windows (one test's storage
cleanup removes a dir another test is iterating). Each affected test PASSES in isolation
and on a clean full run (confirmed: `AccountSuspendRestoreTest` → 4/4 in isolation; two
full runs → 433/433). It is an environment artifact, not a tenancy or money defect, and
does not affect the verdict. Suggest the infra/test owner serialize the S3-fake teardown
(or use a per-test disk root) to remove the flake.

---

## Findings to hand off

- **NONE blocking.** No cross-account read/write path found on any Wave-2 surface.
- **Non-blocking (archivist):** TS-TENANCY-003 can move `open → resolved` — its Phase-8
  condition (a merchant User surface with a back-to-back two-account read test) is now met
  and no bare merchant `User` read shipped.
- **Non-blocking (infra/test owner):** the Windows `Storage::fake('s3')` teardown race
  (§8) should be serialized to stop the intermittent 3-test flake.

**Tenant isolation (Phase 8 Wave 2): GREEN — clear to ship.**
