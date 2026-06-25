# Component inventory

> Every component in both UI families. Each entry = **anatomy → variants → states →
> tokens consumed → i18n keys → data source**. Every component is described with
> **token names** (from [`design-tokens.md`](design-tokens.md)), never literals. Every
> string is an **i18n key** (catalog in [`i18n-catalog.md`](i18n-catalog.md)). A
> field whose backend source is unknown is marked `TODO-DATA` and is an open contract
> question, not a license to invent a column.

**The five mandatory states** every component declares (where applicable):
`default · hover · focus · disabled · loading · empty · error`. A widget result/
gallery surface additionally declares `success` and (result only) `low-quality-warn`.
A surface is not "done" until empty + loading + success + error are all written.

---

# Part A — Admin components (Filament; `admin-design-system` builds)

## A1. KPI card

- **Purpose:** surface one headline number on a dashboard (balance, generations today, conversion, leads).
- **Anatomy:** caption label · big value · optional delta/trend chip · optional sublabel.
- **Variants:** `value-only` · `value+delta` (▲/▼ vs prior period) · `value+trend` (sparkline slot).
- **States:**
  | State | Render |
  |---|---|
  | default | value + label |
  | loading | skeleton shimmer on the value line |
  | empty | value shows `—`, sublabel `states.no_data` |
  | error | value shows `!`, sublabel `states.load_failed`, retry affordance |
- **Tokens:** `--toa-surface`, `--toa-border`, `--toa-radius-card`, `--toa-shadow-card`, `--toa-type-kpi` (value), `--toa-type-caption` (label), delta uses `--to-success` / `--to-danger`.
- **i18n:** label keys per metric (`dashboard.kpi.*`), `states.no_data`, `states.load_failed`.
- **Data:** KPI aggregates — `TODO-DATA` (Q-KPI): exact aggregate shapes + period windows from `laravel-backend`.

## A2. Status badge (pill)

- **Purpose:** render any domain status with a consistent tone.
- **Variants (tone):** `success · warn · danger · neutral · info` — chosen by the §5 status map in `design-tokens.md`.
- **States:** static (no interaction); always carries text, never color-only (a11y).
- **Tokens:** `--to-r-pill`, the tone's color + bg from §5, `--toa-type-caption`.
- **i18n:** the status's key from the §5 map (`status.generation.*`, `status.ledger.*`, `status.lead.*`, `status.credit.*`, `scan.confidence.*`).
- **Data:** the status enum value from the owning model. **No status outside the state machines.**

## A3. Data table

- **Purpose:** dense list (generations, ledger, sites, products, leads, models).
- **Variants:** `default` · `with-status` (a badge column) · `with-row-actions` · `selectable` (bulk).
- **States:**
  | State | Render |
  |---|---|
  | default | rows; zebra via `--toa-surface-alt` |
  | hover | row highlight |
  | loading | skeleton rows |
  | empty (first-run) | empty-state block (A9), first-run copy |
  | empty (filtered) | empty-state block (A9), `states.no_results` + "clear filters" |
  | error | inline error row + retry |
- **Tokens:** `--toa-surface`, `--toa-surface-alt`, `--toa-border`, `--toa-type-body`, `--toa-type-caption` (header, uppercase tracked), sticky header at `--to-z-sticky`.
- **i18n:** column header keys per resource, `states.no_results`, `states.empty`, `actions.clear_filters`.
- **Data:** the resource's index query (account-scoped). RTL: column order flips; numeric/currency columns stay `text-align: end`.

## A4. Scan-review form (the ingestion heart)

- **Purpose:** let the merchant confirm or correct **every** scanned product field and **every** page selector before anything ships. **Nothing auto-approves.**
- **Anatomy (two groups):**
  - **Product fields:** title · price · description · variants (list) · physical dimensions.
  - **Page selectors:** add-to-cart · product image · title · price · variations.
