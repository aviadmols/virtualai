## 2026-06-24T00:00:00Z — Phase 2 — Multi-Tenancy Core — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Lead: laravel-backend

Scope (files reviewed):
app/Support/Tenant.php · app/Support/TenantCredentialsCipher.php · app/Support/GlobalModels.php ·
app/Models/Concerns/BelongsToAccount.php · app/Casts/EncryptedString.php · app/Casts/EncryptedJson.php ·
app/Models/Account.php · app/Models/Site.php · app/Models/User.php ·
app/Jobs/TenantAwareJob.php · app/Jobs/ProvisionSiteJob.php ·
database/migrations/2026_06_24_100000_create_accounts_table.php ·
database/migrations/2026_06_24_100001_create_sites_table.php ·
database/migrations/2026_06_24_100002_add_account_fk_to_users_table.php ·
database/migrations/0001_01_01_000000_create_users_table.php (account_id column edit) ·
database/factories/{AccountFactory,SiteFactory,UserFactory}.php ·
database/seeders/TenantDemoSeeder.php · config/trayon.php ·
tests/Feature/Tenancy/{TenantIsolationTest,TenantLeakBetweenJobsTest,WidgetSecretEncryptionTest,SiteKeyAndAllowListTest}.php

Sweeps run:
- withoutGlobalScope in app/ -> CLEAN (only a comment in BelongsToAccount.php:26 stating it is forbidden)
- Tenant::current / session() / request()-> / config() misuse in app/Jobs -> CLEAN (only doc comments; no ambient-tenant read)
- float / double / decimal money columns in migrations -> CLEAN (balance/reserved are bigInteger micro-USD)
- hardcoded model ids (gpt-/claude-/gemini/flux/dall/sdxl) in app/ -> CLEAN (no AI services exist yet)
- DB::table / DB::statement / DB::select on tenant tables in app/ -> CLEAN (only HealthController `select 1`)
- secrets echoed to Log::/logger/info/dd/dump in app/ -> CLEAN (only infra heartbeat/predeploy info() strings)
- style="..." / arbitrary CSS in resources/ -> CLEAN (no UI this phase)

Tests run:
- php artisan test --filter Tenancy -> 16 passed (50 assertions)
- php artisan test (full)          -> 18 passed (52 assertions)

Verdict: PASS-WITH-SUGGESTIONS. Gate criterion TENANT-SAFE is met. No BLOCKING findings.

--- Universal gate (Phase 2 surface) ---
[OK] Only tenant-owned model this phase (Site) carries account_id NOT NULL + BelongsToAccount; FK constrained + indexed (account_id,id).
[OK] Allow-list (GlobalModels) is the only un-scoped set: User (auth, documented) + future AiModel/AiOperation/Prompt/PlatformSetting. Account is the tenant root (exempt by definition).
[OK] BelongsToAccount global scope fails closed: NO_TENANT_SENTINEL=0 -> empty set when unbound (proven by test_unbound_query_fails_closed_returns_nothing).
[OK] Creating-hook requires a bound tenant when account_id is absent (throws RuntimeException) -> no orphan/unscoped writes.
[OK] TenantAwareJob: explicit `int $accountId` ctor; handle() is final and wraps process() in Tenant::run() (clears in finally). ProvisionSiteJob follows it and never reads ambient Tenant.
[OK] TS-TENANCY-001 reproduction test is meaningful: back-to-back A-then-B jobs, asserts B never lands on A via raw DB count; asserts context cleared after each job.
[OK] widget_secret encrypted via EncryptedString cast keyed by TENANT_CREDENTIALS_KEY (separate from APP_KEY, base64); in $hidden; ciphertext-at-rest + not-serialized proven by test.
[OK] Money columns integer micro-USD (bigInteger), no floats; casts 'integer'.
[OK] CONST-at-top present in every class file; config/trayon.php uses defined()||define() (accepted TS-INFRA-003 exception for config files).
[OK] English-only comments; small single-responsibility classes; no inline CSS.

