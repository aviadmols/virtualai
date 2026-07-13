# Shopify Phase 3 — Product sync — REVIEW

## 2026-07-13 — Shopify Phase 3 (product sync) — VERDICT: BLOCKED

Reviewer: code-review-gatekeeper
Owner under review: laravel-backend
Contract: CLAUDE.md, ARCHITECTURE.md, docs/shopify/DECISIONS.md, approved plan (Phase 3)

### Scope
app/Domain/Products/* (PersistProduct, ProductOrigin, ProductSource, PersistResult, VariantKey, ConfirmImportedProducts),
app/Domain/Shopify/Products/* (mapper, source, importer, queries, gid, DTOs, StartShopifySync, SyncShopifyCatalogJob, SyncShopifyProductJob),
app/Domain/Shopify/Webhooks/HandleProductUpdateJob + HandleProductDeleteJob, app/Domain/Generation/ProductFacts.php,
app/Models/ShopifySyncRun.php, app/Filament/Merchant/Pages/ShopifyProducts.php + blade, 4 migrations (2026_07_13_1000*);
changed: ScanProductJob, ScanConstants, Review/ScanReview, ShopifyGraphQLClient, Product, ProductVariant, ActivityEvent,
PlatformSettings, config/shopify.php, AiControlPlaneSeeder, lang/{en,he}/{shopify,activity}.php; tests/Feature/Shopify/*.

### Sweeps run
withoutGlobalScopes in the Phase-3 surface (clean - the only repo hits are the audited app/Domain/Platform/* seam),
raw DB::table/statement/select on a tenant table (clean), Blade::render (clean; every template path is strtr),
hardcoded model id / prompt / quality / ratio (clean), secrets in logs (clean), access token in a URL (clean),
inline style= and Tailwind arbitrary values in the new page/blade (clean), jobs missing an explicit int accountId (clean),
EN/HE mirror (shopify 85/85, activity 34/34, platform 650/650, scan 86/86; every dynamic run_status.* and mode.* key
present in both), GlobalModels::ALLOW_LIST pinned and ShopifySyncRun correctly NOT on it.

### Tests run
vendor/bin/phpunit -> OK (1048 tests, 3604 assertions).

### Mutation verification (8 guards mutated; the agent claimed 7)

| # | Guard | Mutation applied | Result |
|---|---|---|---|
| 1 | Tenant scope on the delete handler | HandleProductDeleteJob:43 -> withoutGlobalScopes() | RED (verified) |
| 2 | Archive-never-delete (product) | ShopifyProductImporter:103 archive() -> delete() | RED (verified) |
| 3 | Selection-run archive guard | ShopifyProductImporter:129 MODE_CATALOG guard removed | RED (verified) |
| 4 | Archive-never-delete (variant) | PersistProduct:178 archive() -> delete() | RED (verified) |
| 5 | Refresh-confirmed / no-auto-approve | PersistProduct:67 statusPreserved guard -> if (true) | RED, 2 tests (verified) |
| 6 | Variant UPSERT key | PersistProduct:155 existing-lookup -> null | RED (verified) |
| 7 | SyncShopifyProductJob ShouldBeUnique | implements ShouldBeUnique removed | GREEN - THEATRE |
| 8 | ConfirmGate blocking-field set | name/price/main_image_url added to OPTIONAL_FIELDS | Scan suite GREEN - unpinned |

Two behavioural probes (temporary test file, deleted after the run):
- shopify.sync.max_pages = 1 on a 2-page store -> run completed, archived = 1, and a still-LIVE product came back
  is_active = false with archived_at set.
- shopify.import.soft_cap = 1 -> StartShopifySync::catalog() opened the run and dispatched the walk anyway.

### Blocking

1. app/Domain/Shopify/Products/SyncShopifyCatalogJob.php:126-134 - a walk TRUNCATED by the max_pages budget
   (hasNextPage still true but pages >= max_pages) falls through to archiveStale() and marks the run completed.
   The premise of archiveStale (anything Shopify did NOT return is gone from the store) is FALSE for a truncated
   walk, so every locally-imported LIVE product beyond the page budget is archived (is_active=false, archived_at set)
   and drops out of the widget. Proven by probe. FIX: sweep only on a genuinely complete walk (guard on NOT
   page->hasNextPage); on budget exhaustion complete the run WITHOUT sweeping and record the truncation on the run
   plus an activity event.

2. app/Domain/Shopify/Products/StartShopifySync.php:26-31,52-65 (with ShopifyProducts.php:210-214) - the SOFT CAP is
   a comment, not a guard. The docblock claims the class owns the soft cap so that one click cannot queue a
   40k-product store into the bulk queue, but catalog() never calls softCap()/exceedsCap(). The only consumer is the
   modal DESCRIPTION text, and the merchant can still press submit. ShopifyImportUiTest:104-121 asserts only the
   return values of softCap()/exceedsCap() - theatre for the claim. This is also the enabling condition for finding
   1. Spec: plan Phase 3 (import all - soft cap 1,000, platform-admin override). FIX: enforce the cap inside
   catalog() (refuse with a typed cap-exceeded result the page renders, or cap the walk and mark the run truncated)
   and add a test proving a cap-exceeding catalog() dispatches NOTHING.

3. app/Domain/Shopify/Products/SyncShopifyProductJob.php:27 (also SyncShopifyCatalogJob.php:36 and
   tests/Feature/Shopify/ShopifyCatalogSyncTest.php:213-216) - ShouldBeUnique is a claimed guard whose test does not
   go red: removing the implements ShouldBeUnique clause leaves the whole 110-test Shopify suite GREEN, because the
   only assertion is on the uniqueId() STRING, which survives the interface removal. FIX: assertInstanceOf(
   ShouldBeUnique::class, job) plus an assertion on uniqueFor, on BOTH sync jobs.

4. app/Domain/Scan/Review/ScanReview.php:44-47 - the deliberate description + product_type -> OPTIONAL change also
   weakens the SCAN rail, and the resulting blocking set is pinned by NO test: adding name and price to
   OPTIONAL_FIELDS leaves the ENTIRE Scan suite green (only the Shopify imageless-import test catches
   main_image_url). Downstream was traced and is SAFE - GenerateTryOnJob:138 falls back to site->product_category
   else product->product_type and AiOperationResolver:388 skips the leg (a global prompt always exists); the seeded
   prompts never reference the product_type placeholder; ProductFacts omits absent facts; ProductPayload:21-22 is
   already nullable; an imageless product STILL blocks (mutation-verified). So nothing crashes - but WHICH fields a
   merchant must review is a product decision (product-ux-architect, per the CLAUDE.md agent table), not a decision
   for the implementing agent, and the gate teeth are now unguarded. FIX: scope the optionality to the authoritative
   rail (a field whose field_confidence.source is ScanConstants::SOURCE_SHOPIFY / confidence 1.0 is never a blocking
   row), OR keep the global change with product-ux-architect sign-off AND pin the exact blocking set with a test
   (name, price, main_image_url and every selector role BLOCK; description, product_type, variants,
   physical_dimensions do NOT) so the next widening goes red.

### Suggestions

5. HandleProductUpdateJob:63 with PersistProduct:74-75 - an unpublished product is archived by the status:active
   catalog walk and then RE-ACTIVATED by the next products/update webhook (persist unconditionally sets
   is_active = true, archived_at = null). It flaps. The mapper already carries raw.shopify.status.
6. SyncShopifyCatalogJob:59,165 - park() uses release(), which burns one of tries = 5; a persistently throttled store
   fails the run (FAILED is terminal and never re-opens). A park is not a failure.
7. StartShopifySync:176 - normaliseGids() silently array_slices to selection_max (250); report the truncation so the
   page can tell the merchant.
8. tests/Feature/Tenancy/ProductScanIsolationSpotCheckTest.php:243-262 - the allow-list is pinned BY HAND; nothing
   asserts that every app/Models class either uses BelongsToAccount or is allow-listed. A reflection sweep would make
   the next unscoped model go red automatically. (Pre-existing; ShopifySyncRun is correctly scoped and cross-account
   tested at ShopifyCatalogSyncTest:239-252.)

### Nits

9. ProductOrigin.php:18-20 - a CONSTANTS header with no constants beneath it.

### Verified PASS (with evidence)

- TENANT SAFETY. Every Phase-3 job carries an explicit int accountId (SyncShopifyCatalogJob:66,
  SyncShopifyProductJob:51, HandleShopifyWebhookJob:46) through TenantAwareJob (final handle() -> Tenant::run,
  cleared in finally); the (int accountId, int receiptId) handler contract is reflection-asserted. A handler bound to
  the wrong account resolves NOTHING - the ShopifyConnection lookup fails closed (mutation 1).
- NO DATA LOSS. Archive-never-delete holds for products AND variants (mutations 2 and 4; the variant test asserts
  generations.product_variant_id still resolves after the archive). Variant upsert-by-key holds (6). A selection run
  archives nothing (3). The old hard variants()->delete() on the scan rail is GONE - a genuine fix.
- NO AUTO-APPROVE. A refresh never re-confirms and never re-drafts (5). Confirm-all-N-imported runs the SAME
  server-side ConfirmGate with force = false and SKIPS blocked products; an empty ConfirmScanInput cannot wipe
  fields, selectors or variants (ConfirmScanAction:82,95 and syncVariants over an empty array).
- THROTTLING. Retry-After is honoured, clamped to max_wait_seconds and floored at 1s (ShopifyGraphQLClient:181-190);
  both the 429 and the 200-with-THROTTLED signal are recognised; a spent budget surfaces the typed CODE_THROTTLED
  that the sync catches to park its cursor; a real GraphQL error is never retried as a throttle; the token never
  enters a URL. The cursor self-redispatch is bounded by max_pages (see blocker 1 for what happens at that bound).
- CONVENTIONS. CONST-at-top throughout; zero inline CSS and zero arbitrary Tailwind values; EN/HE 1:1; no hardcoded
  model id or prompt; the GraphQL search term rides as a typed variable (no injection); the product_details clause is
  substituted by a single-pass strtr, never Blade.

### Re-review
REQUIRED. Owner: laravel-backend (1, 2, 3). Finding 4 needs product-ux-architect to own the semantics decision and
laravel-backend to pin it with a test.

### Recurring -> troubleshooting-archivist
- A claimed guard that exists only as a const plus a comment, with a test that asserts the HELPER RETURN VALUE
  instead of the BEHAVIOUR - recurs twice in this phase (soft cap 2, ShouldBeUnique 3) and has slipped through twice
  before.
- A completeness sweep run on an INCOMPLETE traversal (1) - the archive/reconcile family.

### Addendum — the working tree mutated UNDER this review (suite is now RED, not for a Phase-3 reason)

My first full-suite run (taken before touching anything) was OK: 1048 tests, 3604 assertions.
Every later run is RED with an IDENTICAL 1048/3604 count and ONE failure:

  Tests\Feature\Filament\Platform\AiModelCostHintTest::test_a_byteplus_model_cannot_be_saved_without_a_per_image_price
  (AiModelCostHintTest.php:99 - assertDatabaseMissing ai_models model_id=seedream-5-0-260128)

It reproduces on the SINGLE test in isolation. It is NOT in the Phase-3 surface, and it is not caused by this review:
every file I mutated was restored byte-identical (diff -q verified) and the temporary probe test was deleted.

Cause: a SECOND agent landed PHASE 4 (bulk generation) into the same working tree DURING this review -
app/Domain/ProductImages/*, app/Models/{ProductAsset,ProductImageBatch}.php,
app/Domain/Ai/{ProductImageCaller,AsyncImagePoll,AsyncImageTicket}.php, app/Filament/Merchant/Pages/ProductImageStudio.php,
migrations 2026_07_13_1200{00,01,02} (file mtimes 11:35-11:53), plus edits to IdempotencyKey, ReservationManager,
CreditLedger, MediaStorage, AiModel and AiModelResource.

The mechanism to look at first: database/migrations/2026_07_13_120002_seed_product_image_operations.php calls
(new AiControlPlaneSeeder)->seedProductImageOperations() FROM A MIGRATION. Migrations run for every RefreshDatabase
test, so AiOperation + AiModel rows (seedModel -> AiModel::updateOrCreate -> AiModelObserver) are now part of the
migrated BASELINE of every test in the suite - which is exactly the class of change that flips a catalog-state
assertion like assertDatabaseMissing(ai_models, ...). This is a Phase-4 concern, outside this gate, but the
orchestrator must resolve it before the Phase-4 gate: today it breaks a committed MONEY-SAFETY test (a flat-rate
BytePlus model must not be savable without a per-image price - a price-less flat-rate model charges nothing).

For the record: the Phase-3 verdict above rests on the clean baseline run (1048/3604 OK) plus 8 targeted mutation
runs and 2 behavioural probes, all executed against the Phase-3 surface.

---

## 2026-07-13 (re-review) — Shopify Phase 3 (product sync) — VERDICT: PASS-WITH-SUGGESTIONS

Reviewer: code-review-gatekeeper
Owner under review: laravel-backend
Clears: the BLOCKED verdict of 2026-07-13 (above). This entry does not rewrite it.

### Scope
The 4 blockers + suggestions 5/6 of the entry above:
SyncShopifyCatalogJob.php (:159-217 completeness guard + recordTruncation, :231-256 park),
StartShopifySync.php (:60-85 cap enforced in catalog()), new StartSyncResult.php,
ScanReview.php (:36-63 OPTIONAL_FIELDS_ON_AUTHORITATIVE_RAIL + AUTHORITATIVE_SOURCES, :158-163 isOptional),
PersistProduct.php (:76-85 platformActive) + ProductOrigin::platformActive,
ShopifySyncRun (markTruncated/isTruncated/TRUNCATION_*), migration 2026_07_13_130000,
ShopifyCatalogSyncTest:373-401, ScanReviewContractTest:181-247.

### Tests run
vendor/bin/phpunit -> OK (1091 tests, 3928 assertions). The AiModelCostHintTest failure noted in the
addendum above is RESOLVED (see the Phase-4 record).

### Mutation verification — every fix re-mutated; ALL 6 now go RED (was 2 theatre)

| # | Fix | Mutation applied | Result |
|---|-----|------------------|--------|
| 1 | Truncation guard | SyncShopifyCatalogJob:163 `if ($page->hasNextPage)` -> `if (false)` (always sweep) | RED |
| 2 | Soft cap enforced | StartShopifySync:76 `if ($this->exceedsCap($size))` -> `if (false)` | RED |
| 2b | Unmeasurable catalog fails closed | StartShopifySync:72 refusedSizeUnavailable -> `$size = 0` (walk blind) | RED |
| 3a | ShouldBeUnique (product job) | `implements ShouldBeUnique` removed | RED (was GREEN — theatre fixed) |
| 3b | ShouldBeUnique (catalog job) | `implements ShouldBeUnique` removed | RED (was GREEN — theatre fixed) |
| 4a | Blocking set pinned | name+price+main_image_url added to OPTIONAL_FIELDS_ON_AUTHORITATIVE_RAIL | RED (was GREEN — unpinned) |
| 4b | Optionality scoped to the authoritative rail | isOptional():160 source check dropped | RED |
| 5 | No flap-back on unpublished | PersistProduct:79 `if ($origin->platformActive)` -> `if (true)` | RED |
| 6 | A park redispatches, never release() | SyncShopifyCatalogJob:249 `self::dispatch(...)->delay()` -> `$this->release()` | RED |

Blocker 1 CONFIRMED FIXED: a max_pages-truncated walk now sweeps NOTHING (`archiveStale` is reached only
on `! $page->hasNextPage`), still COMPLETES, and is distinguishable — `truncated` + `truncated_reason` on
the run, a KIND_SHOPIFY_SYNC_TRUNCATED activity event, a warning log, and the UI warning.
Blocker 2 CONFIRMED FIXED: over the cap, catalog() opens NO run and dispatches NOTHING (StartSyncResult
carries `run = null` BY CONSTRUCTION, so "refused but queued" is not a representable state); an
unmeasurable catalog is refused too (fail closed).

### The two items the agent left open — JUDGED, NEITHER BLOCKS

(a) SyncShopifyProductJob:87 park() still uses `release()`. NOT a blocker. The catalog case was fatal
    because a FAILED run is terminal and the whole remaining walk was lost. Here the blast radius is ONE
    product of a bounded selection: `tries=5` exhausted -> failed() -> COUNTER_FAILED++ -> and because
    ShopifySyncRun::processed():136-139 counts `failed`, completeRunIfDone STILL completes the run. The
    merchant sees a completed run with a failed count and last_error, and can re-run. A degraded and
    REPORTED outcome, not a silent loss. The agent's reason for scoping it out is also sound (a park
    segment would change uniqueId(), which finding 3 now pins). -> SUGGESTION.
(b) StartShopifySync:181-198 normaliseGids() slices to selection_max. NOT a blocker: no data loss, no
    wrong archive, no money — only that products the merchant picked beyond 250 are silently not
    imported. Still the same "truncation you cannot distinguish" family as blocker 1. -> SUGGESTION
    (report the slice so the page can say "250 of 400 queued").

### Suggestions still open
- (a) above; (b) above.
- 8 (from the entry above) — the GlobalModels allow-list is pinned BY HAND; a reflection sweep over
  app/Models would make the next unscoped model go red automatically.
- 9 (from the entry above) — ProductOrigin.php CONSTANTS header with no constants beneath it.

### Gate
GATE: PHASE 3 CLEARS. All 4 blockers fixed and mutation-proven; suggestions 5 and 6 also applied and
proven. No new blocking finding. 4 suggestions carried forward.

### Recurring -> troubleshooting-archivist
- "A claimed guard that exists only as a const + a comment, with a test asserting the HELPER RETURN
  VALUE instead of the BEHAVIOUR" — the fix pattern that works is assertInstanceOf on the interface +
  a behavioural probe. Now applied on both sync jobs.
- "A completeness sweep run on an INCOMPLETE traversal" (the archive/reconcile family) — the fix
  pattern is: sweep only on a proven-complete traversal, and record the truncation as a first-class,
  queryable fact on the run row.