- **Per row anatomy:** field label · **confidence chip** (high/medium/low/not-detected, A2 tones) · editable value control · for selectors: **detected CSS selector** (read-only display) + **manual selector entry** input + **element-pick** trigger ("pick on page") + per-selector **re-scan/test** action.
- **Per-row states:**
  | Row state | Render |
  |---|---|
  | detected-high | prefilled, chip `scan.confidence.high`, row collapsed/calm, ready |
  | detected-medium | prefilled, chip `scan.confidence.medium`, expanded, gentle "please confirm" |
  | detected-low | prefilled but flagged, chip `scan.confidence.low`, **must be reviewed** before confirm |
  | not-detected | empty, chip `scan.confidence.none`, **manual entry required** (selectors: manual selector or element-pick) |
  | editing | active input, underline focuses to `--to-ink` |
  | testing (selector) | selector test in flight (does it match an element on a sample fetch?) |
  | saving | row spinner |
  | error | field-level error + retry/re-scan |
- **Form-level states:** `scanning` (whole form is the scan-loading screen), `ready-to-confirm` (all low/not-detected rows reviewed → "Confirm product" enabled), `blocked` (some low/not-detected rows unreviewed → confirm disabled with a clear reason), `confirming`, `confirm-error`.
- **Tokens:** Filament fields use `--toa-radius-field`, `--toa-border`; chips use §5 tones; underline-on-focus uses `--to-bw-input-focus` / `--to-ink`.
- **i18n:** `scan.field.*` labels, `scan.confidence.*`, `scan.selector.manual`, `scan.selector.pick`, `scan.selector.test`, `scan.action.rescan`, `scan.action.confirm`, `scan.blocked.reason`, error keys under `scan.errors.*`.
- **Data:** scan result — `TODO-DATA` (Q-SCAN): per-field value + confidence level + detected selector string, from `pdp-scanner`. The selector-test contract (does this selector match on a re-fetch?) is also `pdp-scanner`. Element-pick is a `widget-embed`-adjacent bookmarklet/preview mechanism — confirm ownership.

## A5. Embed-code block

- **Purpose:** give the merchant the one-line script tag to install, carrying the site's `data-site-key`.
- **Anatomy:** mono code surface · copy button · regenerate-key action · install hint link.
- **Variants:** `ready` (copyable) · `regenerate-confirm`.
- **States:**
  | State | Render |
  |---|---|
  | default | code shown, "Copy" button |
  | copied | button → `embed.copied` for ~2s |
  | regenerate-confirm | destructive confirm: "the old key stops working" |
  | regenerating | spinner |
  | error | `embed.errors.regenerate` + retry |
- **Tokens:** mono surface uses `--toa-surface-alt`, `--toa-border`, `--toa-radius-control`; copy/regenerate buttons (A8).
- **i18n:** `embed.title`, `embed.copy`, `embed.copied`, `embed.regenerate`, `embed.regenerate_warning`, `embed.install_hint`, `embed.errors.*`.
- **Data:** `site_key` (public) + the embed URL. The `widget_secret` is **never shown** (encrypted at rest, server-only).

## A6. Leads table

- **Purpose:** the merchant's view of captured end-users (leads) per site.
- **Anatomy / columns:** full name · email · phone · status badge (A2, `end_user.status`) · tries used · last attempt (relative time).
- **States:** hover · loading · empty first-run (`leads.empty`) · filtered-empty (`states.no_results`) · error.
- **Tokens:** as A3.
- **i18n:** `leads.col.*` headers, `leads.empty`, status keys, `states.no_results`.
- **Data:** `EndUser` rows (site-scoped). Phone may be null (optional at signup — see flows Q-PHONE).

## A7. Lead card (detail)

- **Purpose:** one lead's profile + their full attempt history.
- **Anatomy:** header (name / email / phone / status) · **attempt history list** — each attempt: product title + variant · result thumbnail (signed URL) · succeeded/failed badge · timestamp · (optional) add-to-cart/purchased marker.
- **States:**
  | State | Render |
  |---|---|
  | default | header + history |
  | loading | header skeleton + history skeleton |
  | empty-history | "no attempts yet" (`leads.history.empty`) |
  | error (per attempt thumb) | broken-thumb placeholder, never a raw broken image |
  | error (card) | `states.load_failed` + retry |
