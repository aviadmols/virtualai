# ARCHITECTURE.md — Locked Decisions

This is the contract. Agents must not silently deviate; if a capability behaves
differently than assumed, adapt the *implementation*, never drop a *pillar*.

## Product

**Tray On** — a multi-tenant SaaS that lets a shopper, on any e-commerce product
page (PDP), see an AI-generated image of how a product looks *on them* before
adding it to cart. The merchant installs a lightweight JavaScript widget; the
widget injects a **Tray On** button under the existing "Add to cart" button. The
shopper uploads a photo + height, and Tray On returns a realistic try-on image,
then lets them add the exact selected variant to cart.

Sold to merchants on a **prepaid-credit** model (no fixed subscription required
for v1): a new merchant gets **$5 opening credit**; each successful generation
debits credits at **2.5× the real OpenRouter cost**. Scales to **hundreds /
thousands of sites**, multi-tenant, EN + HE with RTL.

The three product pillars — **none may be dropped**:

1. **PDP ingestion** — paste a product URL → AI scans it and extracts product
   data, variants, physical dimensions, and the page selectors (add-to-cart,
   product image, title, price, description, variations); merchant confirms or
   corrects every field manually.
2. **Try-on generation** — the widget collects shopper photo + height (+ optional
   body/age/gender/angle) and, via **OpenRouter**, generates a realistic try-on
   of the *selected variant*; result screen offers regenerate / change photo /
   add-to-cart / back to product; an on-site gallery slider holds the session's
   generations.
3. **Credits, leads & control plane** — per-account credit ledger (grant /
   purchase / charge / refund), per-site lead capture before/after a free-tries
   limit, and a Super-Admin control plane that manages models, prompts, costs,
   accounts, sites, and credits **from the database, never hardcoded**.

## Locked decisions

| Decision | Choice |
|---|---|
| Codebase | Fresh **Laravel 11** repo. Clean, short, single-responsibility classes; **CONST-at-top**; comments **in English only**. |
| Admin UI | **Filament 3**, two panels: `platform` (Super Admin) and `merchant` (account owner). Re-skinned via design tokens → CSS vars. |
| Tenancy | Multi-tenant, single DB. **`account_id` on every tenant table + `BelongsToAccount` global scope.** `site_id` is the sub-scope within an account. |
| Tenant boundary | The **Account** is the tenant (holds credits + billing). A **Site** belongs to an account and carries its own config, selectors, prompts, model, gallery, leads, widget key. |
| AI provider | **OpenRouter** for all model calls (scan extraction + image generation). **Platform-level** API key (server-side) — accounts do NOT bring their own AI keys. |
| Model/prompt config | **Database-managed**, never hardcoded. Resolved per operation with override order **site → account → product_type → global**. Super-Admin edits without a redeploy. |
| Credits | Balance stored as **integer micro-USD of selling value** on `accounts`. Opening grant `$5`. Charge = `actual_cost_usd × MARKUP` (default **2.5**, admin-configurable). Debit **only on success**; **release on failure**. |
| Credit purchase rail | **LOCKED (2026-06-24): PayPlus only for v1** (the team already integrates PayPlus). Built behind a `CreditPaymentProvider` interface so Stripe (or another global rail) can be added later without touching call sites. Env: `PAYPLUS_*`. |
| Hosting | **Railway**: 3 services — web (FrankPHP/Caddy), worker (Horizon), scheduler. **Postgres + Redis**. |
| Queues | Laravel **Horizon** on Redis; queues split by type: `generations`, `scans`, `webhooks`, `media`, `default`. |
| Media storage | S3-compatible (Cloudflare R2 / S3) behind a **CDN**; **signed URLs**; per-site **retention** policy (7/30/90 days or until manual delete). |
| Widget | Vanilla JS, **no framework**, lazy-loaded, target **< 20 KB gzipped**; must not hurt host-site SEO, LCP, or CLS. Loaded via `<script src=".../widget.js" data-site-key="…">`. |
| Language | English default; full i18n via `lang/en` + `lang/he`; RTL-aware everywhere (admin + widget). |

## The tenant hierarchy

