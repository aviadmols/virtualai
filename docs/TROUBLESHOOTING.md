# TROUBLESHOOTING.md — Tray On scar-tissue registry

> The project's **living knowledge base** of every bug, blocker, gotcha, or
> thing-that-didn't-go-smoothly encountered during the build — together with its
> **verified** solution and how to prevent it. Maintained by the
> [`troubleshooting-archivist`](../.claude/agents/troubleshooting-archivist.md)
> agent. The point is simple: **the same problem never costs the team time twice.**

## How this file works

- **CONSULT before you build.** Before starting a task or phase, search this file
  for your area (the category index below). Read the known issues, apply their
  verified solutions, and follow the prevention so you don't re-earn the scar. The
  orchestrator consults it at the start of every phase.
- **RECORD after a snag.** After any non-trivial blocker is hit (ideally once
  resolved), add or update an entry: symptom → context → root cause → the exact fix
  that worked → how to prevent it. Hand it to the archivist; don't edit blind.
- **A fix is logged only once VERIFIED.** An unconfirmed fix stays `STATUS: open`
  with `SOLUTION: UNVERIFIED — <hypothesis>`. "We think it works" is not a solution.
- **One problem = one entry.** A recurrence bumps `recurrence` and adds a dated
  line; it never spawns a near-duplicate. Recurring entries (`≥2×`) float to the top
  of their category.
- **Append-only in spirit.** Entries are edited in place to tighten or promote, and
  obsolete ones are marked `wont-fix`/`superseded` with a pointer — **never silently
  deleted.**
- **Dates are supplied, not invented.** Subagents can't call `Date.now()`; the date
  comes from the caller, or from `git log -1 --format=%cd --date=short` / `date +%F`.

## Entry schema (template)

Copy this block for a new entry. Every field is load-bearing; an entry missing
`ROOT CAUSE`, `SOLUTION` specifics, or `PREVENTION` is incomplete and stays `open`.

```
### TS-<CATEGORY>-<NNN> — <one-line title>
- **Date:** YYYY-MM-DD   (re-occurrence: YYYY-MM-DD)
- **Category:** <one of the taxonomy below>
- **Severity:** blocker | major | minor
- **Recurrence:** <integer>
- **Status:** open | resolved | recurring | wont-fix | superseded
- **Tags:** `tag-a`, `tag-b`

- **SYMPTOM:** what was observed — the exact error / wrong output / failed deploy.
- **CONTEXT / TRIGGER:** when it happens — env, command, phase, the exact step.
- **ROOT CAUSE:** why it broke. (Mandatory for a closed entry; if unknown → status open.)
- **SOLUTION:** the exact fix that WORKED — file:line, commands, config, values.
                (Write `UNVERIFIED — <hypothesis>` and keep status open until confirmed.)
- **PREVENTION:** how to avoid it next time — the checklist item or convention to apply.
- **RELATED:** [[TS-OTHER-00X]], [[agent-name]], [[path/to/file.php]]
```

**ID format:** `TS-<CATEGORY>-<NNN>`, e.g. `TS-TENANCY-001`. IDs are stable — never
renumbered, never reused, survive sorting and pruning.

## Category index

The lookup axis. File each entry under exactly one category; cross-link the rest.

| Category | Covers | Primary owner(s) |
|---|---|---|
| **tenancy/isolation** | `account_id` scope leaks, `BelongsToAccount`, `Tenant` left bound between jobs, `withoutGlobalScopes()` misuse, cross-account reads, encrypted `widget_secret` | `laravel-backend`, `saas-credits-billing` |
| **credits/ledger** | reservation/charge/refund flow, markup math, debit-on-success/release-on-failure, opening grant, double-charge, idempotency keys, purchase rail | `laravel-backend`, `saas-credits-billing` |
| **openrouter/ai** | OpenRouter HTTP, `AiOperationResolver`, model/prompt resolution order, cost parsing, fallback, retries, hardcoded-model drift | `ai-openrouter` |
| **pdp-scan** | URL fetch/render, AI extraction, selector confidence, confirm/correct contract, JS-heavy PDPs | `pdp-scanner` |
| **widget/storefront** | PDP detection, variant sync, button injection, modal, result screen, gallery, add-to-cart, signed widget API, weight/LCP/CLS budget | `widget-embed` |
| **infra/railway/horizon** | web/worker/scheduler boot, Horizon, queues, autoscaling, rate-limiting, predeploy guard, env contract, deploy failures | `railway-infra` |
| **filament/admin** | the two panels, resources, forms, actions, append-only read-only surfaces, token→CSS-var theming | `admin-design-system` |
| **i18n/RTL** | `__()` coverage, `lang/en`↔`lang/he` mirror, RTL/logical-property bugs, untranslated strings | `admin-design-system`, `product-ux-architect` |
| **media/storage** | S3/R2, signed URLs, CDN, upload handling, image storage on success | `laravel-backend`, `railway-infra` |
| **privacy/retention** | retention purge of source + result images, GDPR/lead export, never deleting a ledger row | `saas-credits-billing`, `laravel-backend` |
| **build/deploy** | composer/PHP toolchain (Herd absolute paths), migrations, asset build, CI, the widget bundle build | `railway-infra`, `widget-embed` |

**Ordering within a category:** `recurring` (recurrence desc) → `open` (severity) →
`resolved`. The problems that bite most often surface first.

---

## tenancy/isolation

### TS-TENANCY-001 — worker left `Tenant` bound between jobs → cross-account read
- **Date:** 2026-06-24
- **Category:** tenancy/isolation
- **Severity:** blocker
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `global-scope`, `horizon`, `tenant-context`, `account_id`, `worker-state`

- **SYMPTOM:** A `GenerateTryOnJob` for Account B occasionally read and reserved
  credits against **Account A**. In the activity timeline, a generation owned by
  site `B-42` showed a `charge` ledger row written under `account_id = A`. Only
  happened under load when two accounts' jobs ran back-to-back on the same Horizon
  worker; never reproduced running one job in isolation.
- **CONTEXT / TRIGGER:** Phase 6 (generation pipeline), Horizon worker on the
  `generations` queue, two accounts' jobs processed consecutively on the same
  long-lived worker process. Trigger command:
  `php artisan horizon` then dispatch two generations from two accounts.
- **ROOT CAUSE:** `GenerateTryOnJob::handle()` bound the tenant via
  `Tenant::set($account)` at the top but had **no `finally` clearing it**. When the
  job finished, the worker process kept the previous `Tenant` bound in memory. The
  next job — which (wrongly) relied on the ambient `Tenant` instead of its own
  constructor `account_id` — inherited Account A's context and ran its queries under
  A's global scope. The `BelongsToAccount` global scope was working correctly; it was
  reading a *stale* tenant.
- **SOLUTION:** Two fixes, both verified by the isolation test below.
  1. Wrap the job body in a self-clearing context:
     `app/Support/Tenant.php` — use `Tenant::run($account, fn () => …)` which
     binds in a `try` and clears in `finally`, instead of a bare `Tenant::set()`.
     `app/Domain/Generation/GenerateTryOnJob.php:41` now reads
     `return Tenant::run($this->account(), fn () => $this->process());`.
  2. The job no longer relies on ambient state: it resolves the account from its
     **explicit constructor** `account_id` (`GenerateTryOnJob.php:18`), never from
     `Tenant::current()`.
  Verified: `php artisan test --filter TenantLeakBetweenJobsTest` (a new test that
  dispatches an Account-A job then an Account-B job on the same worker and asserts B's
  charge lands on `account_id = B`) — passes; failed before the fix.
- **PREVENTION:**
  - Checklist item (now in the universal gate): **every queued job binds its tenant
    via `Tenant::run($account, …)` (clears in `finally`) and resolves the account
    from its explicit constructor `account_id` — never from `Tenant::current()` /
    session / domain / config.**
  - Add a back-to-back two-account isolation test for every new tenant-touching job.
  - `code-review-gatekeeper` greps job files for a constructor `account_id` and a
    `Tenant::run`/`finally{clear}` wrapper.
- **RELATED:** [[laravel-backend]], [[saas-credits-billing]], [[code-review-gatekeeper]],
  [[app/Support/Tenant.php]], [[app/Domain/Generation/GenerateTryOnJob.php]],
  [[app/Models/Concerns/BelongsToAccount.php]]

> **RECOMMENDED CONTRACT CHANGE** (drafted for `trayon-orchestrator`, not yet
> committed): `CLAUDE.md` "Tenant-safety" bullet already says *"Every queued job
> receives `account_id` explicitly."* Append the second half this incident proved
> necessary: *"…and binds it via `Tenant::run()` which clears in `finally`; a job
> must never read the ambient `Tenant` left by a previous job."* — so the rule
> covers the **stale-context** leak, not only the **missing-parameter** leak.

### TS-TENANCY-003 — `User` is allow-list-exempt → a bare `User::query()` is global (latent Phase-8 cross-account read)
- **Date:** 2026-06-24
- **Category:** tenancy/isolation
- **Severity:** minor
- **Recurrence:** 1
- **Status:** open
- **Tags:** `belongs-to-account`, `allow-list`, `user`, `global-scope`, `filament`, `phase-8`