- **Tokens:** thumbnails at `--to-r-card`; status badges A2; header type `--toa-type-h`.
- **i18n:** `leads.history.empty`, `leads.history.col.*`, status keys.
- **Data:** `EndUser` + their `Generation` rows + signed result URLs — `TODO-DATA` (Q-LEAD): exact attempt-history join shape + whether retention may have purged a thumbnail (then show `leads.history.purged`).

## A8. Buttons (CTA family)

- **Purpose:** all admin actions.
- **Variants:** `primary` (filled accent) · `secondary` (outline) · `destructive` (red outline → red fill on confirm) · `ghost` (text-only, low-emphasis).
- **States:** default · hover · focus (ring `--toa-focus`) · disabled · loading (spinner, label → `actions.working`) · confirm-step (destructive only).
- **Tokens:** `--toa-accent` / `--toa-accent-hover`, `--to-danger`, `--toa-radius-control`, `--toa-type-body`.
- **i18n:** per-action label keys under `actions.*`; `actions.working`; destructive confirm under `actions.confirm.*`.

## A9. Empty-state block

- **Purpose:** the friendly nothing-here surface.
- **Variants:** `first-run` (action CTA) · `filtered-no-results` (clear-filters CTA) · `error` (retry CTA).
- **States:** static; carries an illustration slot + headline + sub + CTA.
- **Tokens:** `--to-ink-muted` copy, illustration slot, CTA via A8.
- **i18n:** per-surface empty keys; shared `states.empty`, `states.no_results`, `states.load_failed`.

## A10. Low-credit / out-of-credit banner (merchant)

- **Purpose:** tell the merchant their account is low or empty and route to purchase.
- **Variants:** `warn` (balance ≤ per-site threshold; dismissible) · `danger` (empty; **persistent**, cannot dismiss).
- **States:** shown · dismissed (warn only) · CTA-loading (going to purchase).
- **Tokens:** warn `--to-warn` / `--to-warn-bg`; danger `--to-danger` / `--to-danger-bg`; pinned at `--to-z-sticky`.
- **i18n:** `merchant.credit.low`, `merchant.credit.empty`, `merchant.credit.buy_cta`, `actions.dismiss`.
- **Data:** account `balance_micro_usd`, `reserved_micro_usd`, per-site low threshold — `TODO-DATA` confirm threshold source (account vs site config).

## A11. Credit ledger view + buy-credits

- **Purpose:** show the immutable ledger + start a purchase.
- **Anatomy:** balance KPI (A1) · ledger table (A3, columns: date · type badge · amount ± · balance after · reference) · "Buy credits" CTA → amount picker → PayPlus redirect.
- **States:** default · loading · empty (`credits.ledger.empty` — fresh account shows only the opening `grant`) · purchase-pending (redirecting) · purchase-error · purchase-success (return-from-PayPlus confirmation).
- **Tokens:** A1 + A3 + A8.
- **i18n:** `credits.balance`, `credits.ledger.col.*`, `credits.buy.title`, `credits.buy.amount`, `credits.buy.confirm`, `credits.buy.pending`, `credits.buy.success`, `credits.buy.errors.*`.
- **Data:** `credit_ledger` rows (account-scoped, append-only) + PayPlus redirect — `TODO-DATA` (Q-PAY): purchase-init + return shape from `saas-credits-billing`. Amounts formatted via locale currency formatter, never hardcoded `$`.

## A12. Platform control-plane resources (Super-Admin only)

- **Purpose:** the DB-managed control plane — models, prompts, costs, AI operations, accounts, sites, credits. **Never hardcoded.**
- **Anatomy:** standard Filament resources (table A3 + form). Notable forms:
  - **AI model catalog:** model id · operation · default/fallback flags · cost hint.
  - **Prompt editor:** scope (`global`/`product_type`/`account`/`site`) · `operation_key` · `product_type` · system + user prompt (templated `{{placeholders}}`) · version · **a "preview resolved" panel** showing which prompt wins for a chosen `(site, product_type)` per the `site → account → product_type → global` order, and a **placeholder-substitution preview via `strtr`** (NOT Blade — RCE). **The preview is read-only and escaped.**
  - **AI operation config:** default/fallback model · image quality · aspect ratio · retention · estimated cost · `credit_multiplier` override.
  - **Account / site / credits admin:** balance, grant/adjustment actions (write `credit_ledger` rows), site config.
