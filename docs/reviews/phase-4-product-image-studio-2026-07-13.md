# Phase 4 — Bulk AI image generation & review (Product Image Studio) — REVIEW

## 2026-07-13 — Phase 4 gate — VERDICT: BLOCKED

Reviewer: code-review-gatekeeper
Owner under review: laravel-backend
Contract: CLAUDE.md, ARCHITECTURE.md, approved plan (breezy-weaving-kurzweil.md:80-87)

### Scope
app/Domain/ProductImages/* (StartProductImageBatch, SubmitProductImageJob, PollProductImageJob,
ProductImageFinalizer, ProductImageReview, SourceImagePicker, BatchPlan, BatchResult, ReviewTile);
app/Domain/Ai/{Contracts/AsyncImageGenerationProvider, AsyncImageTicket, AsyncImagePoll,
ProductImageCaller, ProductImageSubmission, ProductImageResult}; app/Models/{ProductAsset,
ProductImageBatch}; app/Filament/Merchant/Pages/ProductImageStudio.php + blade +
resources/css/filament/shared/components/product-studio.css; lang/{en,he}/product_images.php;
migrations 2026_07_13_1200{00,01,02}; extended: FalImageClient, ReservationManager::renew(),
IdempotencyKey::forProductAsset(), CreditLedger::REFERENCE_PRODUCT_ASSET,
MediaStorage::storeProductAsset(), AiControlPlaneSeeder::seedProductImageOperations(), AiOperation,
ActivityEvent, GenerationFailureCode.

### Sweeps run
withoutGlobalScopes in the Phase-4 surface (clean — the only repo hits are the audited
app/Domain/Platform/* seam) · raw DB::table/statement on a tenant table (clean) · non-deterministic
idempotency in a charge path (ONE HIT — see blocker 1; the random_int hits are backoff jitter, not
keys) · hardcoded model id / prompt / quality / ratio in the ProductImages surface (clean) ·
hardcoded markup (clean) · Blade::render (clean; every prompt path is strtr) · secrets in
widget/browser (clean) · inline style= and arbitrary Tailwind in the studio blade + CSS (clean) · raw
status writes outside transitionTo() (clean) · EN/HE mirror (23/23 files 1:1; product_images 60/60) ·
GlobalModels::ALLOW_LIST correctly EXCLUDES ProductAsset + ProductImageBatch.

### Tests run
vendor/bin/phpunit -> OK (1091 tests, 3928 assertions). The AiModelCostHintTest failure recorded in the
Phase-3 addendum is RESOLVED (Seedream was removed from PRODUCT_IMAGE_ALT_MODELS,
AiControlPlaneSeeder.php:100-103 — the right fix: BytePlus needs a key + a confirmed per-image price
before it can be an offered default).

### Mutation verification — 15 guards mutated (the agent claimed 7). FOUR STAY GREEN.

| # | Guard | Mutation | Result |
|---|-------|----------|--------|
| G1 | Deterministic-key skip (double-clicked BATCH) | StartProductImageBatch:261 exists() check removed | RED |
| G2 | Poll ledger pre-check | PollProductImageJob:182 hasCharge check removed | RED |
| G3 | Submit-once wall (provider_request_id) | SubmitProductImageJob:237 wall removed | RED |
| G4 | Reservation renew() on each tick (TS-CREDITS-008) | ReservationManager:134 put() removed | RED |
| G5 | Release-on-failure | ProductImageFinalizer:165 ledger->release() removed | RED |
| G6a | failed() handler — SUBMIT job | SubmitProductImageJob:107 gutted | **GREEN — THEATRE** |
| G6b | failed() handler — POLL job | PollProductImageJob:84 gutted | RED |
| G7 | Double-charge wall in succeed() | ProductImageFinalizer:101 forced false | **GREEN** (see S5 — not a hole) |
| G8 | Flat-rate cleared-price fails CLOSED | SubmitProductImageJob:144 flatRatePriceMissing forced false | **GREEN — THEATRE** |
| G9 | Tenant scope on ProductAsset | BelongsToAccount removed | RED |
| G10 | Tenant scope on ProductImageBatch | BelongsToAccount removed | RED |
| G11 | STORE-BEFORE-CHARGE | ProductImageFinalizer:84 storage-failure branch neutered | **GREEN — THEATRE** |
| G12 | Cost-unavailable never charges | ProductImageFinalizer:69 forced false | **GREEN — THEATRE** |

Four behavioural probes (temporary test file, deleted; the tree was restored byte-exact and re-verified
green at 542 tests across every mutated file):
- PROBE 1 — TWO Regenerate clicks -> **2 assets minted** (=> 2 charges). See blocker 1.
- PROBE 2 — succeed() called TWICE on one asset -> **1 charge row, balance debited once**
  (5_000_000 -> 4_902_500), reserved 0. The ledger key pre-check + unique index hold underneath.
- PROBE 3 — SubmitProductImageJob::failed() after a reserve -> reserved 250_000 -> **0**, asset failed,
  0 charges. The guard's CODE is correct; only its TEST is missing.
- PROBE 4 — a LATE duplicate poll tick after finalize -> hold NOT resurrected, reserved 0, charges 1.
  **The TS-CREDITS-008 fix HOLDS.**

### Blocking

1. **DOUBLE-CHARGE — app/Filament/Merchant/Pages/ProductImageStudio.php:250** (with the bare
   wire:click="regenerate(...)" at product-image-studio.blade.php:139).
   The clientRequestId is REQUEST_REGENERATE_PREFIX . uniqid(..., true) — a fresh RANDOM segment minted
   **per INVOCATION**, so the deterministic asset key varies on every CLICK, not per merchant INTENT. A
   double-clicked Regenerate therefore creates TWO assets, submits TWO renders and writes TWO charge
   rows — PROVEN by probe (2 assets from 2 clicks). The button has no confirmation and no
   disable-on-loading. Violates ARCHITECTURE.md "Idempotency keys (deterministic — never random)" and
   "Never charge twice for one generation", plus CLAUDE.md money-safety. Varying the key on an explicit
   Regenerate is CORRECT and required by the plan (:86) — but the client_request_id layer exists
   precisely to collapse the accidental repeat of ONE click, and here it cannot, because the SERVER
   mints it. The batch rail is safe (the constant REQUEST_BATCH); only Regenerate is exposed.
   FIX: derive the regenerate segment deterministically from the merchant INTENT — e.g.
   regen-{sourceAssetId}-{n} where n counts the existing regenerations of that product+operation+source
   — so two clicks collide on the unique idempotency_key, createAsset() returns null, and the second is
   skipped, while a DELIBERATE later regenerate still varies the key. Pin it: two back-to-back
   regenerate() calls -> ONE asset, ONE charge.

2. **TEST THEATRE (§3.7) — SubmitProductImageJob.php:107-125, the failed() handler.**
   Gutting it leaves the entire ProductImages suite GREEN. It is the ONLY release for any throw between
   the reserve (:170) and the hand-off (:207): a failed asset save (:171), an illegal transitionTo
   (:172), a failed post-submit save (:191), or a Redis outage on the poll dispatch (:207) each strand
   accounts.reserved_micro_usd — spendable credit destroyed with no ledger row and no alarm. PROBE 3
   proves the guard CODE is correct, so the fix is one test, not a rewrite.
   ProductImageMoneyPathTest:344 exercises only the POLL job failed() handler.
   FIX: the twin of :344 for SubmitProductImageJob — reserve, then call failed(), assert reserved -> 0,
   asset terminal, ZERO charge rows.

3. **TEST THEATRE (§3.7) — the COST-HONESTY chain is entirely unpinned.** Two independent money guards
   both survive mutation:
   - SubmitProductImageJob.php:144 (flatRatePriceMissing) forced to false: suite stays GREEN.
   - ProductImageFinalizer.php:69 (cost available / costUsd null) forced to false: suite stays GREEN.
   This is the gate criterion "a flat-rate model with a cleared price must fail CLOSED, never charge at
   0". Both guards read correctly today; nothing makes the next edit that weakens them go red. The
   try-on rail HAS this test (GenerateTryOnJobTest); the product-image rail does not.
   FIX: (a) clear the fal model cost_hint AND the operation estimate -> the asset is CANCELLED with
   AI_COST_NOT_CONFIGURED, the provider is never called, no charge; (b) a COMPLETED poll whose cost is
   unresolvable -> FAILED with COST_UNAVAILABLE, hold released, no charge row.

4. **TEST THEATRE (§3.7) — STORE-BEFORE-CHARGE unpinned. ProductImageFinalizer.php:76-88.**
   Neutering the storage-failure branch leaves the suite GREEN: no test ever drives a storage failure.
   The finalizer own stated law — "a credit is never debited for an image the merchant cannot see" — is
   therefore unguarded, and an edit that charges despite a failed store would ship green.
   FIX: make MediaStorage::storeProductAsset throw -> assert asset FAILED (STORAGE_FAILED), hold
   released, ZERO charge rows.

### Suggestions

5. ProductImageFinalizer.php:101 — the succeed() double-charge wall survives mutation, but it is NOT a
   hole: PROBE 2 proves two full finalizes still produce exactly ONE charge row and ONE debit, because
   the CreditLedgerService::charge() key pre-check + unique index hold underneath. Genuine
   defense-in-depth, merely unpinned. Add a direct test (call succeed twice) so layer 2 has its own red.
6. PollProductImageJob is deliberately NOT ShouldBeUnique, and lockRenewAndClaim():178 rejects only a
   NON-processing asset — so two concurrent ticks on a processing asset BOTH pass, both poll, and both
   can reach finalize. Money-safe (5 above), but on a split result (tick A sees FAILED, tick B sees
   COMPLETED) the succeed() of tick B passes the isSucceeded() wall on a FAILED asset, writes a charge,
   then throws on the illegal failed -> succeeded transition and rolls the whole transaction back. The
   money survives; the log gets an unexplained exception. Claim the tick under the row lock, or widen
   the wall from isSucceeded() to isTerminal().
7. SubmitProductImageJob.php:221-243 — lockAndPrecheck() COMMITS and releases the row lock BEFORE the
   reserve and the submit. The class docblock (:48-50) claims "the row lock + the provider_request_id
   wall make a second submit impossible" — stronger than the code: the asset stays pending with a null
   provider_request_id across the resolver call, an HTTP fetch of the source photo (:156), the gate and
   the reserve. ShouldBeUnique is the real guard here. Claim the asset (transitionTo PROCESSING) inside
   the lock, or soften the docblock to what the code actually guarantees.
8. SubmitProductImageJob.php:52 — no uniqueFor, so the unique lock never expires: a SIGKILLed worker
   holds it forever and that asset can never be re-dispatched. Same shape as GenerateTryOnJob and
   GenerateBannerJob (pre-existing, not a Phase-4 regression) — but both Shopify sync jobs DO set it
   (3600s / 900s). Worth aligning.
9. NO RESERVATION REAPER EXISTS anywhere (a sweep of app/Console + routes/console.php for
   reserved_micro_usd returns nothing). A hard worker kill (SIGKILL/OOM, where failed() never runs)
   strands reserved_micro_usd forever with no live hold: spendable credit destroyed, no ledger row, no
   alarm. Pre-existing, but Phase 4 materially widens the window (a 10-minute poll budget x N assets per
   batch, versus 70s for one try-on). A scheduled sweep (assets stuck processing past the poll budget ->
   fail + releaseByKey) closes it.
10. migrations/2026_07_13_120002 calls AiControlPlaneSeeder::seedProductImageOperations(). It IS
    idempotent (updateOrCreate on operation_key / model_id / prompt scope) and safe to re-run on a prod
    DB that already holds the rows, and the pattern is pre-existing (8 storyboard migrations do it). The
    latent trap: a migration is immutable HISTORY, the seeder is mutable CODE — the day the method is
    renamed, or starts writing a column added by a LATER migration, migrate-from-scratch breaks. It has
    already collided once (the AiModelCostHintTest failure). Recommend the archivist owns the class.
11. ProductImageFinalizer::succeed() calls the ledger charge() INSIDE its own DB::transaction (:95-143),
    but the charge() docblock says the reservation release runs "Outside the txn" — here it does not,
    and the cache pull() does not roll back. A rollback after charge() would leave the key deleted while
    reserved_micro_usd is restored -> a permanently stranded hold. Unreachable today (ActivityRecorder
    ::record swallows everything, :39-57) and identical to GenerateTryOnJob:462 and
    GenerateBannerJob:360 — pre-existing, recorded for the archivist, not a Phase-4 deviation.
12. FalImageClient::submitAsync():183 accepts an idempotency key and silently discards it. The agent is
    right that fal exposes no idempotency key, and the DB-backed submit-once wall is the correct pivot —
    but the plan verification line "a re-submit with the same provider idempotency key cannot
    double-generate" (plan:84,113) is now UNACHIEVABLE and must not be assumed later. Document it on the
    interface; a dead parameter invites the wrong assumption.

### Nits
13. ProductImageStudio.php:416-430 — operationOptions() builds a labels map from AiOperation and can
    never use it (PRODUCT_IMAGE_KEYS is non-empty, so the options array is never empty). Dead branch.

### Verified PASS (with evidence)

- MONEY PATH. gate -> reserve -> submit -> poll -> charge-on-success / release-on-EVERY-failure is
  mutation-proven: the reserve is strictly BEFORE the provider call (SubmitProductImageJob:170 before
  :175); the charge row is written ONLY in the success branch and ONLY after the bytes are stored
  (ProductImageFinalizer:77 then :107); failure, poll-budget exhaustion (60 x 10s -> POLL_TIMEOUT) and
  an escaped exception all release the hold and write NO charge (G5, G6b RED).
- **THE TS-CREDITS-008 HOLD-RENEWAL FIX HOLDS.** renew() cannot resurrect a hold after finalize: it runs
  inside the row-locked transaction of lockRenewAndClaim(), BEHIND a not-processing check, so a poll
  arriving after a terminal finalize returns null before renew() is ever reached — PROVEN (PROBE 4: hold
  not held, reserved 0, charges 1). renew() is deliberately an unconditional put that never touches
  accounts.reserved_micro_usd, which is correct in BOTH directions (key live -> extend the TTL; key
  lapsed -> restore the invariant "key present == column holds this estimate", so the terminal release
  still decrements). The only residual strand path is a hard worker kill — suggestion 9.
- NO DOUBLE-CHARGE on the BATCH rail. The deterministic key (IdempotencyKey::forProductAsset — sha1 over
  product, source_image_hash, operation, prompt_version, model and the ksorted params, plus a CONSTANT
  client_request_id) + the UNIQUE index on product_assets.idempotency_key + the createAsset() pre-check
  + ShouldBeUnique + the row lock + the ledger pre-check all hold (G1/G2/G3 RED; PROBE 2: two finalizes
  -> one charge). The gap is Regenerate ONLY (blocker 1).
- SUBMIT-ONCE. A row-locked asset that already carries a provider_request_id never re-submits (G3 RED);
  a network blip retries the POLL, never the submit (tested); the ticket persisted at submit time is the
  anchor. The residual concurrency window is suggestion 7 (ShouldBeUnique covers it today).
- TENANT SAFETY. ProductAsset and ProductImageBatch both carry account_id NOT NULL (constrained,
  cascade) + BelongsToAccount; removing the trait from EITHER goes RED. Both are correctly ABSENT from
  GlobalModels::ALLOW_LIST. Both jobs extend TenantAwareJob with an explicit int accountId;
  SubmitProductImageJob::uniqueId():91-100 resolves the key by binding the EXPLICIT account through
  Tenant::run and the normal fail-closed scope — never withoutGlobalScopes(). Every review-grid read
  (ProductImageReview:59-133) and the studio page run inside the global scope with an explicit site
  filter. Cross-account back-to-back worker isolation is tested (ProductImageIsolationTest:67).
- AI IS CONFIGURABLE. Zero model id / prompt / quality / aspect-ratio literals anywhere in the
  ProductImages surface or the studio page (swept); everything resolves through AiOperationResolver;
  prompts are substituted with strtr (ProductImageCaller:164), never Blade::render. The two operations
  (packshot_generation, on_model_generation) are independently modelled, prompted and multiplied, and are
  Super-Admin tunable from the DB with no redeploy (tested, ProductImageBatchTest:185).
- REVIEW IS EDITORIAL, NOT FINANCIAL. A rejection writes no refund row (tested,
  ProductImageReviewTest:69); review is legal only on a SUCCEEDED asset (guarded, tested); every move is
  a guarded transition + an activity event. The UI states the law before the batch runs, in BOTH
  languages (product_images.php:16 EN + HE): charged when the AI succeeds, a later rejection does not
  refund, a failed generation is never charged.
- MEDIA + PRIVACY. Results are stored PRIVATE under accounts/{id}/sites/{id}/product_assets/{id}/
  (MediaStorage:132-147) and reach the panel only through a short-lived SIGNED url (ReviewTile:31).
- CONVENTIONS. CONST-at-top in every new file; ZERO inline CSS and ZERO arbitrary Tailwind values (the
  progress-bar fill is a CLASS, not a style attribute); product-studio.css opens with its token
  reference block and uses logical properties throughout; EN and HE mirror 1:1 across all 23 lang files
  (product_images 60/60).

### Gate
GATE: BLOCKED — 4 blocking findings (double-charge x1 on the Regenerate rail, test-theatre x3 on the
money path). Owner: laravel-backend. Blocker 1 is a code fix + a test; blockers 2-4 are TESTS ONLY —
all three guards are correct as written, so pin them, do not rewrite them. Phase 4 may not advance
until re-review.

### Re-review
REQUIRED. Owner: laravel-backend (1, 2, 3, 4).

### Recurring -> troubleshooting-archivist
- **CLAIMED-GUARD-WITH-NO-RED-TEST, 4th occurrence.** This phase shipped FOUR money guards that stay
  green when deleted (the submit failed() handler, flat-rate-price-missing, cost-unavailable,
  store-before-charge). The pattern is now unmistakable: the guard is written, a HAPPY-path test is
  written next to it, and the guard is never deleted to confirm the test would catch its removal. The
  registry entry should be: before claiming a guard, DELETE it and run the suite; if it stays green, the
  guard is undefended.
- **A hold that LAPSES is worse than a hold that leaks** (TS-CREDITS-008): a TTL-expired cache key makes
  release() a no-op and strands reserved_micro_usd with NO ledger row — silently destroying spendable
  credit. The fix pattern that works: renew unconditionally, under the SAME row lock the terminal
  release takes, behind a non-terminal status check. Now proven. The open half is the missing reaper
  (suggestion 9).
- **A provider that does not support idempotency keys** (fal): the plan assumed one existed. When the
  upstream lacks the primitive, the DB row + a unique key becomes the wall. Record it so the next async
  provider integration does not re-assume it.
- **A migration that calls a seeder** (suggestion 10): idempotent and pre-existing, but it makes mutable
  code part of immutable history and it already broke one committed money-safety test.

---

## 2026-07-13 (re-review) -- Phase 4 gate -- VERDICT: PASS-WITH-SUGGESTIONS

Reviewer: code-review-gatekeeper
Owner under review: laravel-backend
Clears: the BLOCKED entry above (2026-07-13, blockers 1-4). A NEW entry; the prior verdict stands as history.

### Scope
NEW: app/Domain/ProductImages/RegenerateProductImage.php;
database/migrations/2026_07_13_140000_add_source_asset_id_to_product_assets.php;
tests/Feature/ProductImages/ProductImageRegenerateTest.php.
CHANGED: StartProductImageBatch (sourceAssetId + UniqueConstraintViolationException catch), ProductAsset
(TERMINAL_STATUSES, source_asset_id, sourceAsset/regenerations), BatchResult (DENIED_STILL_RENDERING + deniedUnplanned),
ProductImageStudio (uniqid REMOVED; delegates to the domain), product-image-studio.blade.php (wire:confirm +
wire:loading.attr=disabled), lang/en+he/product_images.php, ProductImageMoneyPathTest (+4 mutation guards).
PHASE-3 SUGGESTIONS APPLIED: SyncShopifyProductJob::park() (redispatch + park-indexed uniqueId),
StartShopifySync::selection() (bounded, TRUNCATION_SELECTION_MAX, dropped count reported).

### Tests run
vendor/bin/phpunit -> OK (1103 tests, 4034 assertions). Baseline 1091/3928. +12 tests, +106 assertions. Claim CONFIRMED.

### Mutation verification -- 11 guards mutated (each isolated, tree restored hash-verified between runs)

| #  | Guard | Mutation | Result |
|----|-------|----------|--------|
| M1 | SubmitProductImageJob::failed() (:107-125) [blocker 2] | body gutted (early return) | RED (MoneyPathTest:445 - asset not terminal) |
| M2 | flatRatePriceMissing() (:144) [blocker 3a] | forced false | RED (:474 - processing, not cancelled: it RENDERED) |
| M3 | ProductImageFinalizer cost check (:69) [blocker 3b] | forced false | RED (:506 - TypeError charging from a null cost) |
| M4 | STORE-BEFORE-CHARGE branch (:76-88) [blocker 4] | catch neutered, charges anyway | RED (:536 - succeeded with a dead disk) |
| M5 | RegenerateProductImage::intentId() (:79-87) [blocker 1] | reverted to uniqid() | RED x2 (2 assets / 2 charges) |
| M6 | Still-rendering refusal (:58-60) | check removed | RED (RegenerateTest:211) |
| M7 | createAsset() exists() pre-check (:283) | removed | GREEN BY DESIGN - the UNIQUE index + the catch carry the whole wall alone. Proves layer 2 is live, not decorative. |
| M8 | SubmitProductImageJob implements ShouldBeUnique (:52) | interface removed | GREEN - UNPINNED (suggestion 14; not a merchant double-charge) |
| M9 | SyncShopifyProductJob implements ShouldBeUnique (:28) | interface removed | RED (the Phase-3 pin holds) |
| M10 | SyncShopifyProductJob::uniqueId() park index (:79) | park segment removed | RED x2 - the throttle test reds too, proving a parked retry WOULD be swallowed by the lock its own predecessor still holds |
| M11 | StartShopifySync::selection() bound (:100-102) | silent slice restored | RED (no silent loss) |

Four adversarial probes (temporary file, deleted; tree restored byte-exact and re-verified green at 242 tests):
- PROBE A (the fast-settling attack): n CAN advance between two clicks -- but ONLY via a settle that never charged
  (cancel: no configured price / gate deny / missing source photo; fail: provider error). A settle that CHARGES needs
  submit -> the 8s FIRST_POLL_DELAY (SubmitProductImageJob:64) -> poll -> finalize, which cannot complete inside a
  double-click window. Probe: the twin was minted, CHARGES STAYED AT 1. No double charge.
- PROBE B (the first render FAILS): n advances, but the failed render wrote NO charge; the retry charges once for the one
  image that actually rendered. A free retry, not a double charge. Probe: 2 charges (1 batch + 1 regen), reserved 0.
- PROBE C (a chain): regenerating a regeneration mints its own key (regen-{child}-0); no collision with the parent.
- PROBE D (concurrency, via M7): with the exists() pre-check deleted the double-click test STILL passes -- the loser of a
  truly concurrent pair hits the UNIQUE index, is caught, and is answered as already-existing (queued=0,
  skippedExisting=1), never a 500 on the money path.

### Blockers 1-4: CLEARED (with evidence)
1. DOUBLE-CHARGE (Regenerate). uniqid() is GONE from ProductImageStudio (:240-245 now delegates). The key is derived in
   the DOMAIN: RegenerateProductImage:79-87, regen-{source}-{settled}, counted over ProductAsset::TERMINAL_STATUSES. Two
   clicks read the same n -> one key -> one asset -> one render -> one charge (M5 RED three ways). The UI adds a confirm
   + disable-while-pending (blade:144-147) as a HINT; the WALL is the domain + the DB constraint.
2. failed() on the submit job: pinned by a REAL throw (a one-shot ProductAsset::saving() model event, MoneyPathTest:75-88)
   fired inside the reserve-to-handoff window. M1 RED.
3. Cost-honesty chain: both halves pinned, both by REAL DB clears (:91-100, :499-504). M2 + M3 RED.
4. Store-before-charge: pinned by a REAL throwing filesystem (Storage::set with a Filesystem mock whose put() throws,
   :102-109 -- the DISK is mocked, never the domain class under test). M4 RED, and with the branch neutered the asset
   SUCCEEDS and the merchant pays for an image that does not exist. Exactly the hole it protects.

### Phase-3 non-regression: CONFIRMED
- A twin dispatch still collapses (identical uniqueId for two parks=0 jobs, ShopifyCatalogSyncTest:477-478).
- A parked retry is NOT swallowed by its own lock (the park index; M10 RED).
- The ShouldBeUnique pin still reds when the interface is dropped (M9 RED).
- No silent selection loss: bounded in selection(), the run is marked TRUNCATION_SELECTION_MAX, the dropped count rides
  back on the typed result, and the merchant is warned (ShopifyProducts.php:276-283; EN+HE keys present). M11 RED.

### Sweeps
withoutGlobalScopes in the Phase-4 surface (clean; the only repo call site is the pre-existing audited
MerchantSiteTenancy:45 tenant-routing seam) - raw DB::table/statement (clean) - non-deterministic idempotency in a key
path (CLEAN: the uniqid is gone) - hardcoded model/prompt/quality/ratio (clean) - hardcoded markup (clean) -
Blade::render (clean) - secrets in logs (clean) - inline style= and arbitrary Tailwind (clean) - raw status writes
outside transitionTo() (clean) - EN/HE mirror 23/23 files 1:1, product_images 62/62 - GlobalModels::ALLOW_LIST still
excludes ProductAsset + ProductImageBatch. Nit 13 (the dead operationOptions branch) is fixed.

### Suggestions (recorded, do not gate)
14. SubmitProductImageJob.php:52 -- the ShouldBeUnique interface is UNPINNED (M8 stays green). Severity reasoning: it is
    NOT a merchant double-charge (the row lock, the ledger pre-check and the unique asset key each still hold it to one
    charge, all RED), so this is defense-in-depth, not a hole -- the same call as suggestion 5. But it IS the only wall
    against a double PROVIDER RENDER (we pay fal twice, out of pocket), because lockAndPrecheck() commits before the
    reserve (suggestion 7). The two Shopify jobs already pin theirs in 3 lines (ShopifyCatalogSyncTest:455/469).
15. StartProductImageBatch.php:182-191 -- a swallowed double-click still opens a ProductImageBatch row (the batch is
    created before createAsset() collides), leaving a stray completed/1-skipped batch that becomes lastBatch() in the
    studio. Cosmetic. Create the batch lazily, or reuse the source batch when a regenerate queues nothing.
16. StartProductImageBatch.php:304 -- the catch on UniqueConstraintViolationException is type-broad. Today product_assets
    carries exactly ONE unique index (idempotency_key, migration :113) so it can mask nothing else; the day a second
    unique index is added, a violation on it would be reported to the merchant as this-image-already-exists. Re-assert
    the key exists before answering null, or pin the assumption in a test.
17. Suggestions 6-12 of the prior entry remain OPEN (not requested). Number 9 (NO RESERVATION REAPER) is still the one
    that matters: failed() now covers every throw (mutation-proven), but a SIGKILL/OOM -- where failed() never runs --
    still strands reserved_micro_usd with no ledger row and no alarm.

### Gate
GATE: GREEN -- 0 blocking findings. All four Phase-4 blockers are fixed AND mutation-proven, and the Phase-3 gate did not
regress (its three pins still red). Phase 4 may advance. Suggestions 14-17 are for the owner to decide.

### Re-review
NOT required.

### Recurring -> troubleshooting-archivist
- CLAIMED-GUARD-WITH-NO-RED-TEST: the loop is CLOSED. All four green-when-deleted guards now go red when deleted, and the
  new tests drive REAL failures (a model-event throw, cleared DB prices, a throwing filesystem) instead of mocking the
  class under test. The registry rule is now demonstrated: before claiming a guard, DELETE it and run the suite.
- A MONEY GUARD MUST LIVE IN THE DOMAIN, NEVER IN THE BUTTON. The double-charge was a server-minted random
  client_request_id in a Filament page. A disabled button is a hint; the wall is a deterministic key derived from the
  merchant INTENT plus a UNIQUE index. The reusable pattern: intent-{parent}-{settled_children} -- it collapses the
  accidental repeat and still lets a deliberate later ask through.
- A SELF-REDISPATCHING JOB MUST CARRY ITS RETRY INDEX IN uniqueId() (the Shopify park index). Without it, the retry
  dispatched from INSIDE handle() collides with the lock its own predecessor still holds and is silently dropped --
  reproduced empirically here (M10).