- **SYMPTOM:** No live bug. Surfaced by the Phase-2 tenant-isolation audit
  (`saas-credits-billing`): because `App\Models\User` is on
  `GlobalModels::ALLOW_LIST` (and deliberately NOT `BelongsToAccount`), a bare
  `User::query()` / `User::all()` returns EVERY account's users, not just the
  bound tenant's. Proven in an adversarial probe: `Tenant::run($b, fn () =>
  User::count())` returned both accounts' users.
- **CONTEXT / TRIGGER:** Latent. Triggers only when a merchant-facing surface runs
  an UNSCOPED User query — e.g. a future merchant Filament User resource / team
  management page (Phase 8). No current exposure: `MerchantPanelProvider` is an
  empty shell with zero User resources, and the static hunt found no unscoped User
  query in product code. The one existing relation, `Account::users()`
  (`app/Models/Account.php:62`), filters by the owning `account_id` and does NOT
  leak (verified).
- **ROOT CAUSE:** The `User` exemption is correct and necessary (auth resolves a
  user before any tenant is bound; platform super-admins must be globally visible),
  but the exemption shifts user-isolation responsibility from the automatic global
  scope to EXPLICIT query scoping at every merchant call site. A forgotten scope on
  a User query is therefore fail-OPEN (returns all), unlike tenant models which are
  fail-CLOSED.
- **SOLUTION:** PARTIAL (the scoping tool now exists; the call-site enforcement is
  Phase 8). A real `User::scopeForAccount(Builder, Account|int)` was added
  (`app/Models/User.php`) and proven by
  `SiteKeyAndAllowListTest::test_user_for_account_scope_lists_only_that_accounts_owners`
  (account A sees its 2 owners, B its 3, the global super-admin excluded).
  Still REQUIRED at Phase 8: every merchant-facing User query MUST use
  `User::forAccount($account)` / `$account->users()` — NEVER a bare
  `User::query()`/`User::all()`. Consider a merchant-panel base query / policy that
  injects the account filter so it cannot be forgotten. Stays `open` until that
  surface lands and a back-to-back two-account User-read test proves it end-to-end.
- **PREVENTION:** Audit checklist item (now tracked): any new merchant-facing read
  of a GLOBAL allow-list model (`User`, and future global catalogs) must be
  explicitly account-scoped at the call site, because the global scope will NOT do
  it. `code-review-gatekeeper` greps merchant Filament resources / controllers for
  bare `User::query()`/`User::all()` and flags them.
- **RELATED:** [[saas-credits-billing]], [[laravel-backend]], [[admin-design-system]],
  [[app/Models/User.php]], [[app/Support/GlobalModels.php]],
  [[app/Providers/Filament/MerchantPanelProvider.php]]

### TS-TENANCY-002 — trait constant unreadable from the global-scope class ("Cannot access trait constant … directly")
- **Date:** 2026-06-24
- **Category:** tenancy/isolation
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `php`, `trait-const`, `global-scope`, `belongs-to-account`, `php82`

- **SYMPTOM:** Every tenant-scoped query fataled with
  `Error: Cannot access trait constant App\Models\Concerns\BelongsToAccount::ACCOUNT_FOREIGN_KEY directly`
  at `app/Models/Concerns/BelongsToAccount.php` in the `AccountScope::apply()`
  method. 6 isolation tests errored; the model could not be queried at all.
- **CONTEXT / TRIGGER:** Phase 2. The `account_id` column name was declared as a
  `public const ACCOUNT_FOREIGN_KEY` on the `BelongsToAccount` trait, and the
  sibling `AccountScope` class (same file, the global scope) referenced it as
  `BelongsToAccount::ACCOUNT_FOREIGN_KEY` to build the `where()`.
- **ROOT CAUSE:** PHP (8.2+ trait constants) only allows a trait constant to be
  accessed from WITHIN the class that uses the trait (`self::CONST` inside a
  model). It cannot be read via the trait name (`TraitName::CONST`) from an
  unrelated class — that is a hard language restriction, not a typo.
- **SOLUTION:** Give the scope its own authoritative copy of the column name:
  `app/Models/Concerns/BelongsToAccount.php` — `AccountScope` now declares
  `public const ACCOUNT_FOREIGN_KEY = 'account_id';` and uses `self::` in
  `apply()`. The trait keeps its own `public const ACCOUNT_FOREIGN_KEY` for the
  `creating` hook + `account()` relation (read via `self::` inside the model,
  which is legal). Both name the same literal, so they cannot drift. Verified:
  `php artisan test` -> 18 passed (52 assertions), all isolation tests green.
- **PREVENTION:** Never reference a trait constant via the trait name from a
  separate class. A constant that two classes share (a trait + its companion
  scope/builder) lives on the class that the consumer can actually reach (here,
  the `Scope`), or in a dedicated value class — not only on the trait.
- **RELATED:** [[laravel-backend]], [[app/Models/Concerns/BelongsToAccount.php]]

---

### TS-TENANCY-004 — cross-tenant write guard: mass-assign COERCES, only direct-set THROWS
- **Date:** 2026-06-25
- **Category:** tenancy/isolation
- **Severity:** minor (no leak; a faulty over-strict test, not a code bug)
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `belongs-to-account`, `mass-assignment`, `fillable`, `cross-tenant-write`, `test-expectation`

- **SYMPTOM:** Two Phase-4 `ProductScanIsolationSpotCheckTest` tests failed —
  `Failed asserting that exception of type CrossTenantWriteException is thrown`
  and `Failed asserting that 1 is identical to 0`. Both asserted that
  `Product::create(['account_id' => <foreign>, ...])` / `ProductVariant::create([...])`
  under a *different* bound tenant would THROW and persist nothing.
- **CONTEXT / TRIGGER:** Phase 4 gate. The spot-check file was authored by the
  isolation-audit subagent which crashed at a session limit before reconciling its
  expectations with the (already Phase-2-audited) guard implementation.
- **ROOT CAUSE:** `account_id` is deliberately NOT in any tenant model's `$fillable`
  (Phase-2 design). So a **mass-assigned** foreign `account_id` is silently dropped
  by Laravel *before* the `creating` guard runs; the guard sees `null` and auto-stamps
  the BOUND tenant. The row is created — correctly owned by the bound tenant, never by
  the foreign account. **No cross-account leak.** The guard's `throw` path can only fire
  on the **direct attribute-set** vector (`$m->account_id = <foreign>; $m->save()`),
  where the value actually reaches `creating`. The tests asserted a uniform throw,
  which is impossible for the mass-assign vector.
- **SOLUTION:** Corrected the two tests to assert the TRUE, proven-safe contract
  (`tests/Feature/Tenancy/ProductScanIsolationSpotCheckTest.php`):
  1. `test_cross_tenant_write_mass_assign_coerces_to_bound_tenant_no_leak` — mass-assigned
     foreign id is ignored; row stamped to the bound tenant; 0 rows under the foreign account.
  2. `test_cross_tenant_write_guard_throws_on_direct_foreign_account_id` — the direct-set
     vector throws `CrossTenantWriteException` and persists nothing for either account.
  3. `test_variant_create_guard_throws_on_direct_foreign_account_id` — same for the child model.
  Verified: full suite `php artisan test` -> **123 passed (346 assertions)**, 0 failures.
- **PREVENTION:** When testing the cross-tenant write guard, assert per VECTOR:
  **mass-assignment → coerced to the bound tenant (non-fillable); direct attribute-set →
  throws.** Do not assert a thrown exception on a mass-assigned non-fillable column — it
  never reaches the guard. (Tracked, non-blocking, for the audit agent on re-review: if a
  *uniform loud-throw* on both vectors is wanted, that's a deliberate change — make
  `account_id` fillable and let the guard reject mismatches — not a silent default.)
- **RELATED:** [[TS-TENANCY-001]], [[app/Models/Concerns/BelongsToAccount.php]],
  [[app/Exceptions/CrossTenantWriteException.php]], [[saas-credits-billing]]

### TS-TENANCY-005 — chained factories inside `Tenant::run()` mint a FOREIGN account_id → CrossTenantWriteException in fixtures
- **Date:** 2026-06-25
- **Category:** tenancy/isolation
- **Severity:** minor (test-fixture ergonomics; the guard working as designed, not a code bug)
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `factory`, `tenant-bound`, `cross-tenant-write`, `belongs-to-account`, `test-fixtures`, `phase-6`

- **SYMPTOM:** A Phase-6 status-machine test built a fixture as
  `Tenant::run($accountA, fn () => Generation::factory()->create([...]))` and threw
  `CrossTenantWriteException: cannot create App\Models\Site for account_id 3 while tenant 1
  is bound`. The `GenerationFactory` definition uses `Site::factory()` / `Account::factory()`
  for its FKs, which mint a BRAND-NEW account (id 3) — but the test ran inside account 1's
  bound scope, so the BelongsToAccount `creating` guard (correctly) refused the foreign id.
- **CONTEXT / TRIGGER:** Phase 6 fixtures. Any model factory whose definition chains other
  factories (a Generation needs site/end_user/product/variant) creates fresh, unrelated
  accounts; running that factory inside `Tenant::run($someAccount)` makes those fresh
  account_ids "foreign" to the bound tenant.
- **ROOT CAUSE:** The guard is fail-LOUD on a direct/explicit account_id that mismatches the
  bound tenant (TS-TENANCY-004). A chained factory sets each child's `account_id` to its OWN
  new account via the nested `Account::factory()`, which is explicit and foreign — so the
  guard fires. The guard is right; the fixture was incoherent (its FKs belonged to a
  different account than the one bound).
- **SOLUTION:** Build a COHERENT fixture: create the parent chain first with the
  account-aligning states (`Site::factory()->forAccount($a)`, `Product::factory()->forSite($site)`,
  `ProductVariant::factory()->forProduct($product)`), then build the leaf with a context
  helper that copies the SAME account_id/site_id (`GenerationFactory::forContext($endUser,
  $product, $variant, $crq)`). Because every row now carries account A's id, building them
  OUTSIDE `Tenant::run` (factories set account_id explicitly) — or inside, since the explicit
  id now MATCHES the bound tenant — both pass the guard. Verified:
  `tests/Feature/Generation/GenerationStatusMachineTest.php` + the shared
  `tests/Feature/Generation/GenerationTestSupport.php::makeContext()` build the whole chain
  under one account; full Generation suite (35) green.
- **PREVENTION:** A tenant-owned model's factory must offer a `forX($parent)` state that
  inherits the parent's `account_id`, and tests must build the full parent→child chain under
  ONE account before binding it. Never run a FK-chaining factory inside `Tenant::run()`
  expecting it to adopt the bound tenant — its nested factories mint their own accounts. If a
  factory is used tenant-bound, pass an explicit, matching account_id for every level.
- **RELATED:** [[TS-TENANCY-004]], [[app/Models/Concerns/BelongsToAccount.php]],
  [[database/factories/GenerationFactory.php]], [[tests/Feature/Generation/GenerationTestSupport.php]],
  [[tests/Feature/Generation/GenerationStatusMachineTest.php]]

---

## credits/ledger

### TS-CREDITS-001 — opening-grant observer updates the column AFTER `created`; the in-memory model is stale (`->fresh()` required)
- **Date:** 2026-06-25
- **Category:** credits/ledger
- **Severity:** minor
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `observer`, `opening-grant`, `ledger-writer`, `balance`, `stale-model`, `phase-5a`

- **SYMPTOM:** After `Account::factory()->create()`, the returned `$account`
  instance reported `balance_micro_usd = 0` even though the opening $5 grant row had
  been written and the DB column was `5_000_000`. A test asserting the balance on the
  factory-returned instance (without `->fresh()`) saw 0.
- **CONTEXT / TRIGGER:** Phase 5a. The opening grant is written by `AccountObserver::created()`
  via `CreditLedgerService::grant()`, which `lockForUpdate()`s a FRESH `Account` query
  inside its transaction and updates THAT instance's column — not the original
  factory/in-memory instance that triggered the `created` event.
- **ROOT CAUSE:** The grant correctly goes through the ledger writer (never a bare
  column write), so it mutates a re-queried, row-locked `Account`. The instance held
  by the caller predates that write and is not auto-refreshed. This is expected Eloquent
  behaviour (a model is a snapshot), not a bug — but it surprises a test that reads the
  balance off the original instance.
- **SOLUTION:** Read the post-grant balance from a re-fetched model: `$account->fresh()->balance_micro_usd`
  (or `$account->refresh()`). Tests assert on `->fresh()`; the DB and `balance_after_micro_usd`
  are the source of truth. The grant itself is verified idempotent (one row per account,
  keyed `grant:{account}:opening`). Verified by
  `tests/Feature/Credits/OpeningGrantTest.php` (4 cases) and the seeded two-account DB
  (`credit_ledger` = 2 grant rows, each balance_after = 5_000_000).
- **PREVENTION:** Anything that reads a balance an observer/writer may have moved must
  read it from a fresh model (`->fresh()`/`->refresh()`), never the pre-event instance.
  The ledger writer always re-queries + row-locks the account, so the live column +
  `balance_after_micro_usd` snapshot are authoritative — assert against those.
- **RELATED:** [[laravel-backend]], [[app/Observers/AccountObserver.php]],
  [[app/Domain/Credits/CreditLedgerService.php]], [[tests/Feature/Credits/OpeningGrantTest.php]]

### TS-CREDITS-002 — double-charge wall: the account row lock (not just the unique index) is what serializes same-account charges
- **Date:** 2026-06-25
- **Category:** credits/ledger
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `double-charge`, `idempotency`, `row-lock`, `unique-index`, `pre-check`, `charge`, `phase-5a`

- **SYMPTOM:** Design risk (no live bug): a naive charge that only relies on the unique
  `idempotency_key` index would let two concurrent `charge()` calls for the same
  generation BOTH compute `balance − charge` off a stale read before either committed,
  then the second insert would throw a raw `QueryException` to the caller (a 500 on the
  money path) instead of resolving to a clean no-op.
- **CONTEXT / TRIGGER:** Phase 5a, `CreditLedgerService::charge()` — the four-layer
  idempotency the §7 generation pipeline depends on. Triggered by a double-clicked widget
  button, a Horizon retry, or scheduler overlap producing the same deterministic key.
- **ROOT CAUSE:** The unique index alone makes a duplicate ROW impossible, but it does
  not by itself make the duplicate a graceful no-op, and it does not serialize the
  balance math. The serialization comes from the `lockForUpdate()` on the ACCOUNT row at
  the top of the charge transaction: a second same-account writer blocks there until the
  first commits, then its ledger pre-check (`existingByKey`) sees the committed `charge`
  row and returns it — no second debit, no exception.
- **SOLUTION:** Defense in depth in `app/Domain/Credits/CreditLedgerService.php`:
  (1) the unique `idempotency_key` index (DB-level impossibility);
  (2) `Account::lockForUpdate()` inside the txn (serializes same-account writers);
  (3) the `existingByKey()` ledger pre-check (returns the existing row → no-op);
  (4) a `QueryException` catch around the insert that resolves a racing cross-connection
  duplicate to the existing row (`resolveDuplicateOrThrow()`), re-throwing only an
  UNRELATED DB error — never swallowing a real failure on the money path.
  A duplicate `charge()` returns the SAME row id, the balance is debited exactly once,
  and the activity trace fires only for a FRESH row. Verified by
  `tests/Feature/Credits/CreditLedgerServiceTest.php`:
  `test_double_charge_is_impossible_same_key` (one row, one debit, same id) and
  `test_double_charge_blocked_at_the_db_unique_index` (a raw duplicate insert throws).
- **PREVENTION:** A charge is never guarded by the unique index alone. Always: row-lock
  the account inside the transaction, pre-check the ledger by the deterministic key and
  return the existing row, and treat a unique-violation as "the other writer already
  charged → return its row", not as a 500. The deterministic key (with `account_id`,
  the variant hash, and `client_request_id`) is what makes all of this collapse to one.
- **RELATED:** [[laravel-backend]], [[saas-credits-billing]], [[app/Domain/Credits/CreditLedgerService.php]],
  [[app/Domain/Credits/IdempotencyKey.php]], [[tests/Feature/Credits/CreditLedgerServiceTest.php]]

### TS-CREDITS-003 — PayPlus webhook signature scheme is undocumented publicly; used the team's verified `hash` header (base64 HMAC-SHA256 of raw body)
- **Date:** 2026-06-25
- **Category:** credits/ledger
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `payplus`, `webhook`, `signature`, `hmac`, `docs-drift`, `purchase-rail`, `phase-5b`

- **SYMPTOM:** Building the Phase-5b credit-purchase webhook, the PayPlus official docs
  (`docs.payplus.co.il/reference/post_paymentpages-generatelink`) confirm the page-create
  request shape + the dual-header auth (`api-key` + `secret-key`) but DO NOT document the
  callback (`refURL_callback`) signature scheme — no HMAC header name, no algorithm, no
  transaction status-code table. Coding the webhook verification against an assumption was
  the risk (a wrong scheme would either reject every real webhook or — worse — accept a
  forged one, double-crediting the merchant on the platform-revenue rail).
- **CONTEXT / TRIGGER:** Phase 5b, `PayPlusProvider::verifyAndParseWebhook()`. Public web
  search surfaced conflicting hints (some references show a `hash` header, others an
  `X-PayPlus-Signature` header), none authoritative for the current REST API.
- **ROOT CAUSE:** PayPlus's public reference covers the OUTBOUND generateLink call but not
  the INBOUND callback signature; the verified scheme lives in their integration guides /
  the gateway plugins, not the REST reference page.
- **SOLUTION:** Mirrored the team's ALREADY-VERIFIED PayPlus integration (the RECHARGE
  pattern oracle, `WooDepositCallbackController`): PayPlus signs the raw callback body and
  sends the `hash` header = `base64(HMAC-SHA256(rawBody, secret_key))`; we recompute it
  with the platform `services.payplus.secret_key` and `hash_equals()` it, FAILING CLOSED
  on a missing/forged/unsigned hash (the platform revenue rail requires every webhook be
  signed — unlike the oracle's storefront deposit, which treated an absent hash as a hint).
  Status codes `000`/`0`/`approved`/`success` map to `paid`; `refunded`/`refund` to
  `refunded`; everything else to `failed`. The header name + codes are CONSTs in
  `app/Domain/Credits/Payments/PayPlusProvider.php` so a confirmed-different scheme is a
  one-line change. NEVER trust the client-reported amount — the provider-confirmed amount
  is parsed from the signed body (nested OR flat shape) into integer micro-USD. VERIFIED
  by `tests/Feature/Credits/PayPlusProviderTest.php` + `PurchaseWebhookSignatureTest.php`
  (signed-approved credits once; forged secret rejected; unsigned rejected; replay credits
  once) — and ANTI-THEATER proven: bypassing `hash_equals` makes a forged-secret webhook
  parse (the forged/unsigned tests go RED), restored → green.
- **PREVENTION:** Before wiring any PayPlus callback, reuse the team's verified `hash`
  (base64 HMAC-SHA256 of the RAW body, secret_key) scheme; keep the header name + status
  codes as CONSTs; fail CLOSED on a missing/mismatched signature for a money-in rail. If
  Aviad confirms a PayPlus account that signs with `X-PayPlus-Signature` or a distinct
  `webhook_secret`, flip the one CONST + the config key — do not re-derive the scheme.
- **RELATED:** [[saas-credits-billing]], [[app/Domain/Credits/Payments/PayPlusProvider.php]],
  [[tests/Feature/Credits/PayPlusProviderTest.php]], [[tests/Feature/Credits/PurchaseWebhookSignatureTest.php]],
  [[config/services.php]], [[TS-OPENROUTER-001]]

### TS-CREDITS-004 — webhook arrives with NO bound tenant; routing to the owning account without a withoutGlobalScopes() leak
- **Date:** 2026-06-25
- **Category:** credits/ledger
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `webhook`, `tenant-context`, `belongs-to-account`, `global-scope`, `account-routing`, `purchase-rail`, `phase-5b`

- **SYMPTOM:** Design tension (no live bug): a payment webhook has NO bound tenant, but
  `credit_purchases` is `BelongsToAccount` (fail-closed → returns NOTHING when unbound). So
  `CreditPurchase::where('provider_ref', $ref)->first()` inside the webhook returns null and
  the paid purchase is never credited. The naive fix — `withoutGlobalScopes()` — is a P0
  cross-account leak (and the isolation audit's static hunt rejects it in product code).
- **CONTEXT / TRIGGER:** Phase 5b, `PurchaseReconciler` resolving which account a verified
  PayPlus webhook belongs to. The provider echoes only `more_info` (our `provider_ref`); the
  account is NOT in (and must never be trusted from) the webhook body.
- **ROOT CAUSE:** The global scope is all-or-nothing per query and fails closed with no
  tenant — exactly the safety we want everywhere EXCEPT the single "which tenant is this
  webhook for?" routing step, which by definition runs before a tenant is known.
- **SOLUTION:** A dedicated, documented `PurchaseRouter::accountIdForRef(provider, ref)`
  (`app/Domain/Credits/Payments/PurchaseRouter.php`) does ONE `DB::table('credit_purchases')
  ->value('account_id')` — it returns ONLY the integer account_id (a routing fact), never
  money/PII/row data, keyed by the globally-unique (provider, provider_ref) we ourselves
  minted on initiate(). The reconciler then `Tenant::run($accountId, ...)` and re-reads the
  full row through the NORMAL fail-closed global scope; ALL data reads/writes are tenant-
  scoped. This keeps the cross-scope surface to a single integer in one named class the
  audit can reason about — NOT `withoutGlobalScopes()` and never an unscoped model hydrate.
  VERIFIED by `tests/Feature/Tenancy/PurchaseIsolationTest.php`
  (`a_webhook_for_account_a_never_credits_account_b`: the webhook carrying A's ref credits A
  and ONLY A; B's balance + ledger untouched) and the static hunt (no `withoutGlobalScope`
  in product code; the one `DB::table('credit_purchases')` is this router, integer-only).
- **PREVENTION:** For ANY tenant-less inbound (webhook/callback) that must route to a
  tenant: resolve ONLY the account_id via a single named, integer-returning helper keyed by
  a value YOU minted, then `Tenant::run()` and do all real work through the global scope.
  Never `withoutGlobalScopes()` a tenant model to read its data; never take the account from
  the external body. The audit allows exactly one such routing lookup per inbound rail.
- **RELATED:** [[saas-credits-billing]], [[TS-TENANCY-001]], [[app/Domain/Credits/Payments/PurchaseRouter.php]],
  [[app/Domain/Credits/Payments/PurchaseReconciler.php]], [[tests/Feature/Tenancy/PurchaseIsolationTest.php]]

### TS-CREDITS-005 — a PayPlus REFUNDED webhook after a paid top-up is currently a no-op (no clawback ledger row) — refund-policy follow-up
- **Date:** 2026-06-25
- **Category:** credits/ledger
- **Severity:** minor
- **Recurrence:** 1
- **Status:** open
- **Tags:** `refund`, `clawback`, `purchase-rail`, `webhook`, `adjustment`, `follow-up`, `phase-5b`

- **SYMPTOM:** Tracked design gap (NOT a bug — deliberate v1 behaviour), raised as
  gatekeeper suggestion S3. When a `REFUNDED` PayPlus webhook arrives AFTER a top-up has
  already been credited (`credit_purchases.ledger_id` set), `PurchaseReconciler::reconcile()`
  hits the already-credited `ledger_id` wall and is a NO-OP: the granted credits are NOT
  auto-reversed and no compensating `refund`/`adjustment` ledger row is written. The merchant
  keeps the credits after a provider-side refund until handled manually.
- **CONTEXT / TRIGGER:** Phase 5b purchase rail. A refund issued in the PayPlus dashboard (or
  a chargeback) for an already-paid, already-credited top-up.
- **ROOT CAUSE:** v1 intentionally does NOT auto-claw, because the granted credits may already
  be PARTLY SPENT on generations — a blind reversal could drive the balance negative or
  reverse value the merchant already consumed. A refund of granted-then-possibly-spent credits
  is a deliberate `adjustment`/`refund` ledger decision (and a possible balance-floor policy),
  not a silent automatic clawback. So the wall no-op is the safe default for now.
- **SOLUTION:** UNVERIFIED — follow-up for a later phase (refund/chargeback handling). Proposed:
  on a `REFUNDED` webhook for a credited purchase, do NOT mutate the ledger inline; instead
  raise an admin task / activity event and let an audited platform-admin action decide between
  an `adjustment` (down to a floor of 0, never negative) vs. writing it off, recording the
  decision as a NEW ledger row (append-only). Keep `credit_purchases` and `credit_ledger`
  separate; never edit the original `purchase` row. The behaviour is documented inline at
  `app/Domain/Credits/Payments/PurchaseReconciler.php` (the ledger_id-wall comment) and asserted
  by `tests/Feature/Credits/PurchaseRailTest.php::test_refund_after_paid_does_not_silently_claw_the_ledger`.
- **PREVENTION:** Do not auto-reverse credits that may be partially spent. A refund/chargeback
  on a prepaid-credit balance is an explicit, audited adjustment (floored at 0), recorded as a
  new append-only ledger row — never an inline clawback in the webhook path.
- **RELATED:** [[saas-credits-billing]], [[TS-CREDITS-002]], [[app/Domain/Credits/Payments/PurchaseReconciler.php]],
  [[tests/Feature/Credits/PurchaseRailTest.php]]

### TS-CREDITS-005 — a pre-processing GATE denial cannot transition `pending → failed` (the locked state machine forbids it); use `pending → cancelled`
- **Date:** 2026-06-25
- **Category:** credits/ledger
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `state-machine`, `generation`, `gate-denial`, `transition`, `cancelled`, `contract-tension`, `phase-6`

- **SYMPTOM:** The §7 money-path pseudocode shows a credit/lead-gate denial doing
  `gen.transitionTo(failed)` while the generation is still `pending`. But the LOCKED
  generation state machine (ARCHITECTURE.md §4 / `Generation::TRANSITIONS`) only allows
  `pending -> processing | cancelled` — `pending -> failed` is NOT a legal move. Coding
  the gate-denial path to `failed` would throw `IllegalTransitionException` on EVERY
  out-of-credits / signup-required denial (the most common non-success outcome), turning
  a typed business denial into a 500 — the exact thing the two-gates contract forbids.
- **CONTEXT / TRIGGER:** Phase 6, `GenerateTryOnJob` building the two independent gates.
  A gate runs BEFORE `transitionTo(processing)`, so the generation is `pending` when a
  denial is decided; the pseudocode's `failed` target is unreachable from `pending`.
- **ROOT CAUSE:** Two contract sections in mild tension: the prose pseudocode (§7) said
  `failed` for a gate denial; the canonical state machine (§4, the single source of
  truth) reserves `failed` for a PROCESSING-step failure and only allows `cancelled` as
  the pre-processing exit. The state machine wins (it is the locked truth); the
  pseudocode's `failed` was shorthand for "did not succeed", not a literal target.
- **SOLUTION:** A gate denial is a CANCELLED start, not a failed one:
  `app/Domain/Generation/GenerateTryOnJob.php::cancelOnGate()` stamps the reason on
  `generations.failure_code` (`signup_required` / `post_signup_limit_reached` /
  `insufficient_credits` / `account_inactive`) and transitions `pending -> cancelled`
  (a legal move), plus a `lead_gate_blocked` / `credit_gate_blocked` activity trace. The
  reason the widget needs lives in `failure_code` + the trace regardless of the status,
  so nothing is lost; `failed` stays reserved for a processing-step failure (the
  reservation-release path, `processing -> failed`). VERIFIED by
  `tests/Feature/Generation/GenerationGatesTest.php` (both gates -> `cancelled` + the
  right `failure_code`, NO OpenRouter call via `Http::assertNothingSent`, NO charge) and
  the status-machine test that proves `pending -> failed` throws.
- **PREVENTION:** When prose pseudocode and the canonical state machine disagree on a
  transition target, the STATE MACHINE is authoritative — adapt the implementation
  (here: `cancelled` for a pre-processing refusal), never widen the locked machine to
  match shorthand. A pre-work step on any pipeline: map every exit to a LEGAL transition
  before coding, and keep the human-facing reason on a separate column so the status can
  stay contract-pure.
- **RELATED:** [[laravel-backend]], [[app/Models/Generation.php]],
  [[app/Domain/Generation/GenerateTryOnJob.php]], [[app/Domain/Generation/GenerationFailureCode.php]],
  [[tests/Feature/Generation/GenerationGatesTest.php]], [[ARCHITECTURE.md]]

### TS-CREDITS-006 — non-atomic reservation `release()` (`has()`+`forget()`) double-decrements `reserved_micro_usd` under a concurrent double-release
- **Date:** 2026-06-25
- **Category:** credits/ledger
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `reservation`, `atomicity`, `toctou`, `double-release`, `cache-pull`, `reserved-micro-usd`, `phase-5-gatekeeper-S1`, `phase-6`

- **SYMPTOM:** Phase-5 gatekeeper finding S1 (folded into Phase 6): `ReservationManager::release()`
  guarded its decrement with `if (! $cache->has($key)) return; $cache->forget($key); decrement;`.
  Two concurrent releases (the FAILURE path racing a FINALIZE, or two workers) could BOTH
  pass `has()` before either `forget()`'d, then BOTH decrement — pushing `reserved_micro_usd`
  below the true held amount (and, with the clamp, silently mis-stating spendable credit).
- **CONTEXT / TRIGGER:** Phase 6 pre-work. The reservation is released on success (inside
  `CreditLedgerService::charge`) AND on failure (`release()`), and a racing finalize calls
  release too — so a double-release is a NORMAL concurrent case, not an edge.
- **ROOT CAUSE:** `has()` + `forget()` is a check-then-act (TOCTOU) pair, not atomic. The
  window between the two lets a second caller observe the key still present and also commit
  a decrement. The DB row-lock inside `adjustReserved()` serializes the WRITES but not the
  DECISION to write, so both deltas land.
- **SOLUTION:** Make the claim atomic with `Cache::pull($key)` (atomic get-and-delete):
  exactly ONE caller pulls a non-null value and may decrement; the losers pull null and
  no-op. `app/Domain/Credits/ReservationManager.php::release()` now reads
  `$held = $this->cache->pull($key); if ($held === null) return;` then decrements once.
  VERIFIED by `tests/Feature/Credits/ReservationManagerTest.php::test_concurrent_double_release_decrements_reserved_exactly_once`
  (reserved drops by the estimate ONCE, never to a double-decrement) + the never-held and
  re-reserve no-op cases.
- **PREVENTION:** Any "claim-once then act" on a cache key uses an ATOMIC primitive —
  `Cache::pull()` (get-and-delete) or `Cache::add()` (put-if-absent) — never `has()`+`forget()`
  or `has()`+`put()`. The money path's release/charge are idempotent BY CONSTRUCTION, not by
  a pre-check that can race. A DB row-lock serializes writes but does not make a check-then-act
  atomic — guard the DECISION, not just the write.
- **RELATED:** [[laravel-backend]], [[code-review-gatekeeper]], [[app/Domain/Credits/ReservationManager.php]],
  [[tests/Feature/Credits/ReservationManagerTest.php]], [[TS-CREDITS-002]]

### TS-CREDITS-007 — Phase-6 CHARGE-path money-safety + tenant-isolation spot-check: PASS (one non-blocking index gap)
- **Date:** 2026-06-25
- **Category:** credits/ledger
- **Severity:** minor (verdict is PASS; the one finding is a non-blocking performance index gap)
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `spot-check`, `charge-path`, `money-safety`, `double-charge`, `no-charge-on-failure`, `tenant-isolation`, `anti-theater`, `phase-6`, `composite-index`

- **SYMPTOM:** Release-blocker-class adversarial audit of the ONLY credit-debit path
  (`GenerateTryOnJob` + `CreditLedgerService::charge`/`release` + `ReservationManager` +
  `CreditMath` + `IdempotencyKey`). No defect found; verdict **SPOT-CHECK-PASS**.
- **CONTEXT / TRIGGER:** Phase-6 gate spot-check. Toolchain: PHP 8.4 (Herd), sqlite,
  HTTP + S3 faked. Baseline: `php artisan test` -> 272 passed (801 assertions).
- **ROOT CAUSE:** N/A — audit, not a bug. Recorded so the proof + the index gap are not re-derived.
- **SOLUTION (the proven facts):**
  1. **Charge math.** `finalizeSuccess` (`GenerateTryOnJob.php:350-352`) computes the
     charge from the REAL cost `$result->cost->costUsd` (TryOnResult) via
     `CreditMath::chargeMicroUsd(cost, multiplier)` = `round(cost × multiplier × 1e6)`;
     `multiplier = $config->creditMultiplier ?? CreditMath::multiplierFor($operationKey)`
     (per-op DB value else `config('trayon.pricing.markup_default')`). The ESTIMATE
     (`CreditEstimator`) is used ONLY for the gate + the reservation, never the charge.
     No `2.5`/`* 2.5` literal anywhere in `app/` (grep-clean; asserted by
     `GenerationInvariantsTest::test_no_model_id_or_markup_literal`).
  2. **No charge on failure (ANTI-THEATER PROVEN).** All five failure modes release the
     reservation and write ZERO charge rows, free-try NOT consumed: OpenRouterException ->
     AI_CALL_FAILED; any Throwable -> INTERNAL_ERROR; cost-unavailable -> COST_UNAVAILABLE;
     storeResult throws -> STORAGE_FAILED; gate denial -> `pending -> cancelled` BEFORE
     reserve (TS-CREDITS-005). Break A: invent a cost on the no-cost path -> a SECOND
     guard fires (`CreditMath::chargeMicroUsd(float ...)` TypeErrors on null — cannot
     charge $0) -> test RED. Break B: add a stray `charge()` in `finalizeFailure` ->
     balance 5_000_000 -> 4_000_000, both failure tests RED. Restored -> green.
  3. **Never charge twice (ANTI-THEATER PROVEN — wall is DEEPER than four layers).**
     Disabling LAYER 3 alone (`charge()` `existingByKey`) did NOT double-charge — the
     unique index (`resolveDuplicateOrThrow`) + the job's `lockAndPrecheck`/`finalizeSuccess`
     `hasCharge` guards still held (test stayed green = defense in depth). Forcing a true
     second charge required disabling ALL of: `lockAndPrecheck` isSucceeded/hasCharge +
     isPending short-circuit, `finalizeSuccess` hasCharge, AND a random idempotency key
     (to bypass the unique index) — and EVEN THEN the FIFTH backstop fired:
     `transitionTo(processing)` from `succeeded` throws (illegal transition) before the
     2nd charge lands -> `test_double_dispatch_same_generation_charges_once` RED. Restored.
  4. **Right account.** Key embeds `account_id` as segment 1 (`IdempotencyKey::forGeneration`);
     job carries explicit `accountId` (TenantAwareJob), binds via `Tenant::run`+finally;
     proven by `GenerationTenancyIsolationTest` (back-to-back A-then-B, each charge on its
     own `account_id`, no leak) + `TenantLeakBetweenJobsTest`.
  5. **Cross-account isolation.** `Generation` + `CreditLedger` are `BelongsToAccount`
     (fail-closed); B cannot read A's generations or charge rows; unbound query returns 0.
  6. **Reservation integrity.** Reserve is BEFORE the model call; TTL (300s) > job timeout
     (70s) asserted by `GenerationInvariantsTest`; `release()` is atomic via `Cache::pull`
     (TS-CREDITS-006) keyed on the idempotency key (`reserve` claims `cacheKey($idempotencyKey)`,
     `release` reads `cacheKey($reservation->id)` and `Reservation->id === idempotencyKey` —
     keys match), clamped at 0 -> a double-release decrements exactly once.
  7. **Append-only + linkage.** `credit_ledger` `updating`/`deleting` events throw; the
     charge is a NEW negative row; `generations.charge_ledger_id` links 1:1 to it
     (`finalizeSuccess` sets it from the returned charge row).
- **FINDING (non-blocking, performance — hand to laravel-backend/railway-infra):** the
  generations hot-path index is `(account_id, site_id, created_at)`
  (`database/migrations/...create_generations_table.php:85`); the audit spec §5.1 calls for
  `(account_id, site_id, status, created_at)` (status in the composite for status-filtered
  timeline/worker scans). NOT a security or money-safety blocker (isolation is by
  `account_id`, enforced by the global scope, not the index). Suggest adding `status` to the
  composite when status-filtered list queries land.
- **PREVENTION:** A money-path change must keep: charge from REAL cost (never the estimate),
  multiplier from config/DB (never a literal), the five-deep idempotency wall, reserve-before-call,
  atomic release, append-only ledger. Re-run this spot-check (break/red/restore on #2 and #3)
  at every Phase-6-touching gate; a green double-dispatch/failure test that does NOT go red
  when the guard is broken is theater — fix the test first.
- **RELATED:** [[saas-credits-billing]], [[laravel-backend]], [[TS-CREDITS-002]], [[TS-CREDITS-005]],
  [[TS-CREDITS-006]], [[TS-TENANCY-001]], [[app/Domain/Generation/GenerateTryOnJob.php]],
  [[app/Domain/Credits/CreditLedgerService.php]], [[app/Domain/Credits/CreditMath.php]],
  [[database/migrations/2026_06_25_130000_create_generations_table.php]]

### TS-CREDITS-008 — a LONG async render strands the reservation forever: the hold's cache TTL lapses, so the terminal release finds no key and never decrements `reserved_micro_usd`
- **Date:** 2026-07-13
- **Category:** credits/ledger
- **Severity:** blocker (silently destroys spendable credit)
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `reservation`, `ttl`, `hold-renewal`, `async`, `submit-poll`, `reserved-micro-usd`, `bulk`, `shopify-phase-4`

- **SYMPTOM:** Design defect found while building the async (submit → poll → finalize) bulk
  product-image pipeline. `ReservationManager::release()` only decrements
  `accounts.reserved_micro_usd` if it can `Cache::pull()` the in-flight key (the atomic
  claim from TS-CREDITS-006). The key's TTL is `trayon.credits.reservation_ttl` = **300s**.
  A bulk/async render (fal queue: minutes) outlives that. The key expires, the render then
  finishes, `release()`/`charge()` pull **null**, skip the decrement — and the hold stays on
  the account **forever**. Spendable = balance − reserved, so the merchant permanently loses
  that credit with no ledger row explaining it. Invisible: no error, no failed job, balances
  just drift down.
- **CONTEXT / TRIGGER:** Any generation whose provider call is longer-lived than the
  reservation TTL. The SYNC try-on/banner paths are safe by construction (`timeout=70` <
  300s, asserted by `GenerationInvariantsTest`), which is exactly why nothing caught it until
  a job intentionally spanned many minutes.
- **ROOT CAUSE:** The reservation is TWO stores that must agree — the durable column
  (`reserved_micro_usd`) and the short-lived cache key. Only the cache key expires. The
  release path is keyed on the cache key, so an expired key means "nothing to release", while
  the column still holds the money. The TTL was sized for a 70s job, and the invariant
  "key present ⇔ column holds this estimate" quietly breaks the moment a render is slower.
- **SOLUTION:** RENEW the hold on every poll tick, and make renew a `put`, never an increment.
  1. `app/Domain/Credits/ReservationManager.php::renew(Reservation)` — re-stamps the cache key
     with the held amount and a FULL fresh TTL and **does not touch the column**. Safe in both
     directions: key alive → extend; key already lapsed → re-stamp (the column was never
     decremented, so this RESTORES the invariant instead of double-holding).
  2. `app/Domain/ProductImages/PollProductImageJob::lockRenewAndClaim()` calls it **inside the
     asset's row-locked transaction**, and only while the asset is still `processing` — the
     same lock the terminal release runs under. That serialises renew against release, so a
     renew can never resurrect a hold a release already claimed (which would double-decrement).
  3. The poll budget (MAX_ATTEMPTS × DELAY = 60 × 10s) stays well inside the renewed TTL, so
     two consecutive ticks can never straddle an expiry.
  VERIFIED (mutation-proven, not theater):
  `tests/Feature/ProductImages/ProductImageMoneyPathTest::test_a_long_render_renews_the_hold_so_it_never_lapses_mid_generation`
  travels 200s + 200s (past the 300s TTL) across two poll ticks and asserts the hold is still
  held mid-flight and `reserved_micro_usd` returns to 0 after the charge. **Delete the
  `renew()` call → RED** ("The in-flight hold must survive a render longer than the
  reservation TTL"); restored → green.
- **PREVENTION:** Any provider call that can outlive `trayon.credits.reservation_ttl` MUST
  renew its hold on each progress tick (under the row lock), or the hold is stranded. When
  adding a new money path, check the pair: **max wall-clock time of the call vs. the
  reservation TTL** — if the call can be longer, renewal is mandatory, not optional. A hold is
  only ever released by the code path that also ends the row's non-terminal status.
- **RELATED:** [[laravel-backend]], [[TS-CREDITS-006]], [[TS-CREDITS-007]],
  [[app/Domain/Credits/ReservationManager.php]], [[app/Domain/ProductImages/PollProductImageJob.php]],
  [[tests/Feature/ProductImages/ProductImageMoneyPathTest.php]]

## openrouter/ai

### TS-OPENROUTER-004 — fal.ai documents NO idempotency key: a re-submitted render bills twice, so the submit-once wall must be OURS (DB-backed), not the provider's
- **Date:** 2026-07-13
- **Category:** openrouter/ai
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `fal`, `idempotency`, `queue-api`, `double-submit`, `async`, `provider-request-id`, `money-safety`, `shopify-phase-4`

- **SYMPTOM:** The Phase-4 plan called for calling the provider queue API "with a **provider
  idempotency key**" so a repeated submit cannot double-generate. Building it, the assumption
  failed: fal's queue API has **no such mechanism**. Coding against an invented header
  (`X-Fal-Idempotency-Key`) would have produced a guarantee that does not exist — the worst
  kind: a second submit would silently start a SECOND paid render.
- **CONTEXT / TRIGGER:** `FalImageClient::submitAsync()` (the async seam for bulk product-image
  generation). Any retry/at-least-once delivery of the submit job.
- **ROOT CAUSE:** Verified against fal's own docs (`https://fal.ai/docs/model-endpoints/queue`,
  fetched 2026-07-13): the submit reply carries `request_id`, `status_url`, `response_url`,
  `cancel_url`, `queue_position`; the statuses are `IN_QUEUE` / `IN_PROGRESS` / `COMPLETED`.
  There is **no documented idempotency header or body field** — the docs explicitly leave
  deduplication to the caller ("track request_id values yourself").