- **States:** standard CRUD (default/loading/empty/error) + **resolver-preview** states (resolving / resolved / no-match-fell-through-to-global / preview-error).
- **Tokens:** admin family; the prompt preview panel is a read-only mono surface (`--toa-surface-alt`).
- **i18n:** `platform.models.*`, `platform.prompts.*`, `platform.operations.*`, `platform.accounts.*`, `platform.sites.*`, `platform.credits.*`, `platform.resolver.*`.
- **Data:** the control-plane tables — `TODO-DATA` (Q-RESOLVE): the resolver-preview contract (given `(operation, site, product_type)` return the winning prompt/model + the resolution trace) from `ai-openrouter`. **Prompts are NOT i18n keys** — they are `strtr`-substituted DB text, a separate system from the `__()` catalog.

---

# Part B — Widget components (storefront; `widget-embed` builds; premium/Tabuzzco)

> The widget lives on a stranger's PDP. It renders inside an isolated root (Shadow
> DOM / prefixed namespace) so host CSS can't bleed in. It **adapts color + width**
> to belong and **locks type/corner/shadow/motion** to feel like Tray On (§4 of
> `design-tokens.md`). It must stay **< 20 KB gzipped** and never block host
> render/LCP/CLS.

## B1. Injected Tray On button

- **Purpose:** the entry point — injected directly under the host "Add to cart".
- **Anatomy:** label (`widget.button.label` = "Tray On") · optional small mark.
- **Variants:** `outline` (default, Tabuzzco) · `filled` (only if a merchant/host accent is set).
- **States:**
  | State | Render |
  |---|---|
  | default | sharp-corner outline, width matched to host add-to-cart |
  | hover/focus | slow invert (fill `--tow-accent`, text `--tow-accent-on`); **no scale** |
  | disabled | low-emphasis; used when the widget can't initialize (no config) |
  | loading | brief during init; never a flash of broken button |
  | hidden | site mis-configured / no add-to-cart found → **fail silent**, render nothing (never a broken/empty button) |
- **Tokens:** `--tow-type-button`, `--tow-radius-button` (sharp), `--tow-accent`, `--tow-t-button`, `--tow-btn-w` (inherited), `--to-z-widget-btn`.
- **i18n:** `widget.button.label`, `widget.button.loading`.
- **Data:** the add-to-cart selector (from the confirmed scan) tells the widget where to inject + what width to match. **CLS:** the button reserves its space so injecting it does not shift layout.

## B2. Modal shell

- **Purpose:** the container for the whole try-on experience.
- **Variants:** `centered` (desktop, max `--tow-modal-w`) · `sheet` (mobile, bottom sheet).
- **States:** `enter` (slow fade + rise, `--tow-t-modal`) · `open` · `closing` · `error-boundary` (something inside crashed → graceful `widget.errors.generic` + close, never a host-visible JS error).
- **Anatomy:** scrim (`--tow-overlay`, `--to-z-overlay`) · shell (`--tow-surface`, `--to-shadow-soft`, `--tow-modal-radius`, `--to-z-modal`) · eyebrow header ("TRAY ON") · close affordance · body slot (steps B3–B12) · footer CTA slot.
- **Tokens:** as listed; eyebrow `--tow-type-eyebrow`.
- **i18n:** `widget.modal.title`, `widget.modal.close`, `widget.errors.generic`.
- **Behavior:** focus trap, `Esc` closes, scroll-lock on host, restores focus to the button on close. RTL: close affordance + back arrows mirror.

## B3. Upload dropzone

- **Purpose:** collect the shopper's photo.
- **Anatomy:** dashed dropzone · prompt copy · file-picker trigger · preview thumb · remove/replace.
- **States:**
  | State | Render |
  |---|---|
  | empty | dashed `--to-rule`, prompt `widget.upload.prompt` + accepted types/size |
  | drag-over | emphasized border |
  | uploading | progress, `widget.upload.uploading` |
  | with-preview | image at `--tow-radius-image`, replace/remove actions |
  | invalid | `widget.upload.errors.type` / `widget.upload.errors.size` |
  | error | `widget.upload.errors.failed` + retry |
