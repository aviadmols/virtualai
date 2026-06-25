# Definition of Done

> A pillar's UX is "done" only when **every box below is a written, state-complete,
> EN+HE spec** that `widget-embed` / `admin-design-system` can build against. This
> mirrors ARCHITECTURE.md's pillar structure and adds the per-feature checklists the
> two build agents must satisfy. A BLOCKING item left unchecked stops the phase gate
> (`code-review-gatekeeper`).

## Universal acceptance bar (applies to every surface)

Every surface — admin or widget — must pass all of these before it is "done":

- [ ] **All four states** written: empty · loading · success · error (+ low-quality for the widget result).
- [ ] **No literal values** — every color/size/radius/shadow/motion references a token in [`design-tokens.md`](design-tokens.md).
- [ ] **Every string is a key** in [`i18n-catalog.md`](i18n-catalog.md), with **EN + HE both filled** (1:1).
- [ ] **RTL parity** — layout mirrors via logical properties; per-surface RTL notes applied; HE display uses `--to-track-he`.
- [ ] **Data source named** per field (a backend contract) or flagged `TODO-DATA` with an owning agent.
- [ ] **No status invented** — every badge resolves through the §5 status map (only `generation.status` / `credit_ledger.type` / `end_user.status` / scan-confidence values).
- [ ] **Error copy is human** — what happened + reassurance + next step; no raw 500, no stack trace, shopper never blamed/billed on failure.
- [ ] **Accessibility** — focus visible, status never color-only, modal focus-trapped, inputs labelled.

---

## Pillar 1 — PDP ingestion

UX is done when these are all specced and state-complete:

- [ ] **Add-site** flow — all states (empty / validating / saving / error / success). (Flow 3.1)
- [ ] **Paste-URL → scan** — scanning / error (unreachable, not-a-PDP, failed) / success. (Flow 3.2)
- [ ] **Scan-review form** (A4) with, for **every product field** (title, price, description, variants, dimensions):
  - [ ] a **confidence chip** (high / medium / low / not-detected),
  - [ ] an **editable value** control,
  - [ ] row states: detected-high / detected-medium / detected-low / not-detected / editing / saving / error.
- [ ] **Per page selector** (add-to-cart, product image, title, price, variations):
  - [ ] detected-selector display + **manual selector entry** + **element-pick** + per-selector **re-scan/test** (with test-ok / test-fail).
- [ ] **No auto-approval** — confirm is **blocked** until every low/not-detected row is reviewed (`scan.blocked.reason`).
- [ ] **Confirm-product** action + confirming / confirm-error states.
- [ ] **Embed-code block** (A5) — ready / copied / regenerate-confirm / regenerating / error.
- [ ] **First-generation-works** confirmation (A.3.5) — not-tested / success / error.
- [ ] EN+HE keys (`scan.*`, `sites.*`, `embed.*`) + RTL notes.

**Backend contract dependency:** Q-SCAN (scan-result shape + confidence + selector-test, from `pdp-scanner`). Surface stays `data-pending` until confirmed.

---

## Pillar 2 — Try-on generation

UX is done when these are all specced and state-complete:

- [ ] **Injected button** (B1) — visible / hidden-fail-silent / host-width-matched / loading; reserves space (no CLS).
- [ ] **Modal** (B2) — enter / open / closing / error-boundary; focus trap, Esc-close, scroll-lock, focus-restore.
- [ ] **Inputs** — upload (B3: empty / drag / uploading / preview / invalid / error), height+details (B4: empty / filled / invalid / optional collapse-expand), consent (B5: unchecked-CTA-disabled / checked / required-error).
- [ ] **Generate CTA gating** — disabled until photo + valid height + consent; helper names the missing piece.
- [ ] **The two gates** rendered independently (Flow 1.4): both-pass / LeadGate-blocks→signup / CreditGate-blocks→out-of-credit / both-block→out-of-credit (precedence rule).
- [ ] **Loading** (B6) — generating / timeout (not billed) / cancel.
- [ ] **Result canvas** (B7) — **success + low-quality-warn + error** (all three).
- [ ] **Result action bar** (B8) — regenerate / change-photo (preserves height+consent) / change-height (preserves photo+consent) / **add-to-cart selected variant** / back; each with default/hover/loading/disabled.
- [ ] **Gallery slider** (B9) — **empty + one + many + loading + error**.
- [ ] **Tile actions** (B10) — open / add-to-cart / regenerate / delete-confirm / back.
- [ ] **Money safety in UX** — shopper never billed on failure/timeout/cancel; copy says so (`widget.result.error`).
- [ ] **Host-adaptation contract** honored (§4 of `design-tokens.md`): width+accent adapt, type/corner/shadow/motion lock.
- [ ] EN+HE keys (`widget.*`) + RTL notes (slider direction, mirrored arrows, HE tracking).

**Backend contract dependencies:** Q-RESULT (low-quality signal + aspect), Q-GALLERY (persistence), Q-UPLOAD (limits). Result/gallery stay `data-pending` until confirmed.

---

## Pillar 3 — Credits, leads & control plane

UX is done when these are all specced and state-complete:

