## 2026-06-25T00:00:00Z - Phase 6 (Generation Pipeline - the money path) - VERDICT: BLOCKED
Reviewer: code-review-gatekeeper
Scope: app/Models/Generation.php, database/migrations/2026_06_25_130000_create_generations_table.php,
  database/factories/GenerationFactory.php, app/Domain/Generation/** (GenerateTryOnJob, StartGeneration,
  GenerationRequest, StartGenerationResult, GenerationStartException, GenerationFailureCode, CreditEstimator),
  app/Domain/Media/** (MediaStorage, StoredMedia), app/Providers/GenerationServiceProvider.php, app/Jobs/TenantAwareJob.php.
  Pre-work edits re-confirmed: ReservationManager (S1 atomic release), Account (S4 money cols non-fillable),
  CreditLedger + migration 110000 (N1 comment). Tests: tests/Feature/Generation/** (8 files) + GenerationTestSupport.

Sweeps run (Phase-6 surface):
  withoutGlobalScopes in product code: CLEAN - only a comment in GenerateTryOnJob:91; the audited bypass lives
    solely in Payments/PurchaseRouter + PurchaseReconciler, NOT in Generation.
  raw DB::table / DB::statement on a tenant table: CLEAN - none in app/Domain/Generation; only DB::table hit is
    the documented PurchaseRouter integer-id router; HealthController select 1 is non-tenant.
  non-deterministic idempotency key: CLEAN - no uniqid/uuid/random/microtime/time in Generation; key built by
    deterministic IdempotencyKey::forGeneration, embeds account_id as first segment.
  hardcoded model id / quality / aspect ratio / Blade::render: CLEAN - model+prompt+quality+aspect from
    AiOperationResolver via OperationConfig; prompt substitution is strtr in OperationConfig::substituteUser.
  hardcoded markup / float money: CLEAN - multiplier = config creditMultiplier else CreditMath::multiplierFor;
    all money integer micro-USD via CreditMath; no float carries money.
  raw status writes outside transitionTo: CLEAN - only ->status hit at GenerateTryOnJob:394 is a read compare,
    not a write; every state move goes through Generation::transitionTo.
  charge-path uniqueness: CLEAN - GenerationInvariantsTest asserts no CreditLedger::create / balance_micro_usd /
    raw credit_ledger insert anywhere in app/Domain/Generation.

Tests run: php artisan test (full) and --filter Generation, repeated ~30x.
  Steady-state: full suite 272 passed / 801 assertions; --filter Generation 42 passed / 168 assertions.
  BUT the suite is NON-DETERMINISTIC on the money path - two distinct real failures were observed (then
  intermittently vanished across subsequent runs). See BLOCKER 1.

MONEY-SAFETY VERDICT (production code, by reading): PASS on the code itself.
  - Charge ONLY on a stored, succeeded result: process order is gates -> reserve -> transitionTo(processing) ->
    callOpenRouter -> cost-available guard -> storeResult -> finalizeSuccess(charge). The charge row is written
    ONLY in finalizeSuccess (GenerateTryOnJob:367), in a fresh lockForUpdate txn, AFTER the result is stored
    (storeResult at :167 precedes it; a storage failure routes to finalizeFailure with NO charge).
  - No charge without a credit_ledger row: CreditLedgerService::charge is the sole charge writer; the job never
    touches balance_micro_usd or inserts a ledger row directly (proven by GenerationInvariantsTest + grep).
  - Reserve-before-call / release-on-failure with ZERO charge: every failure branch (OpenRouterException :145,
    Throwable :152, cost-unavailable :159, storage-failed :174) calls finalizeFailure -> ledger release +
    transitionTo(failed) and writes NO charge. The free-try is NOT consumed on failure (increment only in
    finalizeSuccess). No failure branch can fall through to finalizeSuccess (each returns).
  - Four-layer double-charge wall, all present: 1 ShouldBeUnique uniqueId = the stored idempotency_key,
    resolved under Tenant::run with NO withoutGlobalScopes; 2 lockForUpdate on the Generation in lockAndPrecheck
    AND again in finalizeSuccess; 3 ledger pre-check hasCharge short-circuits in BOTH lockAndPrecheck and
    finalizeSuccess; 4 client_request_id is the last key segment, collapsed at StartGeneration entry. The key
    embeds account_id (IdempotencyKey:44). credit_ledger.idempotency_key + generations.idempotency_key both UNIQUE.
  - Both gates, never collapsed, never a 500: LeadGate (typed LeadDecision) AND CreditGate (typed CreditDenied)
    both run BEFORE reserve/model-call; a denial -> pending->cancelled (the only legal pre-processing exit, since
    pending->failed is illegal) + failure_code + activity trace, no reservation taken, no OpenRouter call, no
    charge. Consistent with TS-CREDITS-005.
  - Status machine: Generation::TRANSITIONS matches ARCHITECTURE.md exactly; illegal moves throw; every accepted
    move writes an activity_event. Gate denials use pending->cancelled (legal).
  - Reservation invariants: reservation_ttl 300s > job timeout 70s - asserted by GenerationInvariantsTest;
    generations queue tries=1 (no retry double-spend) - asserted + mirrored in horizon.defaults. S1 release is
    atomic (cache pull get-and-delete; only the winner decrements reserved). Reservation->id IS the idempotency
    key, so reserve(key) and release(reservation->id) claim the SAME cache key - consistent.

TENANT-SAFETY VERDICT: PASS (independent re-confirmation).
  Generation + EndUser carry account_id NOT NULL + BelongsToAccount and are NOT on the global allow-list. The job
  extends TenantAwareJob: explicit int accountId in the ctor, handle binds via Tenant::run (clears in finally,
  TS-TENANCY-001), final handle so a subclass cannot bypass. uniqueId reads the key under Tenant::run with the
  normal fail-closed global scope, never withoutGlobalScopes. StartGeneration dispatches with the explicit
  account_id and never infers it from the body. Back-to-back two-account test proves no tenant leak + correct
  ledger + fail-closed cross-account reads (GenerationTenancyIsolationTest, currently passing).

MEDIA VERDICT: PASS. Source + result written to the s3 disk under accounts/{a}/sites/{s}/generations/{g}/
  {kind}-{rand}.{ext} (account leads), visibility=private, random leaf (non-guessable, no overwrite). The
  persisted ref is the opaque disk PATH; the browser only ever receives a short-lived signed temporaryUrl (TTL
  from config). Source is stored in StartGeneration BEFORE the job runs. No public path. (MediaStorageTest covers it.)

--- BLOCKING ---
1 [BLOCK] section 3.7 test theatre / non-deterministic money-path suite -
  tests/Feature/Generation/GenerateTryOnJobTest.php (the money-path class) + the OpenRouter backoff it leans on.
  Under the --filter Generation ordering the money-path tests are FLAKY: two distinct real failures were
  directly observed in this review (each later passing on re-run):
    a. test_cost_unavailable_is_treated_as_a_failure_no_charge -> TypeError: CreditMath::chargeMicroUsd arg 1
       (actualCostUsd) must be of type float, null given, from GenerateTryOnJob finalizeSuccess ~:351.
       finalizeSuccess was entered with a null cost - the cost-unavailable guard at GenerateTryOnJob:159 did
       not gate that run.
    b. test_double_dispatch_same_generation_charges_once -> RuntimeException: Illegal generation status
       transition succeeded -> processing (generation 1) at Generation:175. On the 2nd dispatch lockAndPrecheck
       did NOT short-circuit because the 1st run had not actually reached succeeded/charged.
  Root mechanism (most probable): OpenRouterClient cost-lookup backoff uses REAL usleep with random_int jitter
  (sleepCostBackoff/backoff ~:382/:387; COST_LOOKUP_ATTEMPTS=3) and NO test fakes the sleep (no Sleep::fake in
  tests/), so cost resolution is timing-sensitive and the HTTP-fake / cost-endpoint interaction is order-
  dependent across classes. The production guards are correct; the SUITE cannot be trusted to certify
  never-double-charge / never-charge-on-failure while it flakes on exactly those two assertions.
  WHY BLOCK: section 3.7 / 1.11 - a money-path guard whose test is non-deterministic is, for gate purposes,
  unproven. This is the highest-stakes phase; a flaky double-charge test is not an acceptable certificate.
  FIX (owner laravel-backend, with ai-openrouter): make the money-path tests deterministic - fake the OpenRouter
  backoff sleep (Sleep::fake / inject a no-op sleeper in tests), and/or harden cross-test isolation so the cost
  endpoint fake and timing cannot bleed between classes. Then run --filter Generation AND the full suite green
  at least 20x consecutively with no flake. Re-review required before the Phase 6 gate flips.

--- SUGGESTIONS (recorded, do NOT gate) ---
2 [SUGGEST] GenerateTryOnJob:309-322 loadSourceImage - the Fallback: read the bytes and send inline comment
  describes a behaviour the method does not implement (it throws when signedUrl is null). Either implement the
  byte fallback or drop the comment so the doc matches the code.
3 [SUGGEST] GenerateTryOnJob is ~486 lines doing gate+reserve+generate+store+charge+finalize in one class. Still
  readable (each step is a named private method), so not a blocker - but extracting the success/failure finalize
  pair into a small collaborator would shrink the single highest-stakes file. Owner discretion.

--- NITS ---
(none)

Re-review: REQUIRED - owner laravel-backend (1 lead), ai-openrouter (1 backoff-sleep faking). Re-run must show
  the money-path suite deterministic (20x+ green, both --filter Generation and full). Suggestions 2/3 non-gating.

Recurring -> troubleshooting-archivist: hand the class real-usleep-plus-random-jitter-in-a-service makes its
  dependent suite flaky on the money path, for the registry (new key e.g. TS-OPENROUTER-003 / TS-CREDITS-008) so
  a future agent fakes the sleep up front. Cross-reference TS-OPENROUTER-* (backoff) and the money-path tests.

GATE: BLOCKED - 1 blocking finding (non-deterministic money-path test suite; production money/tenant/media code
  PASSES by reading and by steady-state tests). Return to laravel-backend (+ ai-openrouter) to make the Phase-6
  money-path tests deterministic and prove 20x+ green; then re-review.


## 2026-06-25T12:00:00Z - Phase 6 RE-REVIEW (clears the 2026-06-25 BLOCK above) - VERDICT: GREEN
Reviewer: code-review-gatekeeper
Re-review of: BLOCKER 1 (non-deterministic money-path suite) + the latent null-cost gap noted under it.
Trigger: laravel-backend + ai-openrouter remediation. Re-read the changed code AND re-ran the suite myself
  (not on the report's word).

What I verified by READING the changed code:
  - app/Domain/Ai/ParsedCost.php: the null-cost gap is now CLOSED AT THE TYPE. The constructor (:46-49)
    forces available=false + source=unavailable whenever costUsd === null, regardless of caller intent. The
    contradictory available=true/costUsd=null state I flagged is structurally impossible to construct. New
    tests/Unit/Ai/ParsedCostTest.php proves the invariant over every path incl. the direct contradictory ctor.
  - app/Domain/Generation/GenerateTryOnJob.php finalizeSuccess (:354-363): defense in depth - costUsd === null
    routes to finalizeFailure(COST_UNAVAILABLE) BEFORE any CreditMath::chargeMicroUsd call; the charge math then
    uses the local non-null $costUsd. chargeMicroUsd(null) is now unreachable on every path (the process()
    :159 guard AND this success-boundary guard AND the ParsedCost type invariant - three independent layers).
    A null/unavailable cost -> release + NO charge + free-try NOT consumed.
  - app/Domain/Ai/OpenRouterClient.php (:386-398): backoff now sleeps via the Sleep facade
    (Sleep::for(...)->milliseconds()), not raw usleep. Production behaviour identical; random_int jitter kept
    for thundering-herd protection; tests Sleep::fake() it so no real time passes and timing is deterministic.
  - tests/Feature/Generation/GenerationTestSupport.php: bootGenerationEnv() now Sleep::fake()s and deliberately
    does NOT pre-install an empty Http::fake() (documented trap: an empty catch-all swallows later stubs). Each
    test installs its own complete fake; no cross-test bleed under any --filter ordering. Sleep imported (:16).
  - tests/Feature/Generation/GenerateTryOnJobTest.php double-dispatch (:158-191): now asserts the CLEAN
    short-circuit - status stays succeeded, exactly ONE charge, ONE free try, and exactly ONE
    generation_succeeded trace (proving the 2nd run returned null at lockAndPrecheck, never the transition
    backstop). No expectException - if the 2nd run threw the illegal-transition RuntimeException the test would
    error. cost_unavailable test (:131-156) asserts failed + COST_UNAVAILABLE + null charge_ledger_id + balance
    intact + reserved 0 + zero charge rows + free-try not consumed. Both meaningful, not theatre.

What I verified by RE-RUNNING (my own hands, not the report):
  - php artisan test --filter Generation (default order): 15/15 GREEN, 43 passed / 177 assertions each.
  - php artisan test --filter Generation --order-by=random: 10/10 GREEN, 43 passed / 177 assertions each.
  - php artisan test (full, default order): 10/10 GREEN, 281 passed / 834 assertions each.
  - php artisan test --order-by=random (full): 6/6 GREEN, 281 passed / 834 assertions each.
  TOTAL: 41 consecutive green runs, ZERO flakes. The two failures from the BLOCK (chargeMicroUsd(null)
  TypeError; succeeded->processing illegal-transition) did not recur in any run or any ordering. In the
  original BLOCK the flake surfaced within the first few --filter Generation runs; it is now gone.

Re-review disposition of the BLOCK:
  - BLOCKER 1 (non-deterministic money-path suite): RESOLVED. Root cause (real usleep + random jitter, no
    Sleep::fake; empty-Http::fake bleed) fixed at the source; proven by 41x stable green incl. random order.
  - Latent null-cost gap (noted under the BLOCK): RESOLVED at the type (ParsedCost invariant) AND defended in
    depth at the consumer (finalizeSuccess guard). chargeMicroUsd(null) is structurally unreachable.
  - Suggestions 2 (loadSourceImage comment) and 3 (job size) were non-gating and remain owner discretion;
    they do not affect this verdict.
  - Harness traps logged by the team as TS-BUILD-004; the flake class logged as TS-OPENROUTER-003 - consistent
    with my archivist handoff. (Re-confirm those entries land in docs/TROUBLESHOOTING.md.)

MONEY-SAFETY: PASS (re-confirmed). TENANT-SAFETY: PASS. MEDIA: PASS. (All unchanged from the BLOCK's read; the
  remediation touched only cost-typing + determinism, not the charge/reserve/release/idempotency/tenancy laws.)

GATE: GREEN - the Phase-6 charge gate may flip. The only blocker is cleared, the money-path suite is
  deterministic (41x green, incl. random order), and the null-cost gap is closed at the type + defended in
  depth at the consumer. No re-review outstanding.