- **Tokens:** dashed `--to-rule`, preview `--tow-radius-image`, copy `--tow-type-body` / `--tow-type-caption`.
- **i18n:** `widget.upload.*`.
- **Data:** accepted MIME + max size — `TODO-DATA` (Q-UPLOAD): limits from `laravel-backend` (and whether a face/full-body hint is required).

## B4. Height input (+ optional attributes)

- **Purpose:** collect height (required) and optional body/age/gender/angle to improve the generation.
- **Anatomy:** underline height field with cm/in toggle · collapsed "Add details (optional)" → body · age · gender · angle selects.
- **States:** empty · filled · focus (underline → `--to-ink`, `--to-bw-input-focus`) · invalid (`widget.height.errors.range`) · optional-collapsed · optional-expanded.
- **Tokens:** underline pattern (`--to-rule` → `--to-ink`), `--tow-type-body`.
- **i18n:** `widget.height.label`, `widget.height.unit_cm`, `widget.height.unit_in`, `widget.height.errors.range`, `widget.details.toggle`, `widget.details.body`, `widget.details.age`, `widget.details.gender`, `widget.details.angle` + their option keys.
- **Data:** valid height range + the option enumerations — `TODO-DATA`: confirm allowed ranges/enums with `laravel-backend` (drive the generation input schema, `ai_operations.input_schema`). RTL: cm/in toggle sits at the inline-end; placement mirrors.

## B5. Consent block (required)

- **Purpose:** explicit, specific consent before any photo is used. **Vague consent is a release blocker.**
- **Anatomy:** required checkbox · **explicit disclosure copy** (not "I agree" — states *what for*) · privacy link · short retention note.
- **States:** unchecked (generate CTA **disabled**) · checked (CTA enabled) · error (tried to submit unchecked → `widget.consent.required`).
- **Tokens:** `--tow-type-caption`, link underline, checkbox uses `--tow-accent`.
- **i18n (explicit copy):** `widget.consent.photo` ("I agree to let Tray On use my photo to generate a virtual try-on of this product."), `widget.consent.privacy_link` ("How we handle your photo"), `widget.consent.retention` (plain-language retention), `widget.consent.required`.
- **Data:** retention period text from the per-site retention policy (7/30/90/until-delete) — `TODO-DATA`: confirm the copy reflects what `saas-credits-billing`/`laravel-backend` actually enforce. **Never promise behavior the backend doesn't enforce.**

## B6. Loading state (generation)

- **Purpose:** reassure during the OpenRouter call.
- **Anatomy:** full-canvas shimmer at `--tow-result-aspect` · reassuring copy · slow indeterminate progress · cancel affordance.
- **States:** `generating` (`widget.loading.title` + reassurance) · `timeout` (→ error with retry, shopper **not billed**) · `cancelled` (→ back to B3/B4 step).
- **Tokens:** `--tow-radius-image`, `--to-t-slow`, `--tow-type-body`.
- **i18n:** `widget.loading.title`, `widget.loading.sub`, `widget.loading.cancel`, `widget.loading.timeout`.
- **Behavior:** the widget polls generation status (`pending → processing → succeeded/failed`); copy never exposes raw status codes.

## B7. Result canvas

- **Purpose:** show the try-on. **This is where the product is judged.**
- **Anatomy:** result image at `--tow-result-aspect`, `--tow-radius-image`, `--to-shadow-card` · action bar (B8).
- **States (all three required):**
  | State | Render |
  |---|---|
  | success | the try-on image + full action bar |
  | low-quality-warn | image rendered but flagged (model returned a likely-off result) → gentle `widget.result.low_quality` + emphasized "Try again", **shopper not blamed** |
  | error | generation failed → `widget.result.error`, retry CTA, **shopper not billed, not blamed**, never a raw 500/stack trace |
