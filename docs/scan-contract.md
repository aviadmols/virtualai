# PDP Scan Contract (Phase 4 — pdp-scanner)

> The backend contract for the PDP-ingestion pillar. **pdp-scanner** produces
> structured product data + verified page selectors + per-field confidence;
> **laravel-backend** persists; **admin-design-system** (Phase 8) renders the
> confirm/correct UI from this shape; **widget-embed** (Phase 7) consumes the
> *confirmed* selectors at runtime. A scan **never auto-approves** — a product
> reaches the storefront only at `status = confirmed`, set by the merchant's
> `confirm()`.

## The two entry methods (what the job calls)

`App\Domain\Scan\PdpScanner`:

- `represent(string $url): PageRepresentation` — fetch (HTTP-first, headless
  fallback) → clean/trim → lift JSON-LD/OG/microdata → candidate hints. **No model
  call.** Throws a typed, merchant-facing `FetchException` on refusal/failure.
- `extract(PageRepresentation $r, ?Site $site, ?string $productType, array $vars):
  MappedProduct` — resolve the `product_scan` bag from `AiOperationResolver`
  (model + prompt + schema from the DB, never hardcoded) → `ProductScanCaller`
  (ai-openrouter owns the model call) → map strict JSON into the bag.

`App\Domain\Scan\ScanProductJob` (extends `TenantAwareJob`, `implements
ShouldBeUnique`) orchestrates both and persists. Construct it with
`new ScanProductJob($accountId, $siteId, $url)`. Unique by the locked key
`scan:{account_id}:{site_id}:{sha1(url)}` (`ScanProductJob::scanKey(...)`).

## The persisted shape

`products` (tenant-owned: `account_id` + `BelongsToAccount`, site-scoped):
`status (draft|confirmed|failed)`, `name`, `description`, `product_type`,
`price_minor` (integer minor units — never a float) + `currency` (ISO) +
`sale_price_minor`/`regular_price_minor` + `price_is_range`, `main_image_url`,
`images` (json), `physical_dimensions` (json), `field_confidence` (json: field →
`{value, confidence, source}`), `detected_selectors` (json: role → selector),
`scan_raw` (json: model JSON + provenance), `fetched_via`, `warnings`,
`confidence` (overall aggregate).

`product_variants` (tenant-owned): `options` (json `{axis: value}`), `price_minor`,
`image_url`, `sku`, `available`, `confidence`.

## Per-field confidence + source

Every field carries `{value, confidence, source}`. `source` ∈ `jsonld`, `og`,
`microdata`, `dom`, `screenshot`, `model_inferred`. `model_inferred` is the
lowest-trust source and is **always flagged for review**. The overall `confidence`
is the weighted aggregate the threshold reads — laravel-backend fails the scan
below `ScanConstants::CONFIDENCE_THRESHOLD`; the review queue flags any field /
selector under `ScanConstants::REVIEW_FLOOR`.

## The six selector roles

`add_to_cart`, `product_image`, `title`, `price`, `description`, `variations`.
Each `detected_selectors[role]` is:

```
{ primary, fallback_chain[], confidence, matched_count, strategy, needs_review }
```

- **Stable over brittle:** `id` > `data-*` > `aria-*` > `itemprop` > semantic >
  class > positional. A positional-only selector is `needs_review = true` by
  default even when it currently resolves to one element.
- **Count-verified:** the primary resolves to **exactly one** element in the
  fetched DOM, else `needs_review = true` (`matched_count` reports 0 or N). The
  `add_to_cart` multi-match (sticky + modal + mobile + desktop) is flagged, never
  silently the first hit.
- **Fallback chain:** 2–3 distinct resolvers so widget-embed degrades down the
  chain at runtime instead of failing when the primary breaks.

## The confirm/correct contract (Phase 8 UI source of truth)

`App\Domain\Scan\Contract\ScanContract::forProduct($product)` returns the bag the
review UI renders:

- `fields` — every field editable, pre-filled with `{value, confidence, source,
  needs_review}`.
- `selectors` — per role: the detected primary + fallback chain + match count +
  `manual_override: true` (the merchant may type a raw CSS selector or class).
- `variants` — every variant editable.
- `element_pick_shape` — the payload the picker POSTs back:
  `{ role, css_path, suggested_selectors[], tag, attributes{id,data-*,aria-*,class},
  text_sample, bounding_box }`. **admin-design-system builds the picker UI; the
  payload shape + the verification are pdp-scanner's.**
- `actions.confirm` / `actions.rescan`.

### Manual-selector live re-verify

`App\Domain\Scan\Contract\SelectorReverifier::verify($url, $selectors)` (or
`verifyAgainstDom($dom, $selectors)`) returns, per selector,
`{ selector, matched_count, resolves_to_one, strategy }` so the UI gives immediate
"resolves to 1 / 0 / N elements" feedback on a typed selector.

### Confirm — the only path live

`$product->confirm($corrections = [])` applies the merchant's corrected fields /
selectors and transitions `draft → confirmed` (guarded; a non-draft product cannot
be confirmed). `confirmed_at` is stamped. **This is the only transition that makes a
product generation-eligible.** widget-embed reads `detected_selectors` (now
`confirmed`) at runtime.

### Re-scan

A re-scan re-dispatches `ScanProductJob`. The job updates an existing
`draft`/`failed` product for the same URL but **never overwrites a `confirmed`
product** — a re-scan over a confirmed product is an explicit, diff-presented
action owned above the job (Phase 8 UI). The idempotency key collapses an
accidental double-dispatch.

## Failure path (never a 500, never a silent empty scan)

A typed `FetchException` (`robots_blocked`, `bot_blocked`, `render_empty`,
`timeout`, `invalid_url`, `render_disabled`, ...) persists a `failed` product
carrying `warnings = { reason, message, suggest_manual }` where `message` is
merchant-facing copy and `suggest_manual = true`. The confirm/correct contract
works with zero pre-fill, so the merchant always gets the manual-entry path.

## SSRF + politeness

`UrlGuard` rejects non-public hosts (`localhost`, private/loopback/link-local IPs,
`169.254.169.254`) before any fetch. `RobotsPolicy` respects `robots.txt`
(`SCRAPER_RESPECT_ROBOTS`, kill-switch only for a merchant's own verified domain).
Honest UA (`SCRAPER_USER_AGENT`), bounded timeouts, byte cap (`SCRAPER_MAX_BYTES`).

## The `SCRAPER_*` env (handed to railway-infra)

| Env | Purpose |
|---|---|
| `SCRAPER_SERVICE_URL` / `SCRAPER_SERVICE_TOKEN` | Headless render sidecar (Playwright/Chromium) endpoint + secret. Null ⇒ HTTP-only. |
| `SCRAPER_RENDER_ENABLED` | Feature-flag the headless path. |
| `SCRAPER_USER_AGENT` | Honest identifying UA for both fetches. |
| `SCRAPER_HTTP_TIMEOUT` / `SCRAPER_RENDER_TIMEOUT` | Bounded timeouts (s). |
| `SCRAPER_MAX_BYTES` | Page byte cap (OOM guard). |
| `SCRAPER_RESPECT_ROBOTS` | Default true. |
| `SCRAPER_MAX_REDIRECTS` | Bounded redirect chain. |

**Recommended Q3 default:** a headless Playwright (Chromium) render sidecar
railway-infra hosts, called over HTTP from the worker. A managed render API
(Browserless / ScrapingBee) is the swap-in alternative behind the same interface.