```
User ──owns──> Account (TENANT: credit balance, billing, status)
                  └──has many──> Site (sub-scope: domain, selectors, prompts,
                                       model, gallery, leads, widget keys)
                                    ├── Product (scanned PDP)  + variants
                                    ├── EndUser (lead / "Tray On user")
                                    └── Generation (one try-on attempt)
```

- `Account` is the isolation boundary. Every tenant-owned row carries
  `account_id NOT NULL` + `BelongsToAccount`. Rows that belong to a site also
  carry `site_id` (indexed), but isolation is enforced by `account_id`.
- `credit_ledger` is **account-scoped** (credits are shared across an account's
  sites). `EndUser` / `Generation` / `Product` are **site-scoped** (and
  account-scoped for isolation).
- Global, non-tenant models (documented allow-list): `AiModel` catalog,
  `AiOperation` defaults, global `Prompt`s, platform settings. These are
  excluded from `BelongsToAccount` and live on the isolation-audit allow-list.

## Per-site widget credentials

Each site has a **public `site_key`** (sent by the widget in the browser) and a
server-side **`widget_secret`** (encrypted at rest). The widget API authenticates
a request by: (1) matching `site_key`, (2) verifying the request `Origin` against
the site's **allow-listed domain(s)**, (3) optional HMAC on sensitive calls. The
OpenRouter key is **never** exposed to the browser — all model calls are
server-side. Credentials are encrypted with a dedicated cast keyed by
`TENANT_CREDENTIALS_KEY` (base64, separate from `APP_KEY`) so key rotation is
independent.

## AI operations, models & prompts (DB-managed control plane)

- `ai_operations` — one row per operation: `product_scan`, `try_on_generation`.
  Fields: default model, fallback model, image quality, aspect ratio, retention,
  estimated cost, `credit_multiplier` (overrides global MARKUP when set),
  input-schema shape.
- `ai_models` — catalog of allowed OpenRouter model ids per operation, with a
  default + fallback flag and a per-1k/per-image cost hint.
- `prompts` — `scope ∈ {global, product_type, account, site}`, `operation_key`,
  nullable `product_type`, `system_prompt`, `user_prompt` (templated with
  `{{placeholders}}` substituted via `strtr`, **never `Blade::render()`**),
  `version`. **Resolution order: site → account → product_type → global** (first
  match wins; fall through to `global` which must always exist).

> No prompt or model id is hardcoded in a service. A service asks
> `AiOperationResolver::for($operation, $site, $productType)` for the resolved
> `{model, fallback, system_prompt, user_prompt, params}` bag.

## Charge contexts & generation states

- `generation.status`: `pending → processing → succeeded` ·
  `pending → processing → failed` · `pending → cancelled` ·
  `processing → cancelled`. Guarded `transitionTo()`; every move writes an
  `activity_event`.
- `credit_ledger.type`: `grant` (opening / admin) · `purchase` · `charge`
  (debit on a succeeded generation) · `refund` (reverse a charge) ·
  `adjustment` (admin ±). Append-only; corrections are new rows.
- `end_user.status` (lead funnel): `new → generated → added_to_cart → purchased`
  · any → `incomplete`. Set forward-only by events (purchased is terminal-best).

## The money path (credits) — the law

1. **Gate before work.** `CreditGate::for($account)->assertCanSpend($estimate)`
   checks `balance − reserved ≥ estimate`. A denial is a typed `CreditDenied`
   result (an "out of credits" UI), never a 500.
2. **Reserve.** A short-lived reservation (Redis + a `reserved_micro_usd` column)
   holds the estimated max charge for the in-flight generation.
3. **Generate.** Run the OpenRouter call (worker job). Real cost comes back in
   the response.
4. **On success:** write a `charge` ledger row = `round(actual_cost_usd ×
   multiplier)`; update `balance_after`; release the reservation. **No charge
   without a ledger row; the ledger is the truth, OpenRouter is the side effect.**
5. **On failure:** release the reservation; **no `charge` row** (the merchant is
   never billed for a failed try-on). Record the failure in `activity_events`.

> Mirror of the reference engine's rule "no charge without a `payment_ledger`
> row, written before the side effect": here the **reservation** is written
> before the OpenRouter call, and finalized to a `charge`/release after.

## The lead gate (end-user free-tries — independent of the credit gate)

- Per-site `free_generations_before_signup` (default **2**; `0` = signup
  required before first try; `null` = no signup ever required).