- **Tokens:** image `--tow-radius-image`, `--to-shadow-card`, reveal `--to-t-slow`.
- **i18n:** `widget.result.title`, `widget.result.low_quality`, `widget.result.error`, `widget.errors.*`.
- **Data:** signed result image URL + an optional low-quality signal — `TODO-DATA` (Q-RESULT): does the pipeline emit a quality/confidence signal, or is "low-quality" purely a shopper-initiated regenerate? Confirm with `laravel-backend`/`ai-openrouter`.

## B8. Result action bar

- **Purpose:** what the shopper does with a result.
- **Actions:** `regenerate` · `change photo` · `change height` · **`add-to-cart` (selected variant)** (primary) · `back to product`.
- **Per-action states:** default · hover · loading · disabled.
- **Tokens:** sharp CTAs (`--tow-radius-button`); primary (add-to-cart) uses `--tow-accent`; others outline.
- **i18n:** `widget.result.regenerate`, `widget.result.change_photo`, `widget.result.change_height`, `widget.result.add_to_cart`, `widget.result.back`, `widget.cart.added` (toast).
- **Behavior decisions (resolved in flows §1, see Q-PRESERVE):** "change photo" returns to B3 **preserving height + consent**; "change height" returns to B4 **preserving photo + consent**; "regenerate" re-runs with the **same inputs** (and re-checks both gates — a regenerate is a new generation, a new credit reservation). `add-to-cart` adds the **exact selected variant** via the host cart and shows the `widget.cart.added` toast (`--to-z-toast`).

## B9. Gallery slider

- **Purpose:** the session's generations, horizontally scrollable.
- **States (all required):**
  | State | Render |
  |---|---|
  | empty (first run) | quiet `widget.gallery.empty` ("Your try-ons will appear here") — **not** a broken grid |
  | one-item | single tile, no broken slider chrome |
  | many | horizontal scroll, slow `--to-t-base` |
  | loading | tile skeletons |
  | error | `widget.gallery.error` + retry, never broken thumbs |
- **Tokens:** tiles `--to-r-card`, `--to-shadow-card`, `--tow-tile-w`; slow scroll.
- **i18n:** `widget.gallery.title`, `widget.gallery.empty`, `widget.gallery.error`.
- **Data:** the session's `Generation` rows + signed thumbnails — `TODO-DATA` (Q-GALLERY): does the gallery survive a page reload? Tied to `EndUser` anon-token server-side persistence (`(site_id, anon_token)` per ARCHITECTURE.md lead gate). Default assumption: **yes, it persists** within the per-site retention window; confirm with `laravel-backend`. RTL: slider scroll direction flips (start = right).

## B10. Gallery tile actions

- **Purpose:** act on one past generation.
- **Actions:** `open full-size` · `add-to-cart` · `regenerate` · `delete` (confirm) · `back to product`.
- **States:** hover-reveal actions · open (lightbox) · deleting (confirm → removing) · error.
- **Tokens:** overlay actions, destructive confirm uses `--to-danger`.
- **i18n:** `widget.gallery.open`, `widget.gallery.add_to_cart`, `widget.gallery.regenerate`, `widget.gallery.delete`, `widget.gallery.delete_confirm`.
- **Data:** delete writes to the generation's media (respecting retention/privacy) — confirm shopper-initiated delete is allowed + how it interacts with the lead's attempt history.

## B11. Free-tries chip

- **Purpose:** the lead-gate nudge — how many free generations remain, and **what happens next**.
- **Variants:** `counting` ("X free tries left") · `last-try` (warn tone, "this is your last free try") · `exhausted` (→ routes to signup B12).
- **States:** counting · last-try (warn) · exhausted.
- **Tokens:** `--tow-radius-chip`, last-try uses `--to-warn` / `--to-warn-bg`, `--tow-type-caption`.
- **i18n (must state the consequence):** `widget.tries.left` (`trans_choice`, `:count`), `widget.tries.last` ("Last free try — after this, a quick sign-up keeps you going"), `widget.tries.exhausted_title`, `widget.tries.exhausted_body`.
- **Data:** per-site `free_generations_before_signup` (default 2; `0` = signup first; `null` = never) + the end-user's used count (per `(site_id, anon_token)`).

## B12. Lead-signup screen

