# Shopify App — Phase 0 Decisions (locked 2026-07-12)

> The compliance & architecture spike deliverable from the approved Shopify plan.
> Every later phase builds on these decisions; changing one after code exists is a
> re-plan, not a tweak.

## 1. Billing — per distribution channel

**Decision: internal credits stay the single metering unit everywhere. Only the
credit PURCHASE rail changes per channel.**

| Channel | App charges rail | Status |
|---|---|---|
| Custom / unlisted (now) | Existing external rails (Stripe Checkout / PayPlus) via `CreditPaymentProvider` | Allowed — off-platform billing is only banned for App Store distribution |
| Public App Store (later) | **Shopify Billing API is mandatory** for all app charges (subscription / one-time / usage). External gateways for app fees violate the Partner Program Agreement; exemptions are rare and need explicit Shopify permission | New `ShopifyBillingProvider implements CreditPaymentProvider`: `appPurchaseOneTime` (credit packs) and/or `appUsageRecord` (metered), confirmed via the same idempotent purchase-webhook → `credit_ledger` 'purchase' row |

Why this holds: `CreditPaymentProvider` is already the project's abstraction for
"money in → credits" (Stripe/PayPlus). Shopify Billing becomes a third provider —
no change to CreditGate, reservations, ledger, markup math, or any purchase screen
logic beyond provider selection for `platform=shopify` sites. **No late-stage
billing rewrite is possible under this shape.**

Consequence for screens: for App Store distribution, credit-purchase CTAs on
Shopify-connected sites must route to the Shopify Billing confirmation URL
(merchant approves inside Shopify admin), not to Stripe/PayPlus checkout.

## 2. OAuth install-origin flows

Two first-class flows (both in Phase 2):

- **`connect_existing_site`** — starts in the Tray On Filament panel. State nonce
  carries `{account_id, site_id}`; the callback verifies HMAC + nonce + shop
  regex, exchanges the code for an offline token, persists inside `Tenant::run`.
- **`install_new_shop`** — starts on Shopify (no Tray On account yet). Callback
  verifies HMAC/shop, stores the token in a short-lived encrypted
  **pending-install** record (NOT tenant-bound), sends the merchant through
  register-or-login, and only an authenticated account consumes the record to
  create the `Site` + `ShopifyConnection` inside `Tenant::run`. Re-install of a
  known `shop_domain` re-activates the existing connection (never duplicates).

Token type: **offline access token** (background jobs need it). Embedded admin
uses **session tokens** (App Bridge) for UI auth only — never for API calls.

## 3. Scopes (requested at install; minimal by design)

| Scope | Used by |
|---|---|
| `read_products` | catalog sync, product picker |
| `write_products` | pushing approved images to product media |
| `read_orders` | purchase attribution (orders/paid webhook) |

**Protected customer data:** order webhooks carry protected customer data and a
distributed app must be approved for it (Partner Dashboard → API access
requests). Our design needs **"Protected customer data" level only — zero
protected customer FIELDS** (no name/email/phone/address): attribution reads
line items (variant id, quantity, price), line-item properties (`_trayon`
token), order id and currency. Request exactly that, nothing more. Development
stores are exempt during development; the request must be approved before any
non-dev distribution that registers `orders/paid`.

## 4. Public-app constraints acknowledged now (so nothing blocks the listing)

- **GDPR webhooks** (`customers/data_request`, `customers/redact`, `shop/redact`)
  registered and answered from day one (Phase 2), wired to real erasure in Phase 7.
- **Embedded-first UX + session tokens + Polaris** are App Store review
  requirements — the Phase 2 embedded shell keeps the OAuth top-level redirect
  App Bridge-compatible and isolates session-token verification in one middleware
  so the full Polaris UI is additive later.
- **Billing** — see §1; review checks it.
- API version pinned in `config/shopify.php`; quarterly bump is a config change.

Sources: [App Store requirements](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements),
[Protected customer data](https://shopify.dev/docs/apps/launch/protected-customer-data),
[Unlisted apps & Billing API (dev forums)](https://community.shopify.dev/t/unlisted-public-app-is-shopify-billing-api-mandatory-or-can-we-use-stripe/32021).

## 5. Media push — `productCreateMedia` is SCHEDULED DEBT (not a Phase-5 blocker)

`ShopifyMediaQueries::productCreateMedia()` calls a mutation Shopify marks
**deprecated** in favour of the `productUpdate` / `productSet` media input. It is
**supported on the pinned `2026-04` version** (`config/shopify.php`), Shopify does
not reject an app for calling a supported mutation, and it is the mutation that
returns `mediaUserErrors` verbatim — which is the merchant's only explanation when
an image is refused. So it ships.

It is debt with a clock on it: the migration to `productUpdate` / `productSet` must
land **before the pinned version ages out of the supported window (~12 months, i.e.
by the 2027-04 line)**, or the next quarterly bump breaks the push rail.
`ShopifyMediaQueries` is the ONE file to change. Routed to Phase 7.

## 6. Media push — the destructive rail's non-negotiables

A REPLACE deletes a media from a LIVE storefront and Shopify drops its bytes with
it. The rail therefore holds these laws, each pinned by a mutation-verified test in
`tests/Feature/Shopify/ShopifyMediaSafetyTest.php`:

- **A write attempted is not a write verified.** Every disk is `throw => false`, so
  a failed `put()` returns FALSE. `MediaStorage::write()` checks the boolean AND
  reads the object back; anything else is a typed `MediaWriteException`. A path that
  points at nothing may never be persisted, charged for, or snapshotted.
- **`captured` is a promise.** A snapshot is stamped CAPTURED only after every
  original it claims reads back non-empty; a destructive push re-verifies it, and a
  REPLACE additionally proves the ONE media it will delete can be handed back.
- **The snapshot holds the merchant's TRUE originals** — media WE pushed
  (`product_assets.shopify_media_id`) never enter it, or undo would re-inject our own
  AI image into the live storefront.
- **Persist the remote id in the same breath as the call that mints it** — on the
  push rail AND on the restore rail, or a crash duplicates media on retry.
- **The gallery is read WHOLE or the push is refused.** A paginated read walks to
  `hasNextPage: false`; a gallery we cannot read to its end fails closed.
- **Leaving the storefront needs no approval.** The push machine's approval gate
  guards the way IN; undo (-> `not_pushed`) must always be able to close the loop, or
  a legal review move can brick the undo rail.