- **SOLUTION:** Make the submit-once wall DB-backed and prove it. The key is still threaded
  through the seam (`AsyncImageGenerationProvider::submitAsync(..., string $idempotencyKey)`)
  so an upstream that DOES support one can use it — but the guarantee comes from three of our
  own layers, in `app/Domain/ProductImages/SubmitProductImageJob.php`:
  1. `ShouldBeUnique` on the deterministic asset key (a duplicate dispatch is dropped);
  2. the row-locked `lockAndPrecheck()` — an asset that is not `pending` never submits;
  3. **the provider_request_id wall** — an asset that already carries a `provider_request_id`
     NEVER submits again (the crash-recovery shape: fal accepted the render, the worker died
     before the status moved). The retry hands off to the POLLER instead.
  A network blip after submit therefore retries the *poll* of the SAME `request_id`; it can
  never start a second render.
  VERIFIED (mutation-proven):
  `ProductImageMoneyPathTest::test_a_resubmit_of_an_already_accepted_render_never_submits_again`
  forces the row back to `pending` with the ticket stored and re-runs the submit job — the
  upstream submit count stays 1. **Delete the provider_request_id check → RED** (2 submits).
- **PREVENTION:** Before relying on a provider's idempotency key, VERIFY it exists in that
  provider's docs — most image/video queue APIs (fal included) have none. For any paid async
  call, the submit-once guarantee must be ours: a deterministic key + `ShouldBeUnique` + a
  row-locked "already has a provider_request_id → never re-submit" wall, with the retry path
  pointed at the POLL. Persist the provider's request id in the SAME transaction that moves
  the row to `processing`.
- **RELATED:** [[laravel-backend]], [[ai-openrouter]], [[TS-CREDITS-008]],
  [[app/Domain/Ai/FalImageClient.php]], [[app/Domain/Ai/Contracts/AsyncImageGenerationProvider.php]],
  [[app/Domain/ProductImages/SubmitProductImageJob.php]]

### TS-OPENROUTER-001 — OpenRouter doc URLs drifted; verified the current API shape before coding
- **Date:** 2026-06-24
- **Category:** openrouter/ai
- **Severity:** minor
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `openrouter`, `api-shape`, `usage-cost`, `structured-outputs`, `image-generation`, `fallbacks`, `docs-drift`