- Tracked per `EndUser` (anonymous browser token, persisted server-side keyed by
  `(site_id, anon_token)` so it survives navigation between PDPs).
- When exhausted, the widget shows a short signup form (full name, email, phone).
  After signup the merchant may grant N extra / unlimited / gated tries
  (`post_signup_grant`).
- **Two independent gates, both must pass:** the *merchant* must have credits
  (`CreditGate`), and the *end user* must be under the free limit or registered
  (`LeadGate`). They never collapse into one.

## Idempotency keys (deterministic — never random)

- `scan:{account_id}:{site_id}:{sha1(url)}`
- `generation:{account_id}:{site_id}:{end_user_id}:{product_id}:{sha1(variant)}:{client_request_id}`
- `purchase:{account_id}:{provider}:{provider_ref}`
- `refund:{account_id}:{generation_id}`

Defense in depth for generations: (1) `GenerateTryOnJob implements ShouldBeUnique`
keyed by the idempotency key; (2) row lock on the `generation` inside a DB
transaction; (3) ledger pre-check (a `charge` row for this generation means do
not charge again); (4) `client_request_id` from the widget collapses
double-clicks. **Never charge twice for one generation.**

## Env contract (high level — see `.env.example`)

- App: `APP_KEY`, `APP_URL`, `APP_ENV`, `APP_DEBUG`.
- DB/queue: `DATABASE_URL` (Postgres), `REDIS_URL`, `QUEUE_CONNECTION=redis`,
  `CACHE_STORE=redis`, `SESSION_DRIVER=database`.
- OpenRouter (platform): `OPENROUTER_API_KEY`, `OPENROUTER_BASE_URL`,
  `OPENROUTER_TIMEOUT`, `OPENROUTER_HTTP_REFERER`, `OPENROUTER_APP_TITLE`.
- Media: `MEDIA_DISK=s3`, `S3_*` / `R2_*`, `MEDIA_CDN_URL`, `MEDIA_SIGNED_TTL`.
- Credit purchase rail (LOCKED — PayPlus): `PAYPLUS_*` (api key, secret key,
  terminal/page UID, base URL, webhook secret). The `CreditPaymentProvider`
  interface keeps a future Stripe rail swappable.
- Pricing: `CREDIT_MARKUP_DEFAULT=2.5`, `CREDIT_OPENING_GRANT_USD=5`.
- Tenancy encryption: `TENANT_CREDENTIALS_KEY` (base64, separate from APP_KEY).
- PDP fetch/render (if a headless renderer is used): `SCRAPER_*` — locked when
  the scan agent picks the fetch strategy.

## Module map (target)

- `app/Models/{Account,Site,User}.php` — tenant + sub-scope + auth.
- `app/Support/Tenant.php` + `app/Models/Concerns/BelongsToAccount.php` — tenancy.
- `app/Domain/Credits/` — `credit_ledger`, `CreditGate`, reservations,
  `CreditPaymentProvider` interface, purchase flow, markup math.
- `app/Domain/Scan/` — PDP fetch + AI extraction + selector confidence + the
  confirm/correct contract.
- `app/Domain/Generation/` — the try-on pipeline (gate → reserve → generate →
  charge/release), `GenerateTryOnJob`, status machine.
- `app/Domain/Ai/` — OpenRouter client, `AiOperationResolver`, model/prompt
  resolution, cost parsing, fallback.
- `app/Domain/Leads/` — `EndUser`, `LeadGate`, capture, export, privacy/retention.
- `app/Filament/Platform/` + `app/Filament/Merchant/` — the two panels.
- `resources/widget/` — the storefront JS widget, modal, and gallery.

## Reference project (pattern oracle — read-only)

`C:\Users\user\Desktop\Projects\תוסף RECHAREG לPAYPLUS` is the **quality and
structure oracle**, not a code-port source (different domain). Mirror its: agent
team shape, CONST-at-top + no-inline-CSS + `strtr`-not-Blade conventions,
multi-tenant `BelongsTo*` + global-scope pattern, immutable ledger discipline,
deterministic idempotency keys, Filament token→CSS-var theming, EN/HE RTL wiring,
and the phase-gate enforcement style. We borrow the *engineering*, not the
PayPlus billing code.