--- Findings (none gate the phase) ---
[SUGGEST] #1  app/Models/User.php:18 — §3.4 doc drift. The docblock references a "forAccount scope" ("isolation is enforced explicitly at the panel/query layer (forAccount scope)") but no scopeForAccount exists on User (only the factory has a forAccount() state). Either add a query scope `scopeForAccount(Builder, Account)` so account-owner isolation has a single enforced helper, or reword the comment to describe the actual mechanism. Account-owner isolation at the query layer is currently unimplemented and undocumented in code — fine for Phase 2 (no panels yet) but must be real before the merchant panel (Phase 8) lists users. Not a blocker now: User is on the allow-list by design and no user-listing query ships this phase.

[SUGGEST] #2  app/Models/Concerns/BelongsToAccount.php:38-51 — §3.1 defence-in-depth on writes. The creating hook returns early when account_id is already set (line 39), so a caller bound as Account A who passes an explicit `account_id = B` would write a row onto B. The read scope still isolates (A can never read it back), and the early-return is needed for seeders/platform-admin, so this is not a read leak. Consider hardening: when a tenant IS bound AND an explicit account_id is passed that differs from Tenant::id(), throw (cross-tenant write guard) rather than silently honour the foreign account_id. Add a test asserting the mismatch throws. Leaving as SUGGEST because no product write-path passes a foreign account_id this phase and reads remain isolated.

[SUGGEST] #3  app/Casts/EncryptedJson.php — §3.7 untested cast. EncryptedJson is authored but unused this phase and has no test. The sibling EncryptedString is covered. When the first consumer lands (per-site credential blob), add a round-trip + ciphertext-at-rest test. No risk now (no caller); recorded so it isn't forgotten.

[NIT]     #4  app/Models/User.php — §3.4 casts() style. User uses the Laravel-11 default-stub array-doc-comment style while Account/Site use the terse project house style. Cosmetic alignment only.

--- The two scrutinized decisions (both ACCEPTED as safe + documented) ---
1. User NOT BelongsToAccount — ACCEPTED. Rationale is sound and documented in three places (User.php docblock, GlobalModels.php allow-list comment, users migration comment): auth resolves a user before a tenant is bound, and super-admins (account_id NULL) must be visible across accounts. User is explicitly on the GlobalModels allow-list so the future isolation audit asserts the un-scoped set equals exactly the allow-list. Caveat tracked as Finding #1: account-owner (non-super-admin) isolation at the query/panel layer is asserted in comments but not yet implemented; must be real before any panel lists users.
2. sqlite-skips-FK on users.account_id — ACCEPTED. Documented in the migration: SQLite cannot ALTER-add an FK to an existing table; tests run on sqlite so the FK is skipped there, while the indexed account_id column still enforces shape and Postgres (production) gets the real constraint. Safe because isolation is enforced at the app layer (global scope), not by the DB FK; the FK is referential hygiene, not the isolation mechanism. Production parity is preserved.

Blocking: NONE.
Suggestions: #1 User forAccount-scope doc drift · #2 cross-tenant-write defence-in-depth · #3 EncryptedJson untested.
Nits: #4 User casts() style.

Re-review: NOT required to advance. Gate may flip GREEN (TENANT-SAFE). Suggestions are at laravel-backend's discretion; #1 should be resolved before the Phase 8 merchant panel lists users.

Recurring -> archivist: none new. TS-TENANCY-001 is correctly encoded and tested; no recurrence. (Hand Finding #2 — "explicit foreign account_id honoured on write while a different tenant is bound" — to troubleshooting-archivist as a watch item, since it is a class of bug that could recur in later tenant models that accept account_id from input.)
Cross-reference: saas-credits-billing isolation audit not yet run for Phase 2; this review is the independent second pair of eyes and confirms TENANT-SAFE on the current surface. The formal audit remains the release blocker.
