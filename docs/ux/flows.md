# End-to-end flows — every state

> The product is its flows, and the flows are judged on their **failure and result
> states**, not the happy path. Every screen here lists all applicable states
> (empty/loading/success/error, plus the widget result's low-quality state). Surfaces
> reference components from [`component-inventory.md`](component-inventory.md) and
> strings from [`i18n-catalog.md`](i18n-catalog.md). The two gates — **CreditGate**
> (merchant has credits) and **LeadGate** (end-user under the free limit or
> registered) — are **independent**; both must pass and they never collapse into one
> (ARCHITECTURE.md).

Legend for state tables: each step lists its **states** and the **next** transition.

---

## Flow 1 — Try-on (the shopper's journey)

`open → upload + height + consent → [CreditGate ∧ LeadGate] → generating → result →
add-to-cart / regenerate / change / back → gallery`

### 1.1 See the button (B1)

| State | Render | Next |
|---|---|---|
| visible/default | sharp outline button under host add-to-cart, width-matched | click → 1.2 |
| loading (init) | brief; reserves its space (no CLS) | → visible |
| hidden (mis-config / no add-to-cart found) | **render nothing** — never a broken button | terminal (fail silent) |

### 1.2 Open modal (B2)

| State | Render | Next |
|---|---|---|
| enter | scrim + slow fade/rise (`--tow-t-modal`), focus trap, scroll-lock | → 1.3 |
| open | eyebrow header "TRAY ON", body = 1.3 | — |
| closing | reverse animation, restore focus to button | terminal |
| error-boundary | graceful `widget.errors.generic`, close cleanly | terminal |

### 1.3 Collect inputs (B3 upload · B4 height/details · B5 consent)

Generate CTA is **disabled** until: photo present **AND** height valid **AND** consent
checked. The disabled CTA's helper makes clear *which* requirement is missing.

| Sub-state | Render | Next |
|---|---|---|
| empty | dropzone prompt, empty height, consent unchecked, CTA disabled | — |
| partially-filled | CTA disabled; helper names the missing piece (`widget.cta.need_photo` / `need_height` / `need_consent`) | — |
| invalid | per-field error (wrong file type/size, height out of range, consent untouched on submit attempt) | fix → ready |
| ready | photo + valid height + consent → CTA `widget.cta.generate` enabled | submit → **1.4 gate** |

> **Q-PRESERVE (resolved):** returning here via "change photo" preserves height +
> consent; via "change height" preserves photo + consent. Only the changed input
> resets. Confirmed as the spec default; no contract conflict.

### 1.4 The two gates (server-side; the widget just renders the outcome)

On submit the backend evaluates **both** gates. The widget renders whichever blocks
first; if both pass it proceeds to 1.5.

| Gate outcome | Widget renders | Next |
|---|---|---|
| both pass | (nothing — proceeds) | → 1.5 generating |
| **LeadGate** blocks (free tries exhausted, not registered) | the lead-signup screen (Flow 2, B12) | signup → resume at 1.5 |
| **CreditGate** blocks (merchant out of credits — typed `CreditDenied`) | out-of-credit screen (B13) — shopper not blamed | terminal (graceful) |
| both block | **LeadGate is shown first** if the user could still register; but if the merchant has no credits, registering won't help — show B13 (the credit wall is the harder stop). See decision note below. |

> **Gate-precedence decision:** when *both* gates would block, show the **out-of-credit
> screen (B13)** — registering past the lead gate cannot produce a generation the
> merchant can't pay for, so prompting signup would be a dead end. The free-tries chip
> (B11) still reflects the lead state, but the actionable screen is B13. (This is a
> product call, not a contract conflict; logged as a resolved decision, not Q-blocking.)

### 1.5 Generating (B6)

A regenerate is a **new generation**: new reservation, new idempotency key
(`generation:…:{client_request_id}`), re-checks both gates. The shopper is **never
billed on failure** (release on failure, no `charge` row — ARCHITECTURE.md money path).

| State | Render | Next |
|---|---|---|
| generating | full-canvas shimmer + reassurance + slow progress + cancel | poll → success/failed |
| timeout | `widget.loading.timeout`, offer retry (not billed) | retry → generating / back → 1.3 |
| cancelled | reservation released, no charge | → back to 1.3 |
| succeeded | → 1.6 | — |
| failed | → 1.6 error | — |

### 1.6 Result (B7 + B8)

| State | Render | Next |
|---|---|---|
| **success** | try-on image + action bar | any action below |
| **low-quality-warn** | image + gentle `widget.result.low_quality` + emphasized "Try again" | regenerate → 1.5 |
| **error** | `widget.result.error` + retry; **not billed, not blamed**, no raw 500 | retry → 1.5 / back → 1.3 |

Action bar (B8):

| Action | Effect | Next |
|---|---|---|
| regenerate | new generation, same inputs, both gates re-checked | → 1.5 |
| change photo | back to B3, **height + consent preserved** | → 1.3 |
| change height | back to B4, **photo + consent preserved** | → 1.3 |
| **add-to-cart** | add the **exact selected variant** to the host cart, toast `widget.cart.added`, set `end_user.status → added_to_cart` | stay → 1.7 visible |
| back to product | close modal, restore PDP | terminal |

Every successful result appends to the gallery (1.7).

### 1.7 Gallery (B9 + B10)

| State | Render | Next |
|---|---|---|
| empty (first run) | quiet `widget.gallery.empty` — not a broken grid | — |
| one-item | single tile, no broken slider chrome | tile actions |
| many | slow horizontal slider | tile actions |
| loading | tile skeletons | → resolved |
| error | `widget.gallery.error` + retry | retry |

Tile actions (B10): open full-size · add-to-cart · regenerate (→ 1.5) · delete (confirm)
· back to product.

> **Q-GALLERY (default assumed, confirm with `laravel-backend`):** the gallery
> **persists across page reload and across PDPs** within the per-site retention
> window, keyed by `(site_id, anon_token)` (the same anon-token the lead gate uses).
> If `laravel-backend` cannot persist anonymous galleries, the fallback is a
> session-only gallery that clears on reload — spec'd but not preferred.

---

## Flow 2 — Lead gate (free tries → exhausted → signup → grant)

`free tries countdown → exhausted (or limit 0) → signup → continue → post-signup grant`

Driven by per-site `free_generations_before_signup`: default **2**; `0` = signup
**before** the first try; `null` = **never** require signup.

### 2.1 Free-tries nudge (B11)

| State | Render | Next |
|---|---|---|
| counting | chip `widget.tries.left` (`:count`) shown near the generate CTA | each successful try decrements |
| last-try | warn-tone chip `widget.tries.last` — **states the consequence** ("after this, a quick sign-up keeps you going") | next try → exhausted |
| exhausted | on the next generate attempt, LeadGate blocks → 2.2 | → 2.2 |

> If `free_generations_before_signup = 0`, the very first generate attempt routes
> straight to 2.2 before any try. If `null`, the chip never shows and 2.2 never fires.

### 2.2 Signup screen (B12)

| State | Render | Next |
|---|---|---|
| empty | underline form (name / email / phone) + "why we ask" + consent | fill |
| validating | per-field validation | — |
| submitting | spinner | → success / error |
| error | `widget.signup.errors.email_taken` / `…network` — human copy, retry | retry |
| success | lead written (`EndUser`), `end_user.status` stays/advances per events | → 2.3 |

> **Q-PHONE (resolved as spec default):** phone is **required by default** but the
> field is built to support a per-site "phone optional" config
> (`widget.signup.phone_optional` copy variant exists). The merchant-configurable
> toggle is the likely backend answer; until `laravel-backend` confirms the column,
> the widget treats phone as required and reads an optional flag if present. Not
> blocking — both copy variants are catalogued.

### 2.3 Post-signup continuation

The shopper resumes **exactly** where they were (the generation they were attempting),
reflecting any merchant `post_signup_grant`.

| State | Render | Next |
|---|---|---|
| continuing | resume the pending try-on (back into 1.5 with the same inputs) | → 1.5 |
| granted (N extra / unlimited) | chip updates to the new allowance (`widget.tries.left` or an "unlimited" variant) | continue |
| gated (merchant gates post-signup) | clear, friendly `widget.tries.gated` ("thanks — try-ons are limited") | terminal-friendly |

> The lead gate and credit gate stay **independent**: if the merchant is out of
> credits at 2.3, the shopper sees the out-of-credit screen (B13), **not** a second
> signup prompt. Registering never produces a generation the merchant can't pay for.

---

## Flow 3 — Merchant scan-review / correct (PDP ingestion)

`add site → paste URL → scan → per-field + per-selector confirm/correct → manual
selector / element-pick where needed → confirm product → embed code → first generation
works`

**Law: nothing ships until confirmed. Never auto-approve.** A low-confidence or
not-detected field/selector must be reviewed before "Confirm product" enables.

### 3.0 Onboarding / first login

| State | Render | Next |
|---|---|---|
| first-run | welcome + opening-credit notice ("$5 to start") + "Add your first site" CTA | → 3.1 |
| returning | merchant dashboard (KPIs A1, sites list) | → 3.1 or manage |
| error | `states.load_failed` + retry | retry |

### 3.1 Add site

| State | Render | Next |
|---|---|---|
| empty | form: domain · display name · allow-listed origin(s) | fill |
| validating | domain format check | — |
| saving | spinner | → success / error |
| error | duplicate domain / invalid format (`sites.errors.*`) | fix |
| success | site created → site dashboard | → 3.2 |

### 3.2 Paste product URL

| State | Render | Next |
|---|---|---|
| empty | URL input + "Scan" CTA | submit |
| scanning | the scan-loading screen ("Reading your product page…") | → review / error |
| error | URL unreachable / not a PDP / scan failed (`scan.errors.*`) + retry | retry |
| success | → 3.3 scan-review | — |

> **Q-SCAN (data-pending):** the scan result shape — per-field value + confidence
> level (`high`/`medium`/`low`/`not_detected`) + detected selector string — is the
> contract `pdp-scanner` must supply. The form (A4) is fully specced against this
> shape; it is `data-pending` until the scanner confirms the field list + confidence
> bucketing.

### 3.3 Scan-review / correct (A4 — the heart)

Two groups: **product fields** (title, price, description, variants, dimensions) and
**page selectors** (add-to-cart, product image, title, price, variations). Each row
carries a confidence chip + editable value; selectors add detected-selector display +
manual-selector entry + element-pick + per-selector re-scan/test.

| Form state | Render | Confirm enabled? |
|---|---|---|
| detected-high (all rows high) | mostly prefilled, calm; ready | **yes** |
| detected-medium (some medium) | prefilled, "please confirm" prompts | yes (review encouraged) |
| detected-low (some low) | low rows flagged, **must be reviewed** | **no** until reviewed (`scan.blocked.reason`) |
| not-detected (some missing) | missing rows require manual value / manual selector / element-pick | **no** until filled |
| testing (selector) | a selector is being tested against a re-fetch | — |
| saving / confirming | spinner | — |
| error | field-level error + re-scan (`scan.errors.*`) | — |

Row-level states are in A4. **Action:** `scan.action.confirm` ("Confirm product") —
only enabled when no low/not-detected row is unreviewed.

### 3.4 Get embed code (A5)

| State | Render | Next |
|---|---|---|
| ready | one-line `<script … data-site-key="…">` + Copy | copy → copied |
| copied | "Copied" for ~2s | — |
| regenerate-confirm | destructive confirm: old key stops working | confirm → regenerating |
| regenerating | spinner | → ready (new key) / error |
| error | `embed.errors.regenerate` + retry | retry |

### 3.5 First generation works

| State | Render | Next |
|---|---|---|
| not-yet-tested | "Test on a live PDP" prompt / checklist item | test |
| success | first generation recorded → onboarding checklist complete | done |
| error | widget didn't load → troubleshooting link (`scan.firstgen.error`) | retry |

---

## Host-adaptation contract (widget — recap, authoritative copy in `design-tokens.md` §4)

| Property | Inherit / Lock / Adapt | Note |
|---|---|---|
| Button width | **Inherit** | match host add-to-cart |
| Button corner / style | **Lock** | sharp corner, slow invert |
| Accent color | **Adapt** | merchant/host brand → `--tow-accent`; default ink; never clash |
| Body font | **Adapt** | Heebo for display moments; host font fallback for inherited chrome |
| Modal display moments | **Lock** | mixed-weight Heebo, slow reveal |
| Image radius / shadow | **Lock** | 7px + layered soft shadow |
| Motion | **Lock** | slow 0.5s |
| RTL | **Adapt** | flip via logical properties |

---

## Open product / contract questions (deferred — default stated, not blocking)

These are forks the contract does not fully resolve. Each has a **stated spec default**
so the build is not blocked; each is flagged for the owning agent to confirm. None
require a redesign — only a confirmation.

| ID | Question | Spec default (used until confirmed) | Owner |
|---|---|---|---|
| **Q-SCAN** | Exact scan-result shape: field list, confidence levels, detected selector strings, selector-test contract. | A4 specced against {value, confidence∈high/med/low/none, selector}. | `pdp-scanner` |
| **Q-RESULT** | Does the pipeline emit a low-quality/confidence signal, or is "low-quality" purely shopper-initiated regenerate? Result aspect ratio source. | Render both: a backend low-quality flag if present, plus always-available regenerate. Aspect from `ai_operations.aspect_ratio`, default 3:4. | `ai-openrouter`, `laravel-backend` |
| **Q-GALLERY** | Does the anonymous gallery persist across reload/PDPs? | Yes — persists within retention window keyed by `(site_id, anon_token)`. Fallback: session-only. | `laravel-backend` |
| **Q-PHONE** | Is phone required at signup, or merchant-configurable per site? | Required by default; optional-copy variant catalogued for a per-site flag. | `laravel-backend`, `saas-credits-billing` |
| **Q-KPI** | Exact dashboard KPI aggregate shapes + period windows. | KPIs specced as labels + value/delta; aggregate math TBD. | `laravel-backend` |
| **Q-LEAD** | Lead attempt-history join shape + purged-thumbnail handling. | Card specced; `leads.history.purged` copy ready for retention-purged thumbs. | `laravel-backend` |
| **Q-PAY** | Buy-credits init + return-from-PayPlus shape. | Amount picker → redirect → return-confirmation states specced. | `saas-credits-billing` |
| **Q-RESOLVE** | Resolver-preview contract for the prompt editor (winning prompt/model + resolution trace). | Preview panel specced against {winner, trace[site→account→product_type→global]}; `strtr` substitution preview, escaped, read-only. | `ai-openrouter` |
| **Q-UPLOAD** | Accepted MIME types, max file size, full-body/face hint requirement. | Dropzone shows accepted-types/size copy from these limits once supplied. | `laravel-backend` |

**Resolved decisions (logged, not blocking):** Q-PRESERVE (change-photo/height
preserves the other inputs) and the gate-precedence rule (both-block → show
out-of-credit, not signup) are product calls made here, consistent with the contract.