- **Purpose:** capture the lead when free tries are exhausted (or before the first try if the limit is `0`).
- **Anatomy:** underline form — **full name · email · phone** · short "why we ask" · consent · submit.
- **States:** empty · validating (per-field) · submitting · error (`widget.signup.errors.email_taken` / `…network`) · success → continue.
- **Tokens:** underline form, sharp submit (`--tow-radius-button`), `--tow-accent`.
- **i18n:** `widget.signup.title`, `widget.signup.name`, `widget.signup.email`, `widget.signup.phone`, `widget.signup.phone_optional`, `widget.signup.why`, `widget.signup.consent`, `widget.signup.submit`, `widget.signup.errors.*`, `widget.signup.success`.
- **Data:** writes an `EndUser` (lead). **Phone optionality:** spec'd **required by default**, but flagged Q-PHONE (merchant-configurable per site is the likely answer — see flows). The lead gate and credit gate are **independent** (a merchant-out-of-credits shopper sees B13, not this form).

## B13. Out-of-credit screen (shopper view)

- **Purpose:** graceful unavailable state when the *merchant* has no credits — the shopper is **never blamed**, never sees "merchant out of money", never a 500.
- **Anatomy:** calm title + body + (optional) "back to product".
- **States:** static (no shopper action that can fix it).
- **Tokens:** neutral; `--tow-type-body`.
- **i18n:** `widget.unavailable.title`, `widget.unavailable.body`, `widget.result.back`.
- **Data:** triggered when `CreditGate` denies (a typed `CreditDenied`, never a 500). Independent from the lead gate.

---

## State-coverage matrix (the "is it done?" grid)

A component is build-ready only when every applicable cell is written. `n/a` = state doesn't apply.

| Component | default | hover | focus | disabled | loading | empty | error | success | low-qual |
|---|---|---|---|---|---|---|---|---|---|
| A1 KPI card | ● | n/a | n/a | n/a | ● | ● | ● | n/a | n/a |
| A2 Status badge | ● | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |
| A3 Data table | ● | ● | ● | n/a | ● | ● | ● | n/a | n/a |
| A4 Scan-review form | ● | n/a | ● | ● | ● | ● | ● | ● | n/a |
| A5 Embed-code | ● | ● | ● | n/a | ● | n/a | ● | ● | n/a |
| A6 Leads table | ● | ● | ● | n/a | ● | ● | ● | n/a | n/a |
| A7 Lead card | ● | n/a | n/a | n/a | ● | ● | ● | n/a | n/a |
| A8 Buttons | ● | ● | ● | ● | ● | n/a | ● | n/a | n/a |
| A9 Empty-state | ● | n/a | n/a | n/a | n/a | ● | ● | n/a | n/a |
| A10 Credit banner | ● | ● | ● | n/a | ● | n/a | ● | n/a | n/a |
| A11 Ledger + buy | ● | ● | ● | ● | ● | ● | ● | ● | n/a |
| A12 Control plane | ● | ● | ● | ● | ● | ● | ● | ● | n/a |
| B1 Injected button | ● | ● | ● | ● | ● | n/a (hidden) | n/a | n/a | n/a |
| B2 Modal shell | ● | n/a | ● | n/a | ● | n/a | ● | n/a | n/a |
| B3 Upload | ● | ● | ● | n/a | ● | ● | n/a | ● | n/a |
| B4 Height/details | ● | n/a | ● | n/a | n/a | ● | ● | n/a | n/a |
| B5 Consent | ● | n/a | ● | ● | n/a | n/a | ● | n/a | n/a |
| B6 Loading | n/a | n/a | n/a | n/a | ● | n/a | ● | n/a | n/a |
| B7 Result canvas | ● | n/a | n/a | n/a | ● | n/a | ● | ● | ● |
| B8 Action bar | ● | ● | ● | ● | ● | n/a | ● | n/a | n/a |
| B9 Gallery | ● | ● | ● | n/a | ● | ● | ● | ● | n/a |
| B10 Tile actions | ● | ● | ● | n/a | ● | n/a | ● | n/a | n/a |
| B11 Free-tries chip | ● | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |
| B12 Lead-signup | ● | ● | ● | ● | ● | ● | ● | ● | n/a |
| B13 Out-of-credit | ● | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |
