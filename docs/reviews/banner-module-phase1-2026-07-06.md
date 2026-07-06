## 2026-07-06T00:00Z — Banner module PHASE 1 (data model + AI generation money-path) — VERDICT: GREEN

Reviewer: adversarial money-safety + tenant-safety review (4 reviewers → per-finding skeptic verify)
Scope: the merchant "Banners" module foundation — 3 tables/models + a new `banner_generation` AI
operation + the generation money-path (clone of GenerateTryOnJob, minus the LeadGate).
New: app/Models/{Banner,BannerAsset,BannerEvent}.php · app/Domain/Ai/{BannerGenerationCaller,BannerResult}.php ·
app/Domain/Banners/{BannerGenerationRequest,StartBannerGeneration}.php · app/Domain/Generation/GenerateBannerJob.php ·
3 migrations + 2 factories + tests/Feature/Banners/GenerateBannerJobTest.php.
Modified (shared): CreditLedgerService::charge()/hasCharge() (+`referenceType`, default=generation) ·
CreditLedger::REFERENCE_BANNER_ASSET · IdempotencyKey::forBanner() (prefix `banner:`) ·
MediaStorage (public storeBannerResult()/publicUrl() + private storeBannerSource()) ·
AiOperation::KEY_BANNER_GENERATION + AiControlPlaneSeeder::seedBannerOperation().

Tests: `--filter=GenerateBannerJobTest` → 8 passed (47 assertions). Adjacent regression suite
(`GenerateTryOnJob|CreditLedgerService|Generation|MediaIsolation|Ai`) → 220 passed — the ledger
`referenceType` change is provably backward-compatible (try-on unchanged).

FOCUS-AREA FINDINGS:
1. MONEY-SAFETY — PASS. GenerateBannerJob follows the LAW: lockAndPrecheck (row lock + ledger
   pre-check on `hasCharge(assetId,'banner_asset')`) → CreditGate (only gate; deny → pending→cancelled,
   no reserve/charge) → reserve-BEFORE-call → store PUBLIC result → charge ONCE (idempotent on the
   `banner:` key, referencing the banner_asset) → release; failure releases the reservation and writes
   ZERO charge rows. Cost never invented (ParsedCost available⇔non-null, guarded on both conditions).
   Markup from `credit_multiplier ?? config`, integer micro-USD. A banner charge and a generation
   charge cannot collide: `hasCharge()` filters BOTH reference_type AND reference_id, and the idempotency
   keys carry distinct prefixes (`banner:` vs `generation:`), so even an identical integer id is disjoint.
2. TENANT-SAFETY — PASS. Banner/BannerAsset/BannerEvent are `account_id` + BelongsToAccount; the job
   extends TenantAwareJob with an EXPLICIT account_id and never infers it (uniqueId binds via Tenant::run,
   no withoutGlobalScopes); StartBannerGeneration stamps the account via the bound tenant; media paths
   lead with account_id. Public banner media is safe: the leaf is a 24-char random token under
   accounts/{acct}/sites/{site}/banners/{asset}/ — unguessable, no enumeration, and marketing content is
   public by intent. The isolation test proves account B reads neither A's banner nor A's asset.
3. CORRECTNESS — PASS. Both state machines are guarded (only canonical transitions). The optional
   reference-image path is null-safe (loadReference); imageDimensions is best-effort (getimagesizefromstring
   guarded). The seed resolves (default_model + is_default AiModel + global `{{brief}}` prompt). Migrations
   index the hot paths; selected_asset_id is deliberately FK-less to avoid a circular constraint;
   banner_events is append-only. The caller builds a 0-or-1-image body for both providers.
4. CONVENTIONS — PASS. CONST-at-top across every new file; queue via config; strtr-not-Blade (the brief is
   substituted via OperationConfig::substituteUser = strtr); naming mirrors the try-on family.

Findings: 3 raised, ALL SUGGESTION/NIT, ALL REFUTED (test-coverage-not-defect). One — the CreditGate-denial
branch had no dedicated test — was APPLIED anyway (it is the single defining gate of the banner money path):
added test_insufficient_credits_cancels_before_reserving_or_calling (asserts pending→cancelled, nothing
reserved, zero charges, Http::assertNothingSent). Suite now 8 tests.

GATE: GREEN — 0 blocking across money-safety, tenant-safety, correctness, conventions. Phase 1 may advance.
Follow-up (infra, non-blocking): a dedicated `banners` Horizon supervisor so merchant banner generation
never contends with shopper try-ons on the `generations` queue (Phase-1 reuses `generations`).