- [ ] **Merchant credit dashboard** — balance KPI (A1: loading skeleton + first-run) + ledger view (A11: empty shows only opening grant).
- [ ] **Low-credit + out-of-credit banners** (A10) — warn (dismissible) / danger (persistent) + "Buy credits".
- [ ] **Buy-credits** (A11) — amount picker → PayPlus redirect → pending / success / error (no-charge-on-failure copy).
- [ ] **Graceful unavailable screen** (B13, shopper) — blame-free, no 500, independent of the lead gate.
- [ ] **Free-tries chip** (B11) — counting / last-try-warn / exhausted; copy **states the consequence**.
- [ ] **Lead-signup screen** (B12) — name / email / phone + why + consent; empty / validating / submitting / error / success.
- [ ] **Post-signup continuation** (Flow 2.3) — continuing (resume the pending try-on) / granted / gated.
- [ ] **Gate independence** — lead gate ≠ credit gate; both-block precedence resolved; never collapsed into one.
- [ ] **Leads table** (A6) + **lead card with attempt history** (A7) — empty / loading / error / purged-thumbnail.
- [ ] **Super-Admin control plane** (A12) — models / prompts / costs / operations / accounts / sites / credits, all DB-managed (never hardcoded), each with empty/loading/error; the **prompt resolver-preview** ( `strtr` substitution, escaped, read-only — **never Blade**).
- [ ] **Consent + privacy + error copy** complete and explicit (B5, `widget.consent.*`, `widget.privacy.*`).
- [ ] EN+HE keys (`credits.*`, `merchant.credit.*`, `leads.*`, `platform.*`, `widget.tries.*`, `widget.signup.*`, `widget.unavailable.*`) + RTL notes.

**Backend contract dependencies:** Q-KPI, Q-LEAD, Q-PAY, Q-PHONE, Q-RESOLVE. Affected surfaces `data-pending` until confirmed.

---

## Per-feature build checklist — `widget-embed` (Phase 7)

The widget is judged on its result + failure surfaces. Before a widget surface ships:

- [ ] All four states (+ low-quality on result) implemented per [`component-inventory.md`](component-inventory.md) Part B and [`flows.md`](flows.md) Flow 1–2.
- [ ] Consent (B5) + lead-gate (B11/B12) copy wired from the catalog; consent CTA disabled until checked.
- [ ] **Host-adaptation contract** applied: button width inherits host add-to-cart; `--tow-accent` takes merchant/host brand (default ink); type/corner/shadow/motion locked.
- [ ] **Isolation**: widget renders in an isolated root (Shadow DOM / prefixed namespace); host CSS can't bleed in, `--tow-*`/`--to-*` not set on host `:root`.
- [ ] **Tokens only** — no literal hex/px/ms; references `--to-*` / `--tow-*`. No inline `style=""`.
- [ ] **i18n** — `widget.*` keys only; EN+HE present; RTL flips via logical properties; HE display tracking 2px.
- [ ] **No charge/blame on failure** — timeout/cancel/error never bills, copy reassures.
- [ ] **A11y** — focus trap, Esc-close, focus-restore, labelled inputs, status not color-only.
- [ ] **`< 20 KB gzipped` UX budget** (see below) — lazy-loaded, never blocks host render/LCP/CLS.

### Widget bundle budget (UX constraint, not just engineering)

The `< 20 KB gzipped` target (ARCHITECTURE.md) is a **UX promise** — a heavy widget
degrades the merchant's store, which is the opposite of premium. It shapes design
decisions:

- [ ] **Heebo loads lazily / async**, only when the modal opens — the injected button can fall back to the host font until then, so the button never blocks LCP.
- [ ] **No heavy runtime** — vanilla JS, no framework (locked).
- [ ] **Images are the only weight that matters at runtime** — UI chrome stays SVG/CSS; no decorative image assets in the bundle.
- [ ] **CLS = 0** — the injected button reserves its space; the modal is an overlay (doesn't reflow the PDP).
- [ ] **Slow `0.5s` motion is CSS transitions**, not a JS animation library.
- [ ] If a design choice would push the bundle over budget, **the budget wins** — simplify the design, escalate the trade-off, never ship a bloated widget.

---

## Per-feature build checklist — `admin-design-system` (Phase 8)

Before an admin surface ships:

- [ ] **Tokens → CSS custom properties** — every value in [`design-tokens.md`](design-tokens.md) bound as a `--to-*` / `--toa-*` var; **no value invented**, no literal in any component class, no inline CSS, no Tailwind arbitrary values.
- [ ] **Both families derive from the shared base** — `--toa-*` references `--to-*`; no duplicated value across layers.
- [ ] All four states per component (A1–A12) implemented per [`component-inventory.md`](component-inventory.md) Part A.
- [ ] **Status badges** resolve through the §5 map only.
- [ ] **Scan-review form** enforces no-auto-approval (confirm blocked until flagged rows reviewed).
- [ ] **Prompt control plane** uses `strtr` substitution preview, escaped + read-only (**never `Blade::render()`** — RCE); merchant HTML preview only via isolated `iframe srcdoc` + `htmlspecialchars`.
- [ ] **i18n** — every string via `__()` with a catalog key; `lang/en` ↔ `lang/he` key-set equality enforced (no EN-only key).
- [ ] **RTL parity** — both panels render correctly in HE; logical properties only; numeric/currency cells align to inline-end.
- [ ] **A11y** — focus-visible ring (`--toa-focus`), labelled fields, keyboard-navigable tables/actions.

---

## Phase-gate readiness summary (report to `trayon-orchestrator`)

| Phase | Surface family | Ready when | Current |
|---|---|---|---|
| 7 — widget | widget (B1–B13) | all 4 states + consent/lead-gate copy written; tokens + EN/HE complete | spec **ready**; B7/B9 `data-pending` on Q-RESULT/Q-GALLERY |
| 8 — admin | admin (A1–A12) | tokens bound; no `TODO-DATA` blocks the layout; EN/HE complete | spec **ready**; scan-review + control-plane + leads `data-pending` on Q-SCAN/Q-RESOLVE/Q-LEAD |

A `data-pending` surface is still buildable for **layout + states + copy**; only the
live data binding waits on the named contract. The build agents may scaffold against
the spec and wire the contract when the owning agent confirms it.
