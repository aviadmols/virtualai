# Tenancy core — Phase 2 contract

The backend tenancy spine. Built fresh against ARCHITECTURE.md ("The tenant
hierarchy", "Per-site widget credentials") and CLAUDE.md (Tenant-safety is a
release blocker). This document is the stable reference the later phases
(widget-embed, the saas-credits-billing isolation audit) build against.

## The boundary

- **Account** = the tenant and the isolation boundary. Holds the credit balance
  (integer micro-USD) + status. NOT account-scoped (it scopes everything else).
- **Site** = the sub-scope within an account (`account_id NOT NULL`,
  `BelongsToAccount`). Carries the public `site_key` and the encrypted
  `widget_secret`.
- **User** = auth. An account owner has `account_id` set; a platform super-admin
  has `account_id = NULL` + `is_super_admin = true`. User is GLOBAL (on the
  allow-list) because auth resolves a user before any tenant is bound and
  super-admins must be visible across accounts. Account-owner isolation is
  enforced explicitly at the panel/query layer, not via the tenant global scope.

## How isolation is enforced

- `App\Support\Tenant` is the single source of the bound account id.
  `Tenant::run($account, fn)` binds in `try` and ALWAYS clears in `finally`
  (restoring any previous binding), so a long-lived worker can never leak one
  job's tenant into the next (TS-TENANCY-001). `current()`, `id()`, `check()`.
- `App\Models\Concerns\BelongsToAccount` adds a global scope
  (`where account_id = Tenant::current()`) + a `creating` hook that auto-fills
  `account_id` from the bound tenant.
- **FAIL-CLOSED:** with NO tenant bound, the scope constrains `account_id` to an
  impossible sentinel (0), so the query returns NOTHING — never all accounts'
  rows. A forgotten `Tenant::run()` or a forgotten `where()` returns an empty
  set, never a leak.
- Creating a tenant model with no `account_id` and no bound tenant THROWS.
- **No `withoutGlobalScopes()` in product code.** Only a future audited
  platform-admin service may bypass the scope; none exists yet.

## Tenant-safe jobs (the universal rule)

Every tenant-touching job extends `App\Jobs\TenantAwareJob`:

- carries `account_id` EXPLICITLY in the constructor (never inferred from
  session / domain / config / the ambient `Tenant`);
- `handle()` is `final` and binds via `Tenant::run($this->accountId, …)`, which
  clears in `finally`;
- subclasses implement `process()` and must resolve their account from
  `$this->accountId`, never from `Tenant::current()`.

Example: `App\Jobs\ProvisionSiteJob`.

## Per-site widget credentials (auth contract — API built in Phase 7)

- **`site_key`** — public, unique, generated on site creation
  (`Site::generateSiteKey()`). Sent by the widget IN THE BROWSER. Persisted as
  `NULL` when absent, NEVER `''` (empty string collides under the unique index;
  a `saving` guard coerces `'' -> NULL`).
- **`widget_secret`** — server-side HMAC secret, generated on creation
  (`Site::generateWidgetSecret()`). Encrypted at rest via the `EncryptedString`
  cast keyed by `TENANT_CREDENTIALS_KEY` (separate from `APP_KEY`, so the
  credential key rotates independently). It is in the model's `$hidden` array,
  so it never serializes into an array/JSON response. **Never sent to the
  browser.**
- The widget API (Phase 7) authenticates a request by: (1) matching `site_key`,
  (2) verifying the request `Origin` against `sites.allowed_origins`, (3)
  optional HMAC on sensitive calls using `widget_secret`. The OpenRouter key is
  never exposed to the browser; all model calls are server-side.

## Encrypted casts

- `App\Casts\EncryptedString` — scalar secret at rest (used for
  `widget_secret`).
- `App\Casts\EncryptedJson` — for future per-site credential blobs (grouped
  secret fields).
- Both use `App\Support\TenantCredentialsCipher`, an AES-256-CBC encrypter keyed
  by `config('trayon.credentials_key')` = `TENANT_CREDENTIALS_KEY`.

## The global (non-tenant) allow-list

`App\Support\GlobalModels::ALLOW_LIST` is the explicit, code-level registry of
models intentionally NOT scoped by `BelongsToAccount`. The future isolation
audit asserts the set of un-scoped models equals exactly this list; any other
un-scoped model is a leak. Current entries (some classes arrive in later
phases): `User` (global auth + super-admins), `AiModel`, `AiOperation`,
`Prompt`, `PlatformSetting`. Add a class here ONLY when it is a genuinely
platform-global catalog — never to silence the audit for a tenant model.

## Money columns

`accounts.balance_micro_usd` and `accounts.reserved_micro_usd` are integer
micro-USD (never floats). Declared in Phase 2; the ledger / reservation logic
lands in Phase 5.