- **SYMPTOM:** Pre-build WebFetch of the OpenRouter docs returned 404 ("This page
  does not exist") for the obvious paths: `/docs/api-reference`,
  `/docs/api-reference/chat-completion`, `/docs/features/structured-outputs`,
  `/docs/features/multimodal`, `/docs/use-cases/usage-accounting`. Coding the
  client against assumptions was the risk.
- **CONTEXT / TRIGGER:** Phase 3 (AI control plane), confirming the chat/completions
  request+response shape, cost fields, structured outputs, image-gen output, and
  model fallbacks before writing `OpenRouterClient`.
- **ROOT CAUSE:** OpenRouter restructured its docs site; the `/docs/features/*` and
  `/docs/use-cases/*` paths moved under `/docs/guides/*` and `/docs/cookbook/*`.
  The LLM-friendly index at `/docs/llms-full.txt` still resolves and lists the new
  paths.
- **SOLUTION:** Fetch `https://openrouter.ai/docs/llms-full.txt` first to discover
  current paths, then the specific guides. VERIFIED current API shape used by the
  client:
  - Endpoint `POST https://openrouter.ai/api/v1/chat/completions`; headers
    `Authorization: Bearer <key>`, `HTTP-Referer`, `X-Title` (server-side only).
  - **Cost is always inline now:** `usage.cost` (USD) is included in every response;
    the `usage:{include:true}` param is DEPRECATED / no-op. `cost_details` carries
    `upstream_inference_cost`. Endpoint fallback for lag:
    `GET /api/v1/generation?id=<gen_id>` -> `data.total_cost`. Implemented:
    `app/Domain/Ai/OpenRouterClient.php` `extractInlineCost()` -> `lookupGenerationCost()`
    -> `ParsedCost::unavailable()` (NEVER guess a cost).
  - **Structured outputs:** `response_format: {type:'json_schema', json_schema:{name,
    strict:true, schema:{... additionalProperties:false}}}`. Not all models honor it,
    so the scan caller keeps a single repair pass + `invalid_json` fallback.
  - **Image generation (chat path):** request `modalities:['image','text']`; the
    image returns in `choices[0].message.images[].image_url.url` as a base64 data
    URL (the dedicated images endpoint instead uses `data[].b64_json`). The try-on
    caller extracts BOTH defensively (`TryOnGenerationCaller::extractImage()`).
  - **Fallbacks:** OpenRouter supports a provider-side `models:[primary, fallback]`
    array, but we OWN the fallback in the client (`callWithFallback()`) for cleaner
    error classification; the response `model` field reports the model actually used.
  Verified by the HTTP-mocked suite (`tests/Feature/Ai/*`) — 36 passing.
- **PREVENTION:** Before coding any OpenRouter surface, fetch `/docs/llms-full.txt`
  to resolve current doc paths (the `/docs/features/*` and `/docs/use-cases/*` URLs
  are stale). Do NOT assume `usage:{include:true}` is needed — cost is inline by
  default. Keep the cost-endpoint fallback + the `cost_unavailable` honest-null path
  because the inline cost or the endpoint can still be absent/lag.
- **RELATED:** [[ai-openrouter]], [[app/Domain/Ai/OpenRouterClient.php]],
  [[app/Domain/Ai/ProductScanCaller.php]], [[app/Domain/Ai/TryOnGenerationCaller.php]],
  [[config/services.php]]

### TS-OPENROUTER-002 — prompts table mixes global + tenant rows; NOT BelongsToAccount, resolved tenant-aware instead
- **Date:** 2026-06-24   (re-occurrence: 2026-06-24 — adversarial spot-check by `saas-credits-billing`)
- **Category:** openrouter/ai
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `prompts`, `belongs-to-account`, `global-scope`, `tenant-isolation`, `resolution-order`, `allow-list`, `spot-check`

- **SYMPTOM:** Design tension, not a runtime bug: the `prompts` table must hold
  PLATFORM-GLOBAL rows (`scope=global` / `scope=product_type`, no tenant) AND
  TENANT-OWNED rows (`scope=account` / `scope=site`, `account_id NOT NULL`) in one
  table. Putting `BelongsToAccount` on the model would make its fail-closed global
  scope ALSO hide the global rows (returns nothing when no tenant is bound), breaking
  the guaranteed `global` resolution floor. Leaving it off naively risks a
  cross-account read of another account's `scope=account`/`scope=site` prompt.
- **CONTEXT / TRIGGER:** Phase 3, deciding how `Prompt` reconciles the dual scope with
  the Phase-2 tenancy contract while keeping the isolation audit able to reason about it.
- **ROOT CAUSE:** The automatic `BelongsToAccount` global scope is all-or-nothing per
  model; it cannot conditionally apply to only the tenant-scoped rows of a mixed table.
- **SOLUTION:** `Prompt` is deliberately NOT `BelongsToAccount` and IS on
  `GlobalModels::ALLOW_LIST` (audited-global). Isolation for the tenant rows is
  enforced EXPLICITLY by dedicated query scopes used only by the resolver:
  `Prompt::scopeSiteScoped($accountId, $siteId, $op)` and
  `scopeAccountScoped($accountId, $op)` ALWAYS constrain by `account_id` (no
  cross-account read); `scopeProductTypeScoped()` / `scopeGlobalScoped()` read the
  global rows (`whereNull('account_id')`). `AiOperationResolver::resolvePrompt()`
  walks site -> account -> product_type -> global using those scopes. Verified:
  `tests/Feature/Ai/AiOperationResolverTest` —
  `test_account_site_prompt_is_not_resolved_for_another_account` and
  `test_account_scoped_prompt_does_not_leak_across_accounts` prove account A's
  site/account prompt is never resolved for account B (it falls through to global).
- **PREVENTION:** A mixed global+tenant table must NOT carry `BelongsToAccount`
  (its scope would hide the global rows). Instead: register it on the allow-list,
  and enforce tenant isolation with explicit, always-account-constrained query
  scopes at the single resolution call site. The isolation audit checks the
  resolver's account/site legs always filter by `account_id`. Never read
  account/site prompts with a bare, unconstrained query.
- **SPOT-CHECK (2026-06-24, `saas-credits-billing`, release-blocker-class):** VERDICT
  **PASS — clear to ship.** An adversarial cross-account resolution audit was run on
  this design (the one allow-list-exempt model isolated by explicit resolver scopes,
  not the global scope). Findings:
  - The ONLY read paths for `Prompt` in `app/` are the four model query scopes
    (`app/Models/Prompt.php:85-132`) and the resolver
    (`app/Domain/Ai/AiOperationResolver.php:151-190`). No bare/unconstrained
    `Prompt::query()`/`Prompt::where()` on account/site rows exists outside the
    resolver (grep-verified). No `withoutGlobalScopes()` and no ambient/`Tenant`
    inference in the resolver — the account/site legs take `account_id` from the
    passed `$site->account_id`, an explicit argument.
  - The account/site legs ALWAYS filter on `account_id` (and site on `site_id` too);
    the global/product_type legs ALWAYS `whereNull('account_id')` so a tenant row
    cannot masquerade as the global floor.
  - Proven by `tests/Feature/Tenancy/PromptResolutionIsolationTest.php` (7 cases,
    18 assertions): B's MORE-specific + NEWER + HIGHER-version prompt never resolves
    for A; A with no prompt drains to GLOBAL, never to B; a poisoned `scope=global`/
    `scope=product_type` row with a non-null `account_id` is excluded; site→sibling-site
    isolation within one account; both `account_id` AND `site_id` required on the site
    leg. NOT theater: removing the site-leg `account_id` filter reds the direct site
    probe; removing the global-leg `whereNull('account_id')` reds the masquerade probe;
    restored → all 16 (new + existing resolver) green.
  - NON-BLOCKING note (tracked, no fix required): the resolver's site/account legs are
    safe under the system invariant that `sites.id` and `accounts.id` are GLOBALLY
    UNIQUE auto-increment keys, so a cross-account `site_id` collision cannot occur via
    the resolver path. The model scopes still defend in depth (they filter on
    `account_id` independently), which is why the direct-scope probes — not just the
    integration flow — are the load-bearing assertions. If site/account ids ever
    become non-global (e.g. per-tenant sequences), re-confirm the account_id
    constraint is what isolates, not the id uniqueness.
- **RELATED:** [[ai-openrouter]], [[saas-credits-billing]], [[app/Models/Prompt.php]],
  [[app/Domain/Ai/AiOperationResolver.php]], [[app/Support/GlobalModels.php]],
  [[tests/Feature/Tenancy/PromptResolutionIsolationTest.php]], [[TS-TENANCY-003]]

### TS-OPENROUTER-003 — real backoff sleeps flake the money-path suite; ParsedCost allowed a null cost to look "available"
- **Date:** 2026-06-25
- **Category:** openrouter/ai
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `sleep`, `usleep`, `retry-backoff`, `flaky-tests`, `parsed-cost`, `null-cost`, `money-path`, `invariant`

- **SYMPTOM:** Two coupled problems surfaced at the Phase-6 gate.
  (1) The generation-job gatekeeper review hit `CreditMath::chargeMicroUsd(null, ...)`
  TypeError reached from the SUCCESS path — a "cost available" result carrying a null
  cost slipped into the charge math.
  (2) The money-path suite was non-deterministic across test orderings: intermittent
  failures (the null-cost TypeError + an illegal `succeeded→processing` transition),
  driven by real wall-clock sleeps in the retry/cost-lookup backoff.
- **CONTEXT / TRIGGER:** Phase 6 (generation pipeline) running the full suite;
  `OpenRouterClient` retry/cost-lookup backoff + `ParsedCost` from the
  `ai-openrouter` layer.
- **ROOT CAUSE:**
  (1) `ParsedCost` did NOT enforce its own invariant. The `inline()`/`fromEndpoint()`
  factories take a non-null `float` and `OpenRouterClient::parseCost()` guards every
  factory with a `!== null` check, so the contradictory `available=true, costUsd=null`
  was NOT reachable through this layer's own parse path. BUT the public constructor
  accepted `?float $costUsd` and `bool $available` as INDEPENDENT args — nothing
  coupled them — so any direct/future caller (or a hand-built test double) could
  construct `new ParsedCost(null, true, 'inline')`, and a downstream that charges on
  `available === true` would then feed `null` into the charge math. Latent, not
  reached through `parseCost()`, but structurally possible.
  (2) The backoff used raw `usleep()` + `random_int()` jitter with no seam to fake,
  so retry-exercising tests slept for real (~0.4–1.2 s each). Under varying test
  order the real delays + shared faked-clock/transition state intermittently raced.
- **SOLUTION:**
  (1) Enforce the invariant IN `ParsedCost`'s constructor — "available" and
  "non-null cost" are the same thing. `app/Domain/Ai/ParsedCost.php`: the constructor
  now NORMALIZES any contradictory combination — `if ($costUsd === null) { $available
  = false; $source = SOURCE_UNAVAILABLE; }` — so a null cost can NEVER present as
  available. Chose NORMALIZE over THROW: a money-path layer should fail SAFE (collapse
  to the honest-null `unavailable`, letting laravel-backend release/reconcile) rather
  than crash the success path with an exception. Proven by
  `tests/Unit/Ai/ParsedCostTest.php` (`test_null_cost_can_never_be_constructed_as_available`,
  `test_available_always_implies_a_non_null_cost`, and the endpoint-fallback-empty →
  unavailable case).
  (2) Replace raw `usleep()` with Laravel's `Illuminate\Support\Sleep` facade so
  production behaviour is identical but tests can `Sleep::fake()`.
  `app/Domain/Ai/OpenRouterClient.php`: `sleepBackoff()` / `sleepCostBackoff()` now use
  `Sleep::for($ms)->milliseconds()`. Random jitter stays (thundering-herd protection)
  but under `Sleep::fake()` no real time passes, so tests assert the backoff by COUNT
  (`Sleep::assertSleptTimes()`), not exact duration — jitter-agnostic + deterministic.
  All AI test `setUp()`s call `Sleep::fake()`. Verified: AI domain suite 46 passing
  (was real-sleeping at ~0.9–1.2 s/case, now ~0.05 s); full suite 280 passing.
- **PREVENTION:**
  - A value object guarding a money quantity must enforce its OWN cross-field
    invariant in the constructor (couple `available` ⇔ non-null cost), not rely on
    every factory/caller to keep them consistent. A nullable money field must be
    fail-safe by construction.
  - NEVER use raw `usleep()`/`sleep()` in code a test must exercise; use the `Sleep`
    facade so backoff is fakeable. Assert backoff by `Sleep::assertSleptTimes()`
    (count), not by duration, when jitter is involved.
  - `code-review-gatekeeper` greps `app/` for raw `usleep(`/`sleep(` (use `Sleep`)
    and for a `ParsedCost`/cost VO constructed with a nullable cost + a truthy
    "available" flag.
- **RELATED:** [[ai-openrouter]], [[laravel-backend]], [[saas-credits-billing]],
  [[app/Domain/Ai/ParsedCost.php]], [[app/Domain/Ai/OpenRouterClient.php]],
  [[tests/Unit/Ai/ParsedCostTest.php]], [[tests/Feature/Ai/OpenRouterClientTest.php]]

## pdp-scan

### TS-PDPSCAN-006 — a private helper named `hydrate*` is hijacked as a Livewire lifecycle hook → route-model-binds its typed param every request
- **Date:** 2026-07-05
- **Category:** pdp-scan
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `livewire`, `lifecycle-hook`, `hydrate`, `route-model-binding`, `scan-review`, `role-picker`, `ws4`

- **SYMPTOM:** Adding a private helper `hydrateDimensionPicks(Product $product)` to the
  `ReviewProduct` Livewire page broke EVERY `->call(...)` on that page (and the existing
  `ScanReviewAndEmbedTest` cases that call after mount) with
  `ErrorException: Array to string conversion` originating in `ModelNotFoundException::setModel`
  → `Livewire\ImplicitlyBoundMethod::getImplicitBinding`. The page rendered on mount but any
  subsequent Livewire request 500'd.
- **CONTEXT / TRIGGER:** WS4 scan-review visual role picker. The helper seeded dimension-pick
  state from `physical_dimensions` and took a typed `Product` parameter; it was called from the
  existing `hydrateFromReview()` (which is param-less and had always been harmless).
- **ROOT CAUSE:** Livewire treats ANY component method whose name starts with a reserved
  lifecycle prefix (`hydrate`, `dehydrate`, `mount`, `updating`, `updated`, `rendering`,
  `rendered`, `boot`, …) as a LIFECYCLE HOOK and invokes it on the matching lifecycle event via
  the container, resolving its parameters. On `hydrate`, it tries to implicitly ROUTE-MODEL-BIND
  a class-typed parameter (`Product $product`) from the request payload → `resolveRouteBinding`
  returns null → `ModelNotFoundException`. The param-less `hydrateFromReview()` never tripped this
  because a hook with no args is a harmless no-op call; the typed-param version is not.
- **SOLUTION:** Rename the helper off the reserved prefix — `seedDimensionPicks(Product $product)`
  (`app/Filament/Merchant/Pages/ReviewProduct.php`), called from `hydrateFromReview()` exactly as
  before. Behaviour identical; Livewire no longer treats it as a hook. VERIFIED:
  `ScanReviewAndEmbedTest` back to green + the new `ScanReviewRolePickerTest` (5 cases) passes;
  full suite `php artisan test` → 601 passed.
- **PREVENTION:** Never name a Livewire component's private helper with a lifecycle-hook prefix
  (`hydrate*`/`dehydrate*`/`mount*`/`updat*`/`render*`/`boot*`). Use a neutral verb (`seed*`,
  `prime*`, `loadX`). A helper that takes a typed model param is especially dangerous under a
  hook prefix because Livewire will try to route-model-bind it every request. The existing
  param-less `hydrateFromReview()` masked the rule because a no-arg hook call is silent.
- **RELATED:** [[pdp-scanner]], [[admin-design-system]], [[app/Filament/Merchant/Pages/ReviewProduct.php]],
  [[tests/Feature/Filament/Merchant/ScanReviewRolePickerTest.php]]

### TS-PDPSCAN-007 — the physical_dimensions summary row `{{ $dimValue }}` blows up on a NESTED dimension group (picks / size_map)
- **Date:** 2026-07-05
- **Category:** pdp-scan
- **Severity:** minor
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `blade`, `physical-dimensions`, `nested-array`, `htmlspecialchars`, `scan-review`, `ws4`

- **SYMPTOM:** After confirming a product with visually-picked size/weight, re-rendering the
  scan-review page threw `htmlspecialchars(): Argument #1 ($string) must be of type string, array
  given (View: review-product.blade.php)` in the `@case('physical_dimensions')` field-row.
- **CONTEXT / TRIGGER:** WS4. The dimensions summary row iterated `physical_dimensions` as
  `$dimKey => $dimValue` and echoed `{{ $dimValue }}`. The dimension JSON now legitimately holds
  NESTED groups: `picks` (`size` → `{selector,value}`) written by the confirm path, and the
  AI-extracted `size_map` (`M` → `{chest:100}`). Both are arrays, so `{{ $dimValue }}` (which runs
  `htmlspecialchars` via `e()`) fataled. The `size_map` shape would have hit this too, but no prior
  test confirmed-then-re-rendered a product carrying dimensions.
- **ROOT CAUSE:** `physical_dimensions` is a free-form JSON bag mixing scalar leaves (chest: 100)
  and nested groups; a summary row that assumes every value is scalar is wrong by construction.
- **SOLUTION:** Guard the echo — `{{ is_scalar($dimValue) ? $dimValue : __('scan.field.dimensions') }}`
  in `resources/views/filament/merchant/pages/review-product.blade.php`: scalars print, nested
  groups render their localized group label instead of exploding. VERIFIED by
  `ScanReviewRolePickerTest::test_picking_a_dimension_role_reads_the_value_and_round_trips_into_physical_dimensions`
  (previously RED on this exact throw, now green).
- **PREVENTION:** When rendering a free-form JSON bag in Blade, never `{{ $value }}` a bag entry
  without an `is_scalar()`/`is_array()` guard — mixed scalar-and-nested shapes are the norm for
  a `physical_dimensions`/`meta`-style column. Summarise nested groups; only echo scalars.
- **RELATED:** [[pdp-scanner]], [[admin-design-system]], [[app/Domain/Scan/Review/ConfirmScanAction.php]],
  [[resources/views/filament/merchant/pages/review-product.blade.php]]

### TS-PDPSCAN-005 — A4 scan-review i18n keys: the read model references 5 keys not yet in lang/*/scan.php
- **Date:** 2026-06-29
- **Category:** pdp-scan
- **Severity:** minor
- **Recurrence:** 1
- **Status:** open (UI/i18n agent must add the keys; pdp-scanner owns the contract, not lang/)
- **Tags:** `i18n`, `scan-review`, `phase-8e`, `contract`, `a4`

- **SYMPTOM:** The Phase-8e scan-review READ model (`app/Domain/Scan/Review/ScanReview.php`)
  emits per-row `label_key` / `confidence_i18n_key` the A4 form binds to. Five referenced
  keys are NOT yet in `lang/en/scan.php` (+ `lang/he`), so a literal `__()` of them would
  render the raw key until the UI agent adds them.
- **CONTEXT / TRIGGER:** Phase 8 Wave 2 (8e). pdp-scanner OWNS the scan boundary + the
  contract shape but does NOT edit `lang/` (a UI/product-ux agent owns the catalog).
  The read model added `product_type` + `main_image` field rows (the scan extracts
  both), the sixth `description` SELECTOR role (only 5 were catalogued), and two extra
  selector-test outcomes (`multiple`, `error`) beyond `test_ok`/`test_fail`.
- **ROOT CAUSE:** The i18n catalog was specced before the field/selector/test-outcome
  list was finalised by the scanner. The contract is now the source of truth.
- **SOLUTION (for the UI/i18n agent — add to BOTH `lang/en/scan.php` and `lang/he/scan.php`,
  mirrored 1:1, and the `docs/ux/i18n-catalog.md` table):**
  - `scan.field.product_type` — "Product type" / "סוג מוצר"
  - `scan.field.main_image` — "Main image" / "תמונה ראשית"
  - `scan.selector.description` — "Description element" / "רכיב התיאור"
  - `scan.selector.test_multiple` — "Matches several elements — narrow it" / "מתאים למספר רכיבים — צמצמו"
  - `scan.selector.test_error` — "Could not test — see the scan error" / "הבדיקה נכשלה — ראו שגיאת סריקה"
  The four confidence-level keys (`scan.confidence.high|medium|low|none`) already exist
  and the bucketing is the single `ConfidenceLevel` value object — no new confidence keys.
- **PREVENTION:** When an agent owns a CONTRACT but not its i18n catalog, it must LIST the
  exact keys the contract needs and log them here rather than silently editing another
  agent's `lang/`. The level→key map is `ScanConstants::LEVEL_I18N_KEY`; the row label keys
  are `ScanReview::FIELD_LABEL_KEYS` + `scan.selector.{role}`; the test-outcome keys are in
  `SelectorTestResult`.
- **RELATED:** [[pdp-scanner]], [[admin-design-system]], [[product-ux-architect]],
  [[app/Domain/Scan/Review/ScanReview.php]], [[app/Domain/Scan/Review/ConfidenceLevel.php]],
  [[app/Domain/Scan/Review/SelectorTestResult.php]], [[lang/en/scan.php]],
  [[docs/ux/i18n-catalog.md]]

### TS-PDPSCAN-004 — SSRF: guard validated the host STRING only; resolved-IP / redirect / byte-cap gaps
- **Date:** 2026-06-25
- **Category:** pdp-scan
- **Severity:** blocker
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `ssrf`, `egress`, `dns-rebinding`, `metadata`, `redirect`, `byte-cap`, `curl-resolve`, `host-resolver`

- **SYMPTOM:** The Phase-4 gate was BLOCKED by 4 SSRF findings on the scan fetch
  layer (the scanner fetches MERCHANT-SUPPLIED URLs server-side). Proven holes:
  `http://metadata.google.internal/x` and `http://0177.0.0.1/x` (octal loopback)
  both PASSED the guard; a `302 → http://169.254.169.254/` was followed; the
  `MAX_BYTES` cap ran AFTER a full `$response->body()` buffer (OOM/slow-loris/
  decompression-bomb vector); and the egress tests covered only literal IPs, so the
  green suite was blind to all three.
- **CONTEXT / TRIGGER:** Phase 4. `UrlGuard::isPublicHttpUrl()` checked only the
  host STRING with `filter_var(..., NO_PRIV_RANGE|NO_RES_RANGE)`. A hostname (or an
  octal/hex/integer IPv4 literal) that RESOLVES to a private/metadata IP was never
  resolved, so it slipped through. Redirects used Guzzle `allow_redirects(max 5)`
  with the guard run on the INPUT url only. `cappedBody()` did `$response->body()`
  then `substr()`.
- **ROOT CAUSE:** Classic egress-filter gap — "validate the input host string, never
  the RESOLVED IP / the redirect target / the stream length." The host string is not
  the connection target: DNS (incl. rebinding/TOCTOU), obfuscated IP encodings, and
  redirect hops all decouple the validated name from the IP actually dialled. A
  post-download byte cap is not a cap at all.
- **SOLUTION:** A single guarded + PINNED egress path all three scan fetches ride
  (page / robots.txt / render sidecar), network-free testable via two seams:
  1. **Resolve, then validate every resolved IP.** `HostResolver` interface
     (`app/Domain/Scan/Fetch/HostResolver.php`) + `SystemHostResolver` (real A via
     `gethostbynamel` + AAAA via `dns_get_record`). `UrlGuard::resolveAndValidate()`
     resolves the host and refuses if ANY A/AAAA address is private/loopback/link-
     local/reserved. `IpNormaliser` unmasks octal/hex/dword/short IPv4 to canonical
     dotted-quad BEFORE the range check (`0177.0.0.1 → 127.0.0.1`), blocks all
     private/reserved v4+v6 CIDRs (incl. 169.254.169.254), and unwraps IPv4-mapped
     IPv6 (`::ffff:10.0.0.1`).
  2. **PIN the connection to the validated IP.** `GuzzleSingleHopTransport` passes
     `CURLOPT_RESOLVE = ["host:port:ip"]` (host:port pinned to the resolved IP, Host
     header + TLS SNI unchanged), closing the DNS-rebinding/TOCTOU window between
     check and connect.
  3. **Re-guard EVERY redirect hop.** `allow_redirects` is DISABLED; `GuardedHttpClient`
     follows `Location` manually, re-running step 1+2 per hop and capping hops — so a
     `302 → 169.254.169.254` is refused, never dialled.
  4. **Cap the body MID-STREAM.** `CURLOPT_WRITEFUNCTION` feeds chunks through a
     `BoundedSink` that returns `< strlen($chunk)` (aborts the curl transfer) the
     instant `MAX_BYTES` is crossed — the whole body is never buffered.
  VERIFIED: `tests/Feature/Scan/FetchEgressGuardTest.php` (+ `FetchStrategyTest`)
  with RED-WHEN-REMOVED proofs for each: removing the resolved-IP rejection reds the
  loopback/metadata/private-resolution tests; removing the `IpNormaliser::normalise`
  branch reds the octal/hex/dword tests; guarding only hop 0 reds the redirect test;
  removing the crossing-the-ceiling abort in `BoundedSink` reds the mid-stream cap
  tests. Full suite `php artisan test` → 136 passed (395 assertions), up from 123.
- **PREVENTION:** For ANY server-side fetch of an attacker-influenced URL: (a) never
  trust the host string — resolve and validate the RESOLVED IP set; normalise
  obfuscated IP literals first; (b) PIN the connection to the validated IP
  (`CURLOPT_RESOLVE`) so resolution can't change between check and connect; (c)
  disable auto-redirects and RE-GUARD each hop; (d) enforce the byte cap MID-STREAM,
  never post-download. Put DNS behind an injectable `HostResolver` so the guard is
  provable with zero real lookups. The generation/media-fetch paths must inherit
  this same `GuardedHttpClient` if they ever fetch external URLs.
- **RELATED:** [[pdp-scanner]], [[saas-credits-billing]], [[code-review-gatekeeper]],
  [[app/Domain/Scan/Fetch/UrlGuard.php]], [[app/Domain/Scan/Fetch/IpNormaliser.php]],
  [[app/Domain/Scan/Fetch/HostResolver.php]], [[app/Domain/Scan/Fetch/GuardedHttpClient.php]],
  [[app/Domain/Scan/Fetch/GuzzleSingleHopTransport.php]], [[app/Domain/Scan/Fetch/BoundedSink.php]],
  [[tests/Feature/Scan/FetchEgressGuardTest.php]]

### TS-PDPSCAN-001 — locale-comma price `₪1.299,00` mis-parses to `1.299` with naive parsing
- **Date:** 2026-06-24
- **Category:** pdp-scan
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `price`, `currency`, `locale`, `decimal-separator`, `money`, `minor-units`

- **SYMPTOM:** A `(float) str_replace(',', '', $raw)`-style parse reads the European
  `1.299,00` (= 1299.00) as `1.299` and the ILS `₪1,299.00` correctly only by luck —
  the decimal separator is locale-dependent and cannot be assumed to be `.`. A wrong
  parse silently mis-prices the product the widget shows.
- **CONTEXT / TRIGGER:** Phase 4, mapping `ScanResult.json` price + JSON-LD/OG price
  hints into `Product.price_minor`. Stores in de/he/fr locales use `.` or a space as
  the THOUSANDS separator and `,` as the DECIMAL separator — the inverse of en-US.
- **ROOT CAUSE:** Decimal vs thousands separator is a locale property, not a constant.
  A single `str_replace` cannot know which of `.`/`,` is the decimal point; the layout
  (which separator appears LAST, how many trailing digits) determines it.
- **SOLUTION:** `app/Domain/Scan/Map/MoneyParser.php` detects the currency FIRST
  (explicit ISO hint > unambiguous symbol `₪€£` > ISO code in string > ambiguous `$`
  = low-confidence USD), then detects the number locale from the punctuation layout
  (the separator appearing LAST is decimal; `.`/`,`/space/NBSP grouping handled), then
  parses to integer MINOR units — never a lossy float. "from"/range prices are flagged
  not truncated; symbol-only currency lowers confidence + raises a warning. VERIFIED by
  `tests/Feature/Scan/MoneyParserTest.php` (9 cases): `₪1,299.00`→129900, `1.299,00`
  (EUR)→129900 (the scar case), `€49,95`→4995, `1 299,00`→129900, `$1,299.99`→129999
  with low confidence, `From ₪199`→range+19900.
- **PREVENTION:** Never `str_replace`-then-cast a price. Detect currency + number
  locale, parse to minor units, store integer minor units + ISO currency. A symbol-only
  or guessed-decimal parse is lower confidence and flagged for merchant review.
- **RELATED:** [[pdp-scanner]], [[app/Domain/Scan/Map/MoneyParser.php]],
  [[app/Domain/Scan/Map/ProductMapper.php]], [[tests/Feature/Scan/MoneyParserTest.php]]

### TS-PDPSCAN-002 — a `final` fetch class blocks the no-network test double; introduce a PageSource seam
- **Date:** 2026-06-24
- **Category:** pdp-scan
- **Severity:** minor
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `testing`, `final-class`, `interface-seam`, `mock`, `http-fake`, `di`

- **SYMPTOM:** `ScanProductJobTest` tried to bind a fixture-returning test double by
  `extends PageFetcherManager` and fataled: `Class @anonymous cannot extend final class
  PageFetcherManager`. The job resolves the fetcher from the container, so the scan
  could not be exercised without a real network fetch.
- **CONTEXT / TRIGGER:** Phase 4, writing the full-scan happy-path test (network +
  OpenRouter MOCKED). The CONST-at-top / SRP convention keeps domain classes `final`,
  which is correct, but a `final` concrete dependency cannot be subclassed for a stub.
- **ROOT CAUSE:** The orchestrator depended on the CONCRETE `PageFetcherManager`, not
  an abstraction. A `final` class is (rightly) not subclassable, so a test cannot swap
  it via inheritance, and binding a different concrete requires a shared parent type.
- **SOLUTION:** Introduce a one-method `App\Domain\Scan\Fetch\PageSource` interface
  (`fetch(url): FetchResult`); `PageFetcherManager implements PageSource` (stays
  `final`). `PdpScanner` + `SelectorReverifier` now depend on `PageSource`;
  `App\Providers\ScanServiceProvider` binds `PageSource → PageFetcherManager`. The test
  binds a tiny `implements PageSource` double that returns fixture HTML — no network,
  no real browser. VERIFIED: `tests/Feature/Scan/ScanProductJobTest.php` (6 cases) +
  the full suite at 113 green.
- **PREVENTION:** A domain orchestrator depends on an INTERFACE seam for any
  boundary (fetch / render / external transport), never a `final` concrete. Keep the
  implementation `final`; expose a thin interface and bind it in a provider. This keeps
  the no-network/no-paid-call test contract satisfiable without weakening `final`.
- **RELATED:** [[pdp-scanner]], [[app/Domain/Scan/Fetch/PageSource.php]],
  [[app/Domain/Scan/Fetch/PageFetcherManager.php]], [[app/Providers/ScanServiceProvider.php]],
  [[app/Domain/Scan/PdpScanner.php]]

### TS-PDPSCAN-003 — variant control type detected page-wide, not per-axis (color swatch leaks onto the size axis)
- **Date:** 2026-06-24
- **Category:** pdp-scan
- **Severity:** minor
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `variants`, `swatch`, `dropdown`, `control-type`, `axis`

- **SYMPTOM:** On a PDP with a COLOR swatch + a SIZE `<select>`, both axes were
  labelled `control_type = swatch` — the first matching variation hint (a swatch
  button) won for EVERY axis, so the size dropdown was mis-described.
- **CONTEXT / TRIGGER:** Phase 4, `VariantMapper::detectControlType()` iterated ALL
  `candidateHints['variations']` and returned the first control found, ignoring which
  axis it was deciding for.
- **ROOT CAUSE:** Control type is a per-AXIS property; the detection was page-wide. A
  page legitimately mixes control types across axes (swatch for color, dropdown for
  size), so a single page-level answer is wrong by construction.
- **SOLUTION:** `app/Domain/Scan/Map/VariantMapper.php` now matches the control to the
  specific axis: image-bearing values → image_swatch; a hint whose name/option-name/
  aria-label names the axis → that hint's control; then an axis-name heuristic
  (color/material → swatch, size → dropdown). VERIFIED by
  `tests/Feature/Scan/VariantMappingTest.php::test_color_swatch_and_size_dropdown_are_two_distinct_axes`
  (Color→swatch, Size→dropdown) and the sample scan dump.
- **PREVENTION:** Detect a variant's control type PER AXIS, matching the candidate
  control to the axis it drives; never return one page-wide control type for a
  multi-axis PDP. Flag a single-axis result when the page shows ≥2 distinct controls.
- **RELATED:** [[pdp-scanner]], [[app/Domain/Scan/Map/VariantMapper.php]],
  [[tests/Feature/Scan/VariantMappingTest.php]]

## widget/storefront

### TS-WIDGET-001 — the signed widget API must bind the tenant for the WHOLE request (run $next INSIDE Tenant::run), and resolve site_key BEFORE binding via an integer-only router
- **Date:** 2026-06-25
- **Category:** widget/storefront
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `widget-api`, `tenant-context`, `middleware`, `site-key-routing`, `belongs-to-account`, `global-scope`, `phase-7a`

- **SYMPTOM (design tension, no live bug):** Building the Phase-7a widget-auth middleware,
  two coupled problems: (1) `Site` is `BelongsToAccount` (fail-closed -> returns NOTHING
  when no tenant is bound), but a widget request arrives with NO bound tenant, so
  `Site::where('site_key', …)` cannot find the site to authenticate it — a chicken/egg
  before binding. (2) The tenant must stay bound for the controller, but `Tenant::run()`
  clears in `finally`; a naive `Tenant::run($id, fn () => $site)` returning the site would
  UNBIND before the controller runs, leaving the controller with no tenant (or, worse,
  tempting an ambient `Tenant::set()` that leaks into the next request).
- **CONTEXT / TRIGGER:** Phase 7a, `ResolveWidgetSite` middleware for `/widget/v1/*`. Every
  route resolves the site from the PUBLIC `site_key` + Origin allow-list, then binds the
  tenant for the request lifecycle.
- **ROOT CAUSE:** The fail-closed global scope is all-or-nothing per query — exactly the
  safety we want everywhere EXCEPT the single "which tenant owns this site_key?" routing
  step that by definition runs before a tenant is known. And the request lifecycle is a
  scope: the bind must span $next, not just a resolve closure.
- **SOLUTION:** Mirror the TS-CREDITS-004 webhook-router pattern + bind across $next.
  (1) `app/Http/Widget/SiteRouter.php::accountIdForSiteKey()` does ONE
  `DB::table('sites')->where('site_key', $key)->value('account_id')` — returns ONLY the
  integer account_id (a routing fact; never the widget_secret / row data), keyed by the
  globally-unique public site_key. (2) The middleware then runs the REST of the pipeline —
  re-read the full `Site` through the NORMAL fail-closed global scope, Origin check, HMAC,
  AND `$next($request)` — all INSIDE the `Tenant::run($accountId, fn () => …)` closure, so
  the tenant is bound for the entire request and cleared in `finally` (never ambient,
  never `withoutGlobalScopes()`). VERIFIED by `tests/Feature/Widget/WidgetAuthTest.php`
  (unknown key -> 401, bad Origin -> 403, HMAC paths) + `WidgetIsolationTest.php`
  (back-to-back two-site requests don't leak; `Tenant::check()` is false after the request;
  end user B can't read A's generation/gallery; bootstrap for A never returns B's product).
- **PREVENTION:** For any tenant-less inbound that must authenticate against a tenant model
  (widget request, webhook): resolve ONLY the account_id via a single named, integer-only
  router keyed by a value YOU minted (the public site_key), then `Tenant::run()` and do ALL
  real work — including running the downstream pipeline/controller — INSIDE that closure so
  the bind spans the whole request and self-clears. Never `withoutGlobalScopes()` a tenant
  model to read its data; never `Tenant::set()` from a middleware (it would leak).
- **RELATED:** [[laravel-backend]], [[TS-CREDITS-004]], [[TS-TENANCY-001]],
  [[app/Http/Middleware/ResolveWidgetSite.php]], [[app/Http/Widget/SiteRouter.php]],
  [[tests/Feature/Widget/WidgetAuthTest.php]], [[tests/Feature/Widget/WidgetIsolationTest.php]]

### TS-WIDGET-002 — gate denials at the widget boundary must run as a PRE-DISPATCH check (no generation row / no job / no charge), and a denied generate is NEVER a 500
- **Date:** 2026-06-25
- **Category:** widget/storefront
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `widget-api`, `lead-gate`, `credit-gate`, `usage-gate`, `gate-denial`, `no-charge`, `typed-json`, `phase-7a`

- **SYMPTOM (contract gap to close, not a bug):** Phase-7a spec requires
  `POST /widget/v1/generations` to return a TYPED gate denial (out-of-credits /
  signup-required / rate-limited) WITHOUT a charge and WITHOUT an OpenRouter call. But
  `StartGeneration::handle()` does NOT run the LeadGate/CreditGate — those run
  authoritatively inside `GenerateTryOnJob` on the worker (the row-locked money path). If
  the controller just called `StartGeneration`, an out-of-credits shopper would get a
  `pending` generation row + a dispatched job that only later cancels — not the immediate
  typed denial the widget needs, and a needless row/job per denied click.
- **CONTEXT / TRIGGER:** Phase 7a, the widget `GenerationController::store`. The two gates
  are independent (LeadGate end-user, CreditGate merchant) and must both pass; a denial is
  a typed result, never a 500 (ARCHITECTURE.md two-gates contract).
- **ROOT CAUSE:** The gates live on the worker by design (defense in depth on the money
  path). The HTTP boundary needs a FAST, friendly short-circuit that mirrors them so a
  denial costs nothing and renders the right screen — without duplicating or weakening the
  authoritative worker gates.
- **SOLUTION:** `app/Http/Widget/WidgetGateService::check()` runs the three independent
  gates as a PRE-DISPATCH check at the boundary: `UsageGate` (rate cap -> typed 429),
  `CreditGate` (estimate from the DB-managed AI bag via `AiOperationResolver` +
  `CreditEstimator`, never a literal -> 402 out-of-credits), `LeadGate` (-> 200 + signup
  form). A denial returns a typed `WidgetResponse::gate(...)` and NEVER calls
  `StartGeneration` — so NO generation row, NO job, NO OpenRouter call, NO charge. On pass
  the controller calls `StartGeneration`, and `GenerateTryOnJob` STILL re-runs the gates on
  the worker (defense in depth — this is a short-circuit, not the only guard). Gate
  precedence on a both-block: the credit wall (402) is surfaced over signup (flows.md
  decision — registering can't produce a try-on the merchant can't pay for). VERIFIED by
  `tests/Feature/Widget/WidgetGatesTest.php` (out-of-credits -> 402 + `Http::assertNothingSent`
  + 0 generation rows + 0 charge rows; free-tries-exhausted -> signup_required + no
  OpenRouter; gate re-opens after `POST /leads`; both-block shows the credit wall).
- **PREVENTION:** At an HTTP boundary in front of a worker-gated money path, run the gates
  as a pre-dispatch typed check that short-circuits a denial BEFORE creating any row /
  dispatching any job / calling the paid API — but keep the authoritative gates on the
  worker (defense in depth). A gate denial is a typed JSON result (402/200/429), never a
  500 and never a charge. The estimate for the credit gate comes from the resolver bag ×
  the config/DB markup, never a literal at the call site.
- **RELATED:** [[laravel-backend]], [[saas-credits-billing]], [[TS-CREDITS-005]],
  [[app/Http/Widget/WidgetGateService.php]], [[app/Http/Controllers/Widget/GenerationController.php]],
  [[tests/Feature/Widget/WidgetGatesTest.php]]

### TS-WIDGET-003 — PHP array-union (`+`) keeps the LEFT operand on key collision; default site state silently overrode a test's per-case override
- **Date:** 2026-06-25
- **Category:** widget/storefront
- **Severity:** minor (test-fixture ergonomics; not a code bug)
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `php`, `array-union`, `test-fixtures`, `factory-state`, `phase-7a`

- **SYMPTOM:** Two `WidgetGatesTest` cases that passed `['free_generations_before_signup' => 0]`
  to the site fixture got `free_generations_before_signup = 2` (the default) instead, so
  the lead gate did NOT block and the assertions failed (expected a 200 signup-required,
  got a 201 created).
- **CONTEXT / TRIGGER:** Phase 7a, `WidgetApiTestSupport::makeSiteContext($siteState)`,
  which built the site state as `['allowed_origins' => …, 'free_generations_before_signup' => 2] + $siteState`.
- **ROOT CAUSE:** PHP's array `+` (union) operator keeps the value from the LEFT operand
  for any duplicate key — the opposite of `array_merge`. So the hardcoded default `2` on
  the left WON over the caller's `0` on the right; the per-case override was silently
  dropped.
- **SOLUTION:** Flip the operands so the caller's override wins:
  `$siteState + ['allowed_origins' => …, 'free_generations_before_signup' => 2]` (defaults
  on the RIGHT, only fill keys the caller didn't set). VERIFIED: the gate suite went green
  (signup-required + re-open-after-signup cases pass).
- **PREVENTION:** With PHP `+` on arrays, put DEFAULTS on the right and OVERRIDES on the
  left (`$override + $defaults`) — or use `array_merge($defaults, $override)`. Never put a
  hardcoded default on the left of `+` when the right side is a caller-supplied override.
- **RELATED:** [[laravel-backend]], [[tests/Feature/Widget/WidgetApiTestSupport.php]]

### TS-WIDGET-004 — a `cloneNode()` SPA re-render copies the mount SENTINEL but NOT the shadow root → a dead button shell blocks re-injection
- **Date:** 2026-06-30
- **Category:** widget/storefront
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `widget`, `mount-engine`, `mutation-observer`, `shadow-dom`, `clonenode`, `spa-remount`, `sentinel`, `phase-7b`

- **SYMPTOM:** The Phase-7b widget mount engine passed the "re-injected, still exactly one"
  count after an SPA re-render, but the next step (open the modal by clicking the button)
  threw `TypeError: Cannot read properties of null (reading 'querySelector')` — the
  sentinel-bearing wrapper had a `null` shadowRoot, so `wrapper.shadowRoot.querySelector('.ton-button')`
  blew up. The button was present by attribute but DEAD (no shadow root, no clickable button).
- **CONTEXT / TRIGGER:** Phase 7b verification harness, the SPA-re-render gate, which
  simulates a theme re-render via `main.replaceWith(main.cloneNode(true))`. The button is
  injected into the HOST DOM inside its OWN small shadow root (so host CSS can't reach it),
  and the wrapper carries `data-trayon-mounted` so injection is idempotent.
- **ROOT CAUSE:** `Node.cloneNode(true)` copies element ATTRIBUTES (so the
  `data-trayon-mounted` sentinel survives) but per spec does NOT clone an attached shadow
  root. The cloned wrapper therefore looked "mounted" to a naive
  `document.querySelector('[sentinel]')` guard, so `inject()` short-circuited and never
  rebuilt a working button — leaving a dead shell that the count-only assertion happily
  counted as "one".
- **SOLUTION:** Make the idempotency guard verify a LIVE mount, not just the attribute.
  `resources/widget/src/mount.js::inject()` now iterates every `[data-trayon-mounted]` node,
  keeps the FIRST one whose `node.shadowRoot?.querySelector('.ton-button')` is truthy
  (a live button), and REMOVES every other (dead clones + any duplicate). Only if a live
  one is found does it return early; otherwise it rebuilds + re-places the button. The
  harness also waits for a live button (`shadowRoot && .ton-button`) before clicking, since
  the observer is debounced (150 ms) and re-injects asynchronously. VERIFIED:
  `node tests/widget/verify.mjs` — the SPA-re-render + modal-open gates pass EN + HE; exactly
  one LIVE button after `cloneNode` re-render.
- **PREVENTION:** A self-healing mount whose marker lives on a shadow HOST must guard on a
  LIVE check (the shadow root + the expected child exists), never on the sentinel attribute
  alone — `cloneNode`/`innerHTML` re-renders duplicate attributes but drop shadow roots. A
  "count === 1" assertion is insufficient; assert the surviving node is functional (clickable).
- **RELATED:** [[widget-embed]], [[resources/widget/src/mount.js]], [[resources/widget/src/button.js]],
  [[tests/widget/verify.mjs]], [[TS-WIDGET-001]]

## infra/railway/horizon

### TS-INFRA-001 — `laravel/horizon` install fails on Windows: missing ext-pcntl / ext-posix
- **Date:** 2026-06-24
- **Category:** infra/railway/horizon
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `horizon`, `composer`, `windows`, `herd`, `pcntl`, `posix`, `platform-req`

- **SYMPTOM:** `composer require laravel/horizon` aborts with
  `laravel/horizon[...] require ext-pcntl * -> it is missing from your system`
  (and then the same for `ext-posix`). Installation is reverted, no package added.
- **CONTEXT / TRIGGER:** Phase 1 scaffold, installing Horizon locally on Windows
  with Herd PHP 8.4 (`C:\Users\user\.config\herd\bin\php84\php.exe`). pcntl/posix
  are POSIX-only extensions and are not available on Windows PHP.
- **ROOT CAUSE:** Horizon legitimately requires pcntl (signal handling, job
  timeouts) and posix (supervisor process control). These run only in the Linux
  production image; the Windows dev box cannot load them, so Composer's platform
  check refuses the install.
- **SOLUTION:** Install with the platform reqs ignored locally; the production
  Dockerfile installs both extensions so they are present where Horizon runs:
  `composer require "filament/filament:^3.2" "laravel/horizon:^5.0" \
    --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`.
  Dockerfile line: `install-php-extensions intl zip pdo_pgsql gd bcmath pcntl posix sockets opcache redis`.
  Verified: `php artisan about` shows Horizon installed; `config('horizon.defaults')`
  resolves the 5 supervisors; production image gets pcntl+posix at build.
- **PREVENTION:** On Windows/Herd, always add
  `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` for any
  Horizon/queue work, and make sure the Dockerfile's `install-php-extensions`
  line includes BOTH pcntl and posix (the reference image listed pcntl only).
- **RELATED:** [[railway-infra]], [[Dockerfile]], [[config/horizon.php]]

### TS-INFRA-002 — global Railway `healthcheckPath` fails every worker/scheduler deploy
- **Date:** 2026-06-24
- **Category:** infra/railway/horizon
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `railway`, `healthcheck`, `worker`, `scheduler`, `frankenphp`, `deploy`

- **SYMPTOM:** Worker (`horizon`) and scheduler (`schedule:work`) deploys fail with
  "1/1 replicas never became healthy"; web sometimes false-negatives on cold boot.
- **CONTEXT / TRIGGER:** One shared `railway.toml` across all three services. A
  global `healthcheckPath = "/up"` is applied to services that have NO listening
  HTTP port (worker/scheduler), so the check can never pass.
- **ROOT CAUSE:** Only `web` serves HTTP. `worker` and `scheduler` have no open
  port, so an HTTP healthcheck on them always fails and Railway restart-loops the
  deploy. FrankPHP cold-boot (config:cache + opcache warm) also exceeds the edge
  timeout, producing false negatives for web.
- **SOLUTION:** Set NO `healthcheckPath` in the shared `railway.toml`. Verify web
  liveness with a manual `GET /up` after deploy. To re-gate web only, set its
  healthcheck per-service in Railway (Settings -> Deploy -> Healthcheck Path = /up,
  timeout >= 300). Documented inline in `railway.toml`. Inherited from the
  pattern-oracle reference deploy, which hit and fixed this exact issue.
- **PREVENTION:** Never put a global `healthcheckPath` in a `railway.toml` shared
  by non-HTTP services. Worker/scheduler get no HTTP healthcheck, ever.
- **RELATED:** [[railway-infra]], [[railway.toml]], [[Procfile]]

### TS-INFRA-003 — `const`-at-top in config/route files breaks `config:cache` ("Constant already defined")
- **Date:** 2026-06-24
- **Category:** infra/railway/horizon
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `config-cache`, `const-at-top`, `route-cache`, `idempotency`, `deploy`

- **SYMPTOM:** `php artisan config:cache` aborts with
  `ErrorException: Constant MEDIA_DISK already defined at config/filesystems.php:7`
  (and `Constant ROUTE_HEALTH already defined at routes/web.php` during route:cache).
  Because docker-web.sh / predeploy run config:cache after env is present, this
  would FAIL every production boot.
- **CONTEXT / TRIGGER:** Phase 1. Applying the CONST-at-top convention literally to
  config files (`const MEDIA_DISK = 's3';`) and route files. `config:cache` and
  `route:cache` evaluate these files within a single PHP process where the file may
  be required more than once (e.g. config caching also triggers route file loading),
  so a bare `const` at file scope is re-declared and throws.
- **ROOT CAUSE:** A file-scope `const` is a hard, one-time declaration. Laravel's
  cache compilers re-include config/route files in one process; the second
  declaration of the same constant is fatal. The convention is sound for CLASSES
  (a class const is namespaced + declared once) but not for plain PHP config/route
  files, which are re-evaluated.
- **SOLUTION:** In config/ and routes/ files ONLY, guard each constant so it is
  idempotent: `defined('MEDIA_DISK') || define('MEDIA_DISK', 's3');` (instead of
  `const MEDIA_DISK = 's3';`). Applied across config/horizon.php, config/queue.php,
  config/trayon.php, config/filesystems.php and routes/web.php. Verified:
  `config:cache && route:cache && view:cache` all exit 0 and the cached app serves
  /health + both panel logins at HTTP 200.
- **PREVENTION:** Keep CONST-at-top as `const` inside CLASSES; in config/ and
  routes/ files use the `defined() || define()` guarded form so the caching steps
  stay idempotent. Gate check: grep config/ + routes/ for `^const ` and convert.
- **RELATED:** [[railway-infra]], [[config/horizon.php]], [[config/trayon.php]],
  [[config/filesystems.php]], [[config/queue.php]], [[routes/web.php]], [[scripts/docker-web.sh]]

### TS-INFRA-004 — embedded Shopify install took the `park→claim` branch when `Auth::check()`, so the claim redirect to `/merchant/login` broke the app OUT of the Shopify Admin iframe
- **Date:** 2026-07-19
- **Category:** infra/railway/horizon
- **Severity:** blocker
- **Status:** open — real production install VERIFICATION pending deploy (see below)
- **Recurrence:** 1
- **Tags:** `shopify`, `embedded`, `oauth`, `iframe`, `install`, `session-bridge`, `samesite-none`, `partitioned-cookie`, `top-level-redirect`, `auto-provision`

- **SYMPTOM:** Installing the embedded Shopify app for a NEW shop while a merchant
  was already authenticated (`Auth::check()` true) navigated the browser to
  `/merchant/login` and the app rendered as a full-page site OUTSIDE the Shopify
  Admin iframe, instead of staying embedded inside `Shopify Admin`. The merchant
  was ejected from Admin at the exact moment the install should have completed.
- **CONTEXT / TRIGGER:** Shopify embedded install/OAuth flow. The OAuth callback
  (`app/Http/Shopify/Controllers/OAuthController.php`) could enter a `park→claim`
  branch when `Auth::check()`, handing off to `InstallClaimController`, which
  redirected to `/merchant/login`. Any top-level `login`/site redirect from inside
  the Admin iframe breaks the app out of the embedded surface. Trigger: connect a
  new shop while signed in to the merchant panel.
- **ROOT CAUSE:** The install callback treated "already authenticated" as a
  park-then-claim handoff. `InstallClaimController` then issued a top-level redirect
  to `/merchant/login` — a full-page navigation — which is exactly what an embedded
  app must NEVER do: inside `Shopify Admin` the app lives in an iframe, and a
  login/site redirect escapes it. The authenticated case had no direct
  "attach this shop to the current account and return to Admin" path, so it fell
  through to a flow designed for the guest/hand-off case.
- **SOLUTION:** Route the callback by install case, and NEVER top-level-redirect out
  of the iframe:
  1. **New shop + authenticated** → attach the shop directly to the logged-in
     account via `ShopifyInstaller::installFreshShop`
     (`app/Domain/Shopify/Auth/ShopifyInstaller.php`) and ALWAYS return to
     `https://{shop}/admin/apps/{client_id}` (stay in Admin). No `park→claim`, no
     `/merchant/login`.
  2. **Guest install** → continues the auto-provision path.
  3. **Reinstall** → continues the reconnect path.
  4. **Session bridge** (`resources/views/shopify/embedded/app.blade.php`): the
     `POST /shopify/app/session` fetch uses `credentials: 'include'`, HALTS if that
     request fails (no silent fall-through), and the dashboard link uses
     `target="_self"` with NO external fallback — so nothing navigates the top frame
     out of Admin.
  5. **Cookie contract locked** for the embedded (third-party-context) session:
     `SameSite=None`, `Secure`, `Partitioned` — pinned in `.env.example`,
     `config/session.php`, and enforced by the predeploy guard so a
     non-Partitioned/non-None cookie can't ship (Chrome CHIPS drops the session
     cookie in the iframe otherwise).
  Local verification evidence (this machine, pre-deploy): full suite **237 tests /
  1077 assertions PASS** including all `tests/Feature/Shopify`, `tests/Feature/Infra`
  and `ShopifyStorePageTest`; the focused Shopify/Infra subset **37/181 PASS**;
  **Pint** clean.
  > **NOT YET VERIFIED IN PRODUCTION.** No live Shopify install was performed: the
  > code is not deployed and there is no connected Shopify session. A real
  > production install (install a new shop from Shopify Admin, confirm the app stays
  > embedded and the session cookie survives the iframe) remains a **post-deploy
  > release step**. Entry stays `open` until that live install is confirmed.
- **PREVENTION:**
  - An embedded Shopify app must NEVER issue a top-level redirect to a
    `login`/site route from inside the Admin iframe. The OAuth callback returns to
    `https://{shop}/admin/apps/{client_id}` for every install case (new / guest /
    reinstall).
  - Branch the install callback by case explicitly — new-shop-authenticated attaches
    via `installFreshShop`; never let the authenticated case fall through to a
    guest `park→claim` hand-off that redirects to `/merchant/login`.
  - The embedded session bridge fetch uses `credentials: 'include'` and HARD-FAILS
    (does not silently continue) when `POST /shopify/app/session` fails; in-Admin
    navigation is `target="_self"` with no external fallback.
  - The embedded session cookie contract (`SameSite=None; Secure; Partitioned`) is
    pinned in `.env.example` + `config/session.php` and gated by the predeploy check
    — treat it as a release blocker, not a default.
  - Release checklist: after deploy, perform ONE real install from Shopify Admin and
    confirm the app stays inside the iframe before marking this entry `resolved`.
- **RELATED:** [[railway-infra]], [[app/Http/Shopify/Controllers/OAuthController.php]],
  [[app/Domain/Shopify/Auth/ShopifyInstaller.php]],
  [[app/Http/Shopify/Controllers/InstallClaimController.php]],
  [[resources/views/shopify/embedded/app.blade.php]], [[config/session.php]],
  [[.env.example]], [[tests/Feature/Shopify/ShopifyInstallNewShopTest.php]],
  [[tests/Feature/Shopify/ShopifyAutoProvisionTest.php]],
  [[tests/Feature/Shopify/ShopifyEmbeddedEntryTest.php]],
  [[tests/Feature/Infra/PredeployCheckTest.php]]

### TS-INFRA-005 — embedded `install_new_shop` failed `invalid_state` on the FIRST attempt: the state-nonce session cookie (`SameSite=None; Secure; Partitioned`) was set on the install 302 and dropped before the OAuth round-trip; a refresh then worked
- **Date:** 2026-07-19
- **Category:** infra/railway/horizon
- **Severity:** major
- **Status:** resolved (code) — live re-test on a real first install pending
- **Recurrence:** 1
- **Tags:** `shopify`, `embedded`, `oauth`, `install`, `invalid_state`, `samesite-none`, `partitioned-cookie`, `chips`, `session`, `breakout`, `top-level`, `works-on-refresh`

- **SYMPTOM:** After the breakout page escaped top-level to `/shopify/install` and the merchant
  approved Shopify's grant screen, the callback returned a broken page —
  `Shopify install failed: The OAuth state nonce is missing, forged, expired, or already used.
  (code: invalid_state)` — OUTSIDE the admin. Refreshing / retrying the install then SUCCEEDED
  (the shop + owner auto-provisioned and the app connected).
- **CONTEXT/TRIGGER:** `install_new_shop` from the Shopify admin (embedded app → breakout →
  `/shopify/install` → Shopify authorize → `/shopify/oauth/callback`). First install in a browser
  with no prior `go.vsio.app` top-level session cookie.
- **ROOT CAUSE:** `OAuthController::install()` issued the single-use, browser-bound state nonce
  into the SESSION (`ShopifyOAuthState::issue` → `$session->put`) and returned a **302** to
  Shopify's authorize URL. The session cookie is globally `SameSite=None; Secure; Partitioned`
  (the CHIPS contract for the embedded panel, TS-INFRA-004). A freshly-created session cookie SET
  ON A REDIRECT response, in a top-level context reached via `window.open(_top)` from the admin
  iframe, can be dropped by the browser before the cross-site OAuth round-trip — so the callback
  ran under a DIFFERENT (empty) session, `$session->pull(nonce)` found nothing, and the state
  check failed closed. On the second attempt the cookie already existed → it worked (the classic
  "works on refresh" signature).
- **SOLUTION:** `install()` now returns a **200 hand-off page**
  (`resources/views/shopify/oauth/redirect.blade.php`) that commits the session cookie first, then
  navigates the top-level frame to Shopify's authorize URL (`window.location.replace` + a
  `<meta http-equiv=refresh>` and manual-link fallback). The nonce STILL lives in the session —
  the browser-binding CSRF wall (and the `test_a_state_stolen_from_another_browser…` guard) is
  UNCHANGED; only the cookie-commit timing is fixed. This is Shopify's canonical "set the cookie
  top-level before redirecting" pattern. `connect_existing_site` (`start()`) is untouched — it
  begins from the authenticated panel where the session cookie is already committed.
  Local verification: full Shopify + install/state suites PASS (incl. the browser-binding +
  phished-grant security tests); Pint clean.
  > NOT YET RE-VERIFIED ON A LIVE INSTALL. A real first-time install from Shopify Admin (fresh
  > browser, confirm no `invalid_state` on the first attempt) is the release check.
- **PREVENTION:**
  - When a session cookie is `SameSite=None; Partitioned`, never rely on it being committed by a
    302 that starts a cross-site OAuth round-trip. Set it on a 200 the browser fully loads, THEN
    redirect.
  - Keep the OAuth state session-bound (browser-binding is the CSRF/login-CSRF wall); fix cookie
    DELIVERY, not the security model.
- **RELATED:** [[TS-INFRA-004]], [[app/Http/Shopify/Controllers/OAuthController.php]],
  [[resources/views/shopify/oauth/redirect.blade.php]],
  [[app/Domain/Shopify/Auth/ShopifyOAuthState.php]], [[config/session.php]]

## filament/admin

### TS-FILAMENT-001 — platform cross-account read: the EAGER-LOAD relation re-applies the global scope (variants return 0); + Livewire route-param vs typed property collision
- **Date:** 2026-06-30
- **Category:** filament/admin
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `withoutGlobalScope`, `eager-load`, `belongs-to-account`, `platform-seam`, `livewire`, `route-param`, `phase-8`, `scan-review`

- **SYMPTOM:** Two blockers while bringing the scan review/confirm + verify-setup into the
  PLATFORM Filament panel (super-admin, no bound tenant):
  1. `PlatformProductQuery::findWithVariants()` removed `AccountScope` from the Product
     query, but `$product->variants` came back EMPTY — `findWithVariants` returned the
     product yet `assertCount(1, $loaded->variants)` saw 0.
  2. The `ManageSiteProducts` resource page declared `public Site $record` and a route
     `/{record}/products`; the Livewire test (`['record' => $site->id]`) fataled with
     `Cannot assign int to property …::$record of type App\Models\Site` (a ViewException on
     mount) — every page test errored before any assertion.
- **CONTEXT / TRIGGER:** Phase 8, platform `PlatformProductQuery` seam + the
  `ManageSiteProducts` custom resource Page. The page reads cross-account through the
  audited `withoutGlobalScope(AccountScope::class)` seam (guarded by `PlatformGuard`).
- **ROOT CAUSE:**
  1. `withoutGlobalScope()` only affects the builder it is called on. An eager-load
     (`with('variants')`) builds a FRESH `ProductVariant` query, and `ProductVariant` IS
     `BelongsToAccount`, so its `AccountScope` re-applies — and with NO bound tenant it
     fails closed to nothing. Removing the scope on the parent does not propagate to the
     child relation.
  2. Livewire hydrates public properties from the mount parameters BY NAME before `mount()`
     runs. A `public Site $record` typed property collides with the `record` route/mount
     param (an int id), so Livewire tries to assign the int to the `Site`-typed property and
     type-errors.
- **SOLUTION:**
  1. Remove the scope on the relation too, inside the same audited seam:
     `with(['variants' => fn ($r) => $r->withoutGlobalScope(AccountScope::class)])`
     (`app/Domain/Platform/PlatformProductQuery.php::variantsWithoutScope()`). The bypass is
     still inside the one guarded, allow-listed seam — not a new product-code bypass.
  2. Rename the page's public property off the route param: keep `mount(int|string $record)`
     (receives the id) but store it as `public Site $site = SiteResource::getEloquentQuery()
     ->findOrFail($record)` (`ManageSiteProducts`). The property name must differ from the
     mount/route param name so Livewire does not pre-assign the raw id to it.
  VERIFIED: `tests/Feature/Tenancy/PlatformProductQueryTest.php` (variants load cross-account)
  + `tests/Feature/Filament/Platform/PlatformSiteProductsTest.php` (page renders, confirm
  DRAFT→CONFIRMED, setup Ready, verify checklist) — full suite `php artisan test` → 493
  passed (1592 assertions). Modal-content assertions use `mountTableAction` (opens + renders
  the modal), not `callTableAction` (which runs a no-submit action and closes it).
- **PREVENTION:** When an audited platform seam removes `AccountScope` from a parent for a
  cross-account read, it MUST also remove it from every eager-loaded `BelongsToAccount`
  child relation (a closure in `with()`), all inside the same guarded seam. A custom Filament
  resource `Page` with a `/{record}/…` route must NOT also declare a `public <Model> $record`
  property — name the resolved-model property something else (`$site`) so Livewire's
  by-name mount hydration doesn't clash with the route param's raw value.
- **RELATED:** [[laravel-backend]], [[admin-design-system]], [[TS-TENANCY-002]],
  [[app/Domain/Platform/PlatformProductQuery.php]],
  [[app/Filament/Platform/Resources/SiteResource/Pages/ManageSiteProducts.php]],
  [[tests/Feature/Tenancy/PlatformProductQueryTest.php]],
  [[tests/Feature/Filament/Platform/PlatformSiteProductsTest.php]]

## i18n/RTL

_No recorded issues yet._

## media/storage

### TS-MEDIA-001 — store the result image BEFORE charging, and persist the opaque disk PATH (never a URL); sign on demand
- **Date:** 2026-06-25
- **Category:** media/storage
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `s3`, `r2`, `signed-url`, `temporary-url`, `private-visibility`, `tenant-scoped-path`, `storage-fake`, `phase-6`

- **SYMPTOM (design risk, no live bug):** The money-path law is "no charge without a
  STORED result." Two ways to get it wrong: (a) charge before the result bytes are
  durably on the media disk (a credit debited for an image the shopper can't see), and
  (b) persist a public/signed URL on `generations.result_image_path` (a leaked column =
  free hot-link egress; a stale signed URL on the row). Also: tests must exercise signed
  URLs without a real S3.
- **CONTEXT / TRIGGER:** Phase 6, `GenerateTryOnJob` finalize + `MediaStorage`. The result
  comes back as BYTES from `TryOnGenerationCaller`; backend stores then charges.
- **ROOT CAUSE:** Storing a URL couples the row to a credential/expiry; storing public
  objects defeats egress control; charging before the store breaks the "result first" law.
- **SOLUTION:** `app/Domain/Media/MediaStorage.php` writes bytes PRIVATE
  (`['visibility' => 'private']`) under a tenant-led path
  `accounts/{account}/sites/{site}/generations/{gen}/{kind}-{rand}.{ext}` and returns the
  opaque PATH (a `StoredMedia` ref), which is what `generations.result_image_path` stores
  — NEVER a URL. The browser gets a SHORT-lived `temporaryUrl` minted on demand
  (`signedUrl()`, TTL from `config('trayon.media.signed_ttl')`, never a literal). The job
  stores the result in `storeResult()` and ONLY THEN opens the charge transaction (result
  before charge). TESTING: `Storage::fake('s3')` supports `temporaryUrl` (returns an
  `http://...?expiration=...` URL that `ImagePayload::fromUrl()` accepts), so the whole
  pipeline — store, sign, send-to-OpenRouter — runs with ZERO real S3. VERIFIED by
  `tests/Feature/Generation/MediaStorageTest.php` (private, tenant-scoped path, signed-with-
  expiration, null-path -> null) and `GenerateTryOnJobTest::test_signed_result_url_is_issued_and_not_a_public_path`.
- **PREVENTION:** Persist the opaque disk path, never a URL; write media PRIVATE and sign
  on demand with a config TTL; lead every media path with `account_id` so an object is
  never cross-tenant ambiguous and the Phase-9 retention purge can delete a whole
  generation prefix. Store the result BEFORE the charge transaction. In tests, fake the
  `s3` disk — its `temporaryUrl` works, so no real bucket is ever touched.
- **RELATED:** [[laravel-backend]], [[railway-infra]], [[app/Domain/Media/MediaStorage.php]],
  [[app/Domain/Media/StoredMedia.php]], [[app/Domain/Generation/GenerateTryOnJob.php]],
  [[tests/Feature/Generation/MediaStorageTest.php]]

### TS-MEDIA-002 — a write ATTEMPTED is not a write VERIFIED: every disk is `throw => false`, so a failed `put()` returns FALSE and `MediaStorage` handed back a path pointing at NOTHING
- **Date:** 2026-07-13
- **Category:** media/storage
- **Severity:** blocker
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `s3`, `r2`, `volume`, `filesystem-throw-false`, `silent-write-failure`, `shopify-media`, `money-path`, `phase-5`

- **SYMPTOM:** A failed media write was INVISIBLE. `MediaStorage` ignored the boolean that
  `disk()->put()` returns and always minted a `StoredMedia` with a path — so every caller
  believed bytes had landed when they had not. Two blast radii, both real: (a) the Shopify
  original-gallery snapshot was stamped `captured` with nothing behind it, which then
  LICENSED deleting the merchant's original from a LIVE storefront — the image was gone
  from Shopify *and* from us, and Undo threw `notRestorable` forever; (b) on the money
  rails, a "stored" result that does not exist would still be charged (store-before-charge
  is only a law if the store is real).
- **CONTEXT / TRIGGER:** Any media write on any rail (try-on, banner, product-image,
  storyboard, playground, Shopify snapshot). Triggered by a real S3/R2/volume write
  failure — a full disk, a bad credential, a bucket policy, an outage.
- **ROOT CAUSE:** EVERY disk in `config/filesystems.php` sets `'throw' => false`
  (:45, :54, :67, :84). Laravel's `FilesystemAdapter` therefore CATCHES `UnableToWriteFile`
  and returns `false` instead of raising. Ten `put()` call sites ignored that return value.
  A second, subtler shape: a disk that ACCEPTS the write and cannot serve it back (a lying
  or eventually-consistent backend) — the boolean alone does not catch that one.
- **SOLUTION:** ONE write gateway: `MediaStorage::write()`
  (`app/Domain/Media/MediaStorage.php`) — every `store*()` method routes through it, and it
  (1) throws `MediaWriteException::rejected()` when `put()` returns `false`, and (2) READS
  THE OBJECT BACK (`byteSize() >= 1`) and throws `MediaWriteException::unverified()` when it
  is missing or empty. `StoredMedia::byteSize` is now the VERIFIED size, not `strlen($bytes)`.
  Downstream: the Shopify snapshotter re-verifies the whole capture before it may transition
  to `captured` (`assertVerified()`), and the pusher re-verifies before every destructive
  push (`assertSnapshotRestorable()` / `assertMediaRestorable()`) — a path STRING is not
  bytes. The money rails already wrapped the store in a try/catch, so a typed write failure
  now releases the hold and writes NO charge row (they stay green).
- **PREVENTION:** Never trust `Storage::put()`'s side effect while `throw => false` is set —
  check the boolean AND read the object back before anything irreversible (a delete, a
  charge, a state transition) hangs on it. Pin BOTH checks separately: a mock disk with
  `put -> false, exists -> true` proves the boolean check, and `put -> true, exists -> false`
  proves the readback. Mutation-verified in
  `tests/Feature/Shopify/ShopifyMediaSafetyTest.php` +
  `ProductImageMoneyPathTest::test_a_silently_refused_disk_write_never_debits_the_merchant`.
- **RELATED:** [[TS-MEDIA-001]], [[laravel-backend]], [[app/Domain/Media/MediaStorage.php]],
  [[app/Domain/Media/MediaWriteException.php]],
  [[app/Domain/Shopify/Media/ShopifyMediaSnapshotter.php]],
  [[app/Domain/Shopify/Media/ShopifyMediaPusher.php]]

### TS-MEDIA-003 — an UNDO that restores the order but not the bytes is an undo that lies: three ways the Shopify destructive rail could lose a merchant's original for good
- **Date:** 2026-07-13
- **Category:** media/storage
- **Severity:** blocker
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `shopify`, `product-media`, `destructive-push`, `undo`, `idempotency`, `pagination`, `state-machine`, `phase-5`

- **SYMPTOM:** Four independent ways a merchant's live product could end up with a deleted
  original nobody could restore, or with junk in their gallery:
  (1) a crash mid-restore re-uploaded the SAME original again (the snapshot's
  `restored_media_id` was saved only AFTER the whole loop), so the live gallery grew a
  DUPLICATE original on every retry;
  (2) the snapshot captured the WHOLE live gallery — including images WE had already
  pushed with an APPEND (an append is non-destructive and takes no snapshot) — so a second
  Undo re-uploaded OUR OWN AI image into the storefront as if it were an "original", where
  it stayed forever;
  (3) `media(first: 50)` had no `pageInfo`: a >50-media product was silently TRUNCATED and
  still stamped `captured`, so Undo could never restore the rest's order;
  (4) `approved -> rejected` was legal with no push guard, so Undo mutated the store and
  THEN threw on `pushTransitionTo()` — the asset was stranded at `pushed` with a dead media
  id, no restore event, and an Undo button that threw forever.
- **CONTEXT / TRIGGER:** Phase 5 (`app/Domain/Shopify/Media/*`). Any REPLACE / position-N
  push, an append followed by a destructive push, a >50-image product, a poll-budget
  exhaustion or throttle park mid-restore, a merchant who rejects an image they pushed.
- **ROOT CAUSE:** Four different instances of the same family — a step whose durable record
  lagged the irreversible act it was supposed to justify. Notably (1) is the SAME window as
  the push rail's `createMedia()` (already closed there): the provider minted the resource
  and our DB write did not land.
- **SOLUTION:** (1) `ShopifyMediaSnapshot::rememberRestoredMediaId()` is called between
  `createMedia()` and `awaitReady()` — the id is persisted in the SAME BREATH as the call
  that mints it, per item, before anything that can throw. A resumed restore also re-awaits a
  not-yet-READY original, so the "delete ours only after every original is READY" law holds
  on the resume path too. (2) `ShopifyMediaSnapshotter::capture()` EXCLUDES every media id
  carried by this product's `product_assets.shopify_media_id` and rebases the positions over
  what remains — the snapshot is the merchant's TRUE pre-Tray-On state. (3)
  `ShopifyMediaQueries::productMedia()` now requests `pageInfo { hasNextPage endCursor }` and
  `ShopifyMediaClient::gallery()` WALKS it, throwing `ShopifyMediaException::galleryUnread()`
  when the gallery cannot be read to its end (fail closed; bounded by
  `shopify.media.max_pages`). (4) `ProductAsset::reviewTransitionTo()` refuses to REJECT an
  asset that `isInStore()`, and `pushTransitionTo()`'s approval gate now guards only the way
  INTO the store — leaving it (`-> not_pushed`, i.e. undo) needs no approval, so undo can
  always close the loop.
- **PREVENTION:** Persist the remote id in the same breath as the call that mints it (never
  after the loop). A snapshot must represent the state BEFORE we touched anything — never
  re-capture our own writes. A partial read of a paginated resource is not a shorter
  resource: fail closed. Two machines that can move independently (review + push) must be
  made to agree, or one can brick the other. Every one of these guards is pinned by a
  mutation-verified test in `tests/Feature/Shopify/ShopifyMediaSafetyTest.php`.
- **RELATED:** [[TS-MEDIA-002]], [[laravel-backend]],
  [[app/Domain/Shopify/Media/ShopifyMediaPusher.php]],
  [[app/Domain/Shopify/Media/ShopifyMediaSnapshotter.php]],
  [[app/Domain/Shopify/Media/ShopifyMediaClient.php]], [[app/Models/ProductAsset.php]]

## privacy/retention

### TS-PRIVACY-001 — Phase-5a `end_users` shipped with NO consent columns; marketing-consent-default-OFF had nowhere to store
- **Date:** 2026-06-25
- **Category:** privacy/retention
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `gdpr`, `consent`, `marketing-consent`, `photo-consent`, `default-off`, `end-users`, `phase-5b`

- **SYMPTOM:** The Phase-5b consent guardrail (marketing consent DEFAULTS OFF; separate
  from the use-my-photo consent) had no storage: the Phase-5a `end_users` table + `EndUser`
  model carried NO consent fields at all (`marketing_consent`, `photo_consent_at` absent).
  A launch-blocker contract (a pre-checked / default-on marketing box is a GDPR violation)
  could not be enforced because the column didn't exist.
- **CONTEXT / TRIGGER:** Phase 5b privacy guardrail. `end_users` (migration
  `2026_06_25_110001`) modelled the lead funnel + free-tries counter but predated the
  consent contract being implemented.
- **ROOT CAUSE:** The lead table was shaped before the two-consents contract (ARCHITECTURE
  "lead gate" + the billing agent's §7.2) was implemented, so the default-OFF guarantee had
  no column to live on. A default that isn't a STORAGE-level default is not a guarantee.
- **SOLUTION:** Forward-only migration
  `database/migrations/2026_06_25_120002_add_consent_to_end_users_table.php` adds THREE
  separate, independent fields: `photo_consent_at` (timestamp, null until the shopper agrees
  — the provable basis to process the photo), `marketing_consent` (boolean, **`->default(false)`**
  — the storage-level OFF guarantee, never pre-checked, never implied by photo consent), and
  `marketing_consent_at` (when opt-in was given). `EndUser` casts `marketing_consent` to
  bool with `$attributes['marketing_consent' => false]`; `LeadCapture::register()` flips it
  true ONLY on an explicit truthy `marketing_consent` field (`filter_var(... ?? false ...)`).
  VERIFIED by `tests/Feature/Leads/MarketingConsentDefaultTest.php` (default off; signup
  without opt-in keeps it off; explicit opt-in sets it + the timestamp; photo consent is
  independent) — and ANTI-THEATER proven: defaulting capture to marketing-on reds the
  "signup keeps marketing off" test, restored → green.
- **PREVENTION:** A consent / privacy default that the contract says is OFF must be a
  COLUMN default (`->default(false)`) + a model attribute default, not just app logic — so
  it holds even on a direct insert. The two consents (use-my-photo vs marketing) are always
  SEPARATE fields; capture sets marketing only from an explicit opt-in. When a later phase
  implements a guardrail an earlier phase's table must store, re-check the columns exist.
- **RELATED:** [[saas-credits-billing]], [[widget-embed]], [[app/Models/EndUser.php]],
  [[app/Domain/Leads/LeadCapture.php]],
  [[database/migrations/2026_06_25_120002_add_consent_to_end_users_table.php]],
  [[tests/Feature/Leads/MarketingConsentDefaultTest.php]], [[TS-BUILD-003]]

## build/deploy

### TS-BUILD-009 — TWO agents running the suite at once: `Storage::fake('s3')` WIPES the shared testing-disk directory, so the other process's just-written file vanishes mid-test
- **Date:** 2026-07-13
- **Category:** build/deploy
- **Severity:** minor (environmental; no product bug — but it looks exactly like a money-path flake)
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `storage-fake`, `parallel-agents`, `flaky`, `testing-disks`, `media`, `false-positive`

- **SYMPTOM:** A single, non-reproducible failure in the product-image money path:
  `Storage::disk('s3')->assertExists($asset->image_path)` failed while EVERY other assertion in
  the same test (status succeeded, charge = 97_500, ledger row, balance, released hold) passed.
  The next four consecutive runs — and the full suite — were green. A "sometimes the stored image
  isn't there" failure on a money path is alarming and easy to chase for an hour.
- **CONTEXT / TRIGGER:** Two agents (this repo runs a multi-agent team) executed
  `vendor/bin/phpunit` at the same time on the same working copy.
- **ROOT CAUSE:** `Storage::fake($disk)` is NOT process-isolated: it writes to the SHARED path
  `storage/framework/testing/disks/{disk}` and **cleans that directory** every time it is called.
  The other process's `Storage::fake('s3')` (in its own `setUp`) therefore deleted the files my
  test had just written, between the write and the assertion. The DB assertions survived because
  each process has its own sqlite/`RefreshDatabase` transaction — only the filesystem is shared.
- **SOLUTION:** Nothing to fix in product code (verified: the pipeline stores the bytes BEFORE the
  charge, and the path/charge assertions were all correct). Do not run two suites against one
  working copy at the same time; re-run to confirm before treating a lone `assertExists` failure
  on a faked disk as a real defect. Verified: 4 consecutive `--filter ProductImage` runs green
  (30 tests) and the full suite green (1091 tests) once the other agent's run finished.
- **PREVENTION:** When a media/disk assertion fails ALONE while the money/DB assertions in the same
  test pass, suspect a concurrent `Storage::fake()` wipe before suspecting the pipeline. In a
  multi-agent session, serialise full-suite runs (or give each run its own working copy); if
  parallel runs ever become routine, point the testing disk root at a per-process temp dir.
- **RELATED:** [[laravel-backend]], [[TS-MEDIA-001]], [[TS-BUILD-008]],
  [[tests/Feature/ProductImages/ProductImageMoneyPathTest.php]]

### TS-BUILD-008 — `Http::fake()` runs EVERY matching stub closure (not just the winner), so a counter inside a stub over-counts; and a SEED MIGRATION breaks an unrelated `assertDatabaseMissing`
- **Date:** 2026-07-13
- **Category:** build/deploy
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `http-fake`, `stub-callbacks`, `side-effects`, `call-counting`, `seed-migration`, `assert-database-missing`, `shopify-phase-4`

- **SYMPTOM:** Two test-harness traps while proving the async product-image money path.
  (1) A test asserting "the provider is never re-SUBMITTED" counted submits with a `$this->count++`
  inside the submit stub closure. It reported **3 submits for 1 real submit** — the anti-double-submit
  assertion was measuring nonsense, and a genuine double-submit bug would have been invisible.
  (2) After adding a migration that SEEDS the AI control plane (the two product-image operations
  + their model catalog), an unrelated platform test went RED:
  `AiModelCostHintTest::test_a_byteplus_model_cannot_be_saved_without_a_per_image_price`
  → `assertDatabaseMissing('ai_models', ['model_id' => 'seedream-5-0-260128'])` now found rows.
- **CONTEXT / TRIGGER:** (1) `Http::fake([...])` with several URL patterns whose globs overlap
  (`/*/requests/*/status`, `/*/requests/*`, `/*`). (2) Any `RefreshDatabase` test — migrations
  run, so a seed migration's rows exist in EVERY test, not just the ones that call `$this->seed()`.
- **ROOT CAUSE:**
  (1) `Factory::send()` does `$this->stubCallbacks->map(fn ($cb) => $cb($request, $options))->filter()->first()`
  — `map()` is EAGER, so **every** stub whose pattern matches is invoked; only the first non-null
  RESPONSE is used. A broad catch-all pattern therefore fires its closure on every request,
  including the status/result polls, even though its response is discarded. Side effects in a
  stub closure are not a call count.
  (2) A seed migration is global test state. `assertDatabaseMissing` on a catalog row is a
  test that "nobody has seeded this id" — a new seed migration silently invalidates it.
- **SOLUTION:**
  (1) Keep stub closures SIDE-EFFECT-FREE for counting and read call counts from `Http::recorded()`,
  which records the requests that actually went out:
  `tests/Feature/ProductImages/ProductImageTestSupport.php` —
  `falSubmitCount()` = recorded POSTs whose url has no `/requests/`; `falPollCount()` = recorded
  urls ending in `/status`. (Sequencing state — "IN_QUEUE then COMPLETED" — may stay in the
  closure; it is idempotent per matching request. Combine with TS-BUILD-004: install ONE fake and
  make the RESPONDER the mutable part.)
  (2) Keep the seed migration, and keep the catalog it seeds MINIMAL: the BytePlus/Seedream row
  was dropped from the product-image alt-model list (fal + OpenRouter only), which restores the
  other test's premise without weakening it. Verified: full suite green afterwards.
- **PREVENTION:** Never count provider calls with a counter inside an `Http::fake` closure — use
  `Http::recorded()` / `Http::assertSentCount()`. Assume EVERY matching stub closure runs. And
  before adding a migration that seeds a catalog table, grep the suite for `assertDatabaseMissing`
  / count assertions on that table — a seed migration is global state for every `RefreshDatabase`
  test.
- **RELATED:** [[laravel-backend]], [[TS-BUILD-004]], [[TS-BUILD-007]],
  [[tests/Feature/ProductImages/ProductImageTestSupport.php]],
  [[database/migrations/2026_07_13_120002_seed_product_image_operations.php]],
  [[database/seeders/AiControlPlaneSeeder.php]]

### TS-BUILD-006 — `php artisan test` dies with "Allowed memory size of 134217728 bytes exhausted" while `vendor/bin/phpunit` passes
- **Date:** 2026-07-12
- **Category:** build/deploy
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `phpunit`, `artisan-test`, `memory_limit`, `toolchain`, `herd`

- **SYMPTOM:** `php artisan test` fails partway through with repeated
  `In FinfoMimeTypeDetector.php line 76: Allowed memory size of 134217728 bytes
  exhausted (tried to allocate 7340161 bytes)` and never reports a summary. The exact
  same suite run as `vendor/phpunit/phpunit/phpunit` is fully green (983 tests).
- **CONTEXT / TRIGGER:** Any full-suite run once the suite crossed ~128 MB peak
  (it now peaks at ~144 MB — the media/image fixtures dominate). Hit while gating
  Shopify Phase 2. Passing `-d memory_limit=1G` to the artisan command does NOT help.
- **ROOT CAUSE:** `artisan test` SPAWNS a separate PHP process for PHPUnit. That child
  inherits the php.ini `memory_limit` (128M in Herd's php84), not the `-d` flag given
  to the parent artisan process — so the child OOMs while a direct phpunit invocation
  with a raised limit succeeds. It is not a leak in any test.
- **SOLUTION:** Pin the limit in the PHPUnit config itself so the child process reads
  it: in `phpunit.xml`, inside `<php>`, add
  `<ini name="memory_limit" value="512M"/>`.
  Verified: `php artisan test` -> 983 passed (3286 assertions).
- **PREVENTION:** Set test-runner ini values in `phpunit.xml` (`<ini>`), never on the
  artisan command line — the runner is a child process. If a suite-wide OOM appears
  after adding image/media fixtures, raise this value rather than chasing a leak.
- **RELATED:** [[phpunit.xml]], [[TS-MEDIA-001]], [[laravel-backend]]

### TS-BUILD-005 — the storefront widget must be a CLASSIC-script IIFE on a STABLE static path, not an ESM/hashed Vite asset (a `<script src>` can't send the site_key header, and the locked embed snippet has no `type=module`)
- **Date:** 2026-06-30
- **Category:** build/deploy
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `widget`, `esbuild`, `iife`, `vite`, `static-asset`, `code-splitting`, `dockerfile`, `phase-7b`

- **SYMPTOM / DESIGN TENSION (no live bug):** Building the Phase-7b widget bundle, two
  traps: (1) the embed snippet is locked as a CLASSIC `<script src="…/widget/v1/widget.js"
  data-site-key="…" async>` (no `type="module"`), so an ESM output with top-level
  `import`/`export` — or an esbuild `format:'esm'` + `splitting:true` build that emits a
  dynamic-import chunk — would NOT execute on the host page. (2) The widget must load via a
  `<script src>`, which cannot send the `X-Tray-Site-Key` header; if served through the
  `/widget/v1` API (behind `ResolveWidgetSite`) it would 401. And a Vite-hashed filename
  (`widget-AbC123.js`) breaks the stable embed URL.
- **CONTEXT / TRIGGER:** Phase 7b, wiring `resources/widget/build.config.mjs` into
  `npm run build` + the Dockerfile node stage.
- **ROOT CAUSE:** Classic scripts can't run ESM and can't send custom auth headers; the
  static-asset path must bypass PHP routing/middleware entirely; the URL must be stable.
- **SOLUTION:** Build with esbuild `format:'iife'` (a single self-contained file, no
  import/export — verified `grep -cE '^import |^export ' dist == 0`), minified, to the
  STABLE NON-HASHED path `public/widget/v1/widget.js`. FrankenPHP/Caddy serves it as a
  static file from `public/` BEFORE PHP routing, so it bypasses the `/widget/v1` API auth
  (no header needed) and never collides with the API routes (`/bootstrap`,
  `/generations/{id}`, …) — none is `/widget.js`. The widget is small enough (~11.8 KB
  gzipped, budget 25.6 KB) that bundling the modal in (rather than ESM code-splitting) is
  the reliable choice; the modal DOM is still only CONSTRUCTED on first button click, so
  first paint stays cheap. Wired: `package.json` `build` runs `vite build && build:widget`;
  the Dockerfile node stage runs `npm run build` and the final image
  `COPY --from=node-builder /app/public/widget ./public/widget`; `/public/widget` is
  gitignored like `/public/build`. A mechanical size-budget gate (gzip vs
  `size-budget.json`) exits non-zero over budget. VERIFIED: `node tests/widget/verify.mjs`
  boots the bundle from a real origin and passes every gate EN + HE.
- **PREVENTION:** A storefront/embed script that ships via a classic `<script src>` is an
  IIFE on a stable, non-hashed static path served straight from `public/`, never an ESM
  module, never behind header-based API auth, never a hashed Vite entry. Keep the size-budget
  gate in the build so weight regressions fail the build, not review.
- **RELATED:** [[widget-embed]], [[railway-infra]], [[resources/widget/build.config.mjs]],
  [[Dockerfile]], [[package.json]], [[resources/views/components/to/embed-code.blade.php]],
  [[app/Http/Middleware/ResolveWidgetSite.php]]

### TS-BUILD-004 — generation-suite determinism: `Sleep` is `Illuminate\Support\Sleep` (not `…\Facades\Sleep`), and a later `Http::fake([…])` does NOT override an earlier `Http::fake()`
- **Date:** 2026-06-25   (re-occurrence: 2026-07-13 — Shopify Phase 3 product sync)
- **Category:** build/deploy
- **Severity:** major
- **Recurrence:** 2
- **Status:** recurring
- **Tags:** `flaky-tests`, `sleep-fake`, `http-fake`, `test-isolation`, `money-path`, `determinism`, `phase-6`, `shopify`

> **RECURRENCE 2026-07-13 (Shopify Phase 3, `laravel-backend`).** The same trap, a NEW
> facet: it is not only an *empty* catch-all that wins — **two non-empty `Http::fake([...])`
> calls using the SAME url pattern also do not replace each other.** `Factory::fake()`
> MERGES stub callbacks and `stubCallbacks->first(non-null)` wins, so the FIRST stub keeps
> answering. Symptom in the sync suite: a test faked catalog page A, then re-faked a smaller
> catalog to simulate a deleted product — and kept getting page A, so "the product vanished
> from Shopify" never happened and the archive assertions failed with confusing "true is not
> false" messages. Same root cause bit the webhook tests (an `import()` helper that faked the
> API, then the test re-faked it with the updated product → the stale product came back).
> **VERIFIED FIX (reusable):** register EXACTLY ONE stub and swap the behaviour behind it —
> `tests/Feature/Shopify/ShopifyProductTestSupport::respondWith(Closure $responder)` installs
> `Http::fake(['*/admin/api/*/graphql.json' => fn ($r) => ($this->shopifyResponder)($r)])`
> once, and every later `fakeCatalog()` / `fakeSingleProduct()` only reassigns the closure.
> Re-stubbing then actually works. Rule of thumb: **`Http::fake()` is append-only within a
> test — treat it as install-once, and make the RESPONDER the mutable part.**

- **SYMPTOM:** Closing the Phase-6 determinism blocker (the consumer side of
  TS-OPENROUTER-003), two test-harness traps cost real time:
  (1) `use Illuminate\Support\Facades\Sleep;` → fatal `Class
  "Illuminate\Support\Facades\Sleep" not found` (18 generation tests errored at
  `setUp`). (2) After fixing the import, 6 money-path tests failed with the WRONG
  outcome (e.g. `ai_call_failed`/`cost_unavailable` instead of a charge, balance
  unchanged at $5): the per-test `Http::fake([...])` success/outage stub was being
  ignored and an EMPTY 200 returned for `/chat/completions`.
- **CONTEXT / TRIGGER:** Phase 6. The generation/money-path tests exercise the REAL
  `OpenRouterClient` (only the HTTP transport is faked), so the suite must `Sleep::fake()`
  the client's backoff. A shared `GenerationTestSupport::bootGenerationEnv()` set up the
  env, including (mistakenly) a pre-emptive empty `Http::fake()` "to isolate fakes."
- **ROOT CAUSE:**
  (1) Laravel's fakeable sleep helper lives at `Illuminate\Support\Sleep` (it is a
  real class with a `fake()`/`assertSleptTimes()` API, NOT under `Support\Facades`);
  the OpenRouterClient imports it from there. The test trait guessed the `Facades`
  namespace by analogy with `Http`/`Storage`.
  (2) In this Laravel version, `Http::fake()` (empty, a catch-all returning an empty
  200) followed by a later `Http::fake([url => response])` does NOT replace the
  catch-all — the empty stub WINS and swallows every request. Proven in a 6-line repl:
  `Http::fake(); Http::fake(['…/x' => Http::response(['ok'=>true])]); Http::get('…/x')`
  → empty body, `json('ok') === null`. The pre-emptive empty fake therefore masked
  every per-test stub.
- **SOLUTION:** (1) Import `use Illuminate\Support\Sleep;` and call `Sleep::fake()` in
  `bootGenerationEnv()` so the real retry/cost-lookup backoff runs instantly. (2) Do
  NOT pre-install an empty `Http::fake()`; each test installs its OWN COMPLETE
  `Http::fake([...])` via a `fake*` helper before it dispatches, and the HTTP factory is
  reset between tests, so there is no cross-test bleed under any `--filter` ordering.
  VERIFIED: `php artisan test --filter Generation` 20/20 all-green + 10/10 under
  `--order-by=random`; full `php artisan test` 20/20 all-green (281 passed, 834
  assertions every run, 0 failures across all 40 runs).
- **PREVENTION:**
  - The fakeable sleep is `Illuminate\Support\Sleep` — never `…\Facades\Sleep`. Any
    suite that exercises a class with `Sleep`-based backoff must `Sleep::fake()` in
    `setUp` (mirror the AI suite). A quick `php -r` import check catches the namespace.
  - Do NOT stack `Http::fake()` calls expecting the later one to override; an empty
    catch-all installed first WINS. Install ONE complete fake per test (or reset
    explicitly), and verify override behavior in a repl before relying on it.
  - When a money-path test "passes the gate but charges nothing / balance unchanged,"
    suspect the HTTP stub is being swallowed before suspecting the pipeline.
- **RELATED:** [[laravel-backend]], [[ai-openrouter]], [[TS-OPENROUTER-003]],
  [[tests/Feature/Generation/GenerationTestSupport.php]],
  [[tests/Feature/Generation/GenerateTryOnJobTest.php]]

### TS-BUILD-007 — `QUEUE_CONNECTION=sync` in tests makes a SELF-REDISPATCHING job cascade inline, silently invalidating page-by-page assertions
- **Date:** 2026-07-13
- **Category:** build/deploy
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `queue-sync`, `self-redispatch`, `cursor-pagination`, `bus-fake`, `test-isolation`, `shopify`, `phase-3`

- **SYMPTOM:** The Shopify catalog walk (`SyncShopifyCatalogJob` dispatches ITSELF with the
  next cursor) behaved bizarrely under test: a page-1 `handle()` call also ran page 2, page 3
  … to completion, so a test that then drove page 2 by hand found the run already TERMINAL
  and asserted against a state it had not created. Side effects were confusing rather than
  loud: `Sleep::assertSleptTimes(1)` reported **9** sleeps (the throttled page had been
  retried by three cascaded job instances), and a "resume from the parked cursor" test proved
  nothing because the cascade had already walked the whole catalog.
- **CONTEXT / TRIGGER:** Any test that invokes `->handle()` directly on a job that dispatches
  a continuation of itself (cursor pagination, poll loops, fan-out). `phpunit.xml` sets
  `QUEUE_CONNECTION=sync`, so `Job::dispatch()` inside `handle()` runs the next job INLINE,
  nested inside the first — the queue's boundary between units disappears.
- **ROOT CAUSE:** The `sync` driver executes a dispatched job immediately in-process. A job
  designed to be resumable (one unit of work per invocation, state persisted between them)
  therefore collapses into a single recursive call in tests — exactly destroying the property
  the test exists to prove.
- **SOLUTION:** `Bus::fake()` in `setUp()` for any suite that drives a self-redispatching job,
  then (a) assert the continuation was DISPATCHED with the right arguments
  (`Bus::assertDispatched(SyncShopifyCatalogJob::class, fn ($job) => $job->cursor === 'cursor-1')`)
  and (b) invoke the next unit by hand. Each page then runs exactly once, from persisted state
  — which is what proves resumability. VERIFIED: `tests/Feature/Shopify/ShopifyCatalogSyncTest.php`
  (walk, throttle-park, cursor-resume, archive-stale) — 110 Shopify tests green, full suite 1048.
  Note `Bus::fake()` does NOT block a job you `->handle()` yourself, so the work still runs.
- **PREVENTION:** If a job dispatches itself (or a sibling) from inside `handle()`, its test
  MUST `Bus::fake()` — otherwise `sync` turns N independent, resumable units into one nested
  call. Symptom to recognise: a job-count/sleep-count/attempt-count assertion off by an exact
  multiple, or a "resume" test that passes for the wrong reason.
- **RELATED:** [[laravel-backend]], [[TS-BUILD-004]],
  [[app/Domain/Shopify/Products/SyncShopifyCatalogJob.php]],
  [[tests/Feature/Shopify/ShopifyCatalogSyncTest.php]], [[phpunit.xml]]

### TS-BUILD-003 — Phase-2 `sites.free_generations_before_signup` was NOT NULL; the lead gate's `null` ("signup never required") could not be stored
- **Date:** 2026-06-25
- **Category:** build/deploy
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `migration`, `lead-gate`, `nullable`, `contract-shape`, `sites`, `phase-5a`

- **SYMPTOM:** A Phase-5a `LeadGateTest` case (`null` = no signup ever required) failed
  on insert: `SQLSTATE[23000]: NOT NULL constraint failed: sites.free_generations_before_signup`.
  The locked contract (ARCHITECTURE.md "The lead gate") defines THREE meanings —
  `N` (free tries), `0` (signup before first try), `null` (never) — but the column could
  not hold `null`.
- **CONTEXT / TRIGGER:** Phase 5a, building the LeadGate. The Phase-2/3 sites migration
  declared `free_generations_before_signup` as `unsignedSmallInteger(...)->default(2)`
  with no `->nullable()` — it predated the lead-gate semantics being implemented in the
  backend, so the `null` meaning had no storage.
- **ROOT CAUSE:** The column shape was set before the gate that consumes it existed, so
  the third (`null`) state of the locked contract was unrepresentable. A default of 2 is
  correct, but the column must ALSO accept `null`.
- **SOLUTION:** A forward-only migration makes the column nullable while keeping the
  default 2: `database/migrations/2026_06_25_110003_make_sites_free_generations_nullable.php`
  uses `->default(2)->nullable()->change()` (Laravel 11 native column change — no
  doctrine/dbal needed). Existing rows are unchanged. Verified: `migrate:fresh` applies
  cleanly and `tests/Feature/Leads/LeadGateTest.php::test_null_free_never_requires_signup`
  passes; full suite 199 passed (up from 136).
- **PREVENTION:** When a column encodes a tri-state contract (a sentinel `null` meaning),
  the migration must allow `null` even if a non-null default is the common case. When a
  later phase implements the gate that reads a column an earlier phase shaped, re-check
  the column can hold EVERY value the locked contract assigns meaning to.
- **RELATED:** [[laravel-backend]], [[app/Domain/Leads/LeadGate.php]], [[app/Models/Site.php]],
  [[database/migrations/2026_06_25_110003_make_sites_free_generations_nullable.php]],
  [[tests/Feature/Leads/LeadGateTest.php]]

### TS-BUILD-001 — `composer create-project laravel/laravel` pulls Laravel 13, not 11
- **Date:** 2026-06-24
- **Category:** build/deploy
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `composer`, `laravel-version`, `scaffold`, `filament`

- **SYMPTOM:** `composer create-project laravel/laravel skel` installs Laravel
  Framework 13.x. Filament 3 requires Laravel 10/11, so the panels would not install.
- **CONTEXT / TRIGGER:** Phase 1 scaffold. The unversioned create-project always
  takes the latest framework; the locked contract is Laravel 11.
- **ROOT CAUSE:** `laravel/laravel` is unpinned; "latest" drifted past 11.
- **SOLUTION:** Pin the skeleton: `composer create-project "laravel/laravel:^11.0" skel`.
  Verified `php artisan --version` -> Laravel 11.54.0, and Filament 3.2 + Horizon 5
  install cleanly against it.
- **PREVENTION:** Always pin the major in create-project when a framework version
  is locked by contract: `laravel/laravel:^11.0`.
- **RELATED:** [[railway-infra]], [[composer.json]]

### TS-BUILD-002 — Laravel 11.x blocked by composer audit security advisories
- **Date:** 2026-06-24
- **Category:** build/deploy
- **Severity:** major
- **Recurrence:** 1
- **Status:** resolved
- **Tags:** `composer`, `audit`, `block-insecure`, `laravel-11`, `advisories`

- **SYMPTOM:** `composer update` for a `^11.31` framework constraint fails:
  "found laravel/framework[v11.31.0, ..., v11.54.0] but these were not loaded,
  because they are affected by security advisories" (PKSA-3r5d-mb8f-1qw9,
  PKSA-m5cs-t1y6-qpcs, PKSA-mdq4-51ck-6kdq). Dependency resolution aborts.
- **CONTEXT / TRIGGER:** Phase 1. Composer's `block-insecure` (audit) refuses the
  whole 11.x range because Laravel 11 is in security-fix-only mode and the listed
  advisories span the available patch range, so no 11.x version is "clean".
- **ROOT CAUSE:** The contract locks Laravel 11; the only 11.x versions available
  carry framework-level advisories that the audit blocker treats as install-stopping.
- **SOLUTION:** Add the three advisory IDs to composer.json `config.audit.ignore`
  so the locked-contract version can install:
  `composer config audit.ignore PKSA-3r5d-mb8f-1qw9 PKSA-m5cs-t1y6-qpcs PKSA-mdq4-51ck-6kdq`.
  Verified `composer install` then succeeds (80+ packages); committed so the
  production Docker `composer install` resolves identically.
- **PREVENTION:** When a contract pins a maintenance-mode framework major, expect
  audit advisories on its remaining range; record the specific advisory IDs in
  `config.audit.ignore` (do NOT blanket-disable `block-insecure`) and re-review
  them when the project moves to a newer major.
- **RELATED:** [[railway-infra]], [[composer.json]]
