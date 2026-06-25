# Tray On — UX Specification Set

> The single source of truth for **what every screen and widget surface looks like,
> what data it shows, every state it can be in, and every string it speaks** — in
> EN + HE, RTL-aware. Authored by `product-ux-architect`. This is a **specification**,
> not an implementation: no CSS, no JS, no PHP lives here. The build agents bind
> these specs to code.

The locked engineering contract is [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md)
and [`../../CLAUDE.md`](../../CLAUDE.md). Where this spec and the contract disagree,
**the contract wins** — flag the drift, do not silently deviate.

---

## The two UI families (one token base)

Tray On renders two visually distinct but token-shared surface families:

| Family | Surfaces | Character | Build agent |
|---|---|---|---|
| **(a) Filament admin** | `platform` panel (Super-Admin) + `merchant` panel (account owner) | Clean, dense, functional SaaS dashboard. Legible, calm, monochrome. | `admin-design-system` |
| **(b) Premium widget** | The storefront button + modal + result + gallery + lead gate, injected on a *stranger's* PDP | Jewelry. Tabuzzco/Heebo typography-first, dramatic weight contrast, sharp-corner CTA, 7px image radius, layered soft shadows, slow 0.5s motion. Adapts to the host site. | `widget-embed` |

Both families derive from **one shared token base** (the locked Tabuzzco anchor:
ink/paper/rule, Heebo + its 9 weights, the radius/shadow/motion scale). The admin
adds a `--toa-*` layer tuned for dense data; the widget adds a `--tow-*` layer
grounded in Tabuzzco and host-adaptive. **A value lives in exactly one layer —
the families must not drift.** See [`design-tokens.md`](design-tokens.md).

---

## The three product pillars (none droppable)

Each surface declares which pillar(s) it serves and must pass that pillar's
Definition of Done.

1. **PDP ingestion** — paste URL → AI scan → per-field + per-selector confirm/correct → confirm product.
2. **Try-on generation** — button → modal (photo + height + consent) → both gates → generate → result → add-to-cart / regenerate / gallery.
3. **Credits, leads & control plane** — credit ledger UX, lead gate (free tries → signup → grant), Super-Admin DB-managed control plane.

---

## Files in this set

| File | What it locks |
|---|---|
| [`README.md`](README.md) | This index + phase mapping. |
| [`design-tokens.md`](design-tokens.md) | The token **VALUES** (colors, type, spacing, radius, shadow, motion, z-index, breakpoints), in a shared base + admin + widget families. `admin-design-system` turns each into a CSS custom property; nothing in the UI may use a literal that is not here. |
| [`component-inventory.md`](component-inventory.md) | Every admin + widget component: anatomy, variants, all states (default/hover/focus/disabled/loading/empty/error), the tokens it consumes, its i18n keys. |
| [`flows.md`](flows.md) | The three end-to-end flows with **every** state per screen: the try-on flow, the lead-gate flow, the merchant scan-review flow. |
| [`i18n-catalog.md`](i18n-catalog.md) | The canonical `en`/`he` key catalog for **all** user-facing strings (incl. empty/loading/error/consent/low-credit/out-of-credit/signup). The `lang/en/*` + `lang/he/*` files mirror this 1:1. |
| [`definition-of-done.md`](definition-of-done.md) | Per-pillar DoD + a per-feature checklist `widget-embed` and `admin-design-system` must satisfy (incl. RTL parity + the < 20 KB widget budget as a UX constraint). |

---

## How the spec maps to the build phases

This set is authored **in parallel from Phase 0** so the build phases have a locked
contract to build against.

| Build phase | Agent | Consumes from this set |
|---|---|---|
| Phase 1–6 (backend / AI / scan / credits) | `laravel-backend`, `ai-openrouter`, `pdp-scanner`, `saas-credits-billing` | The **data contracts** referenced per surface (this set names the fields; those agents supply them). Any field marked `TODO-DATA` is an open contract question for them. |
| **Phase 7 — widget** | `widget-embed` | The widget half of `component-inventory.md`, the try-on + lead-gate flows in `flows.md`, the `widget.*` keys in `i18n-catalog.md`, the host-adaptation contract, the `< 20 KB` budget. |
| **Phase 8 — admin** | `admin-design-system` | The full `design-tokens.md` (→ CSS custom properties), the admin half of `component-inventory.md`, the scan-review flow, the admin keys in `i18n-catalog.md`, both Filament panels. |

**Handoff readiness gate:** a surface is *ready for build* only when (1) its data
contract is confirmed by `laravel-backend` / `pdp-scanner` (or explicitly stubbed
with `TODO-DATA`), and (2) for a widget surface, all four states + consent/lead-gate
copy are written. Until then it is `data-pending`, not `ready`.

---

## Surface status board

Status legend: `draft` · `data-pending` (awaiting a backend contract) · `ready` (buildable) · `built`.

| Surface | Family | Pillar(s) | Status | Data gaps |
|---|---|---|---|---|
| Design tokens | shared | all | ready | — |
| Component inventory | both | all | ready | per-component `TODO-DATA` noted inline |
| **Merchant**: dashboard / KPIs | admin | credits/leads | ready | KPI aggregate shapes — see Q-KPI |
| **Merchant**: add site | admin | ingestion | ready | — |
| **Merchant**: paste URL → scan | admin | ingestion | data-pending | scan result shape + confidence (Q-SCAN) |
| **Merchant**: scan-review form | admin | ingestion | data-pending | per-field/per-selector confidence (Q-SCAN) |
| **Merchant**: embed code | admin | ingestion | ready | — |
| **Merchant**: leads table / lead card | admin | leads | data-pending | attempt-history shape (Q-LEAD) |
| **Merchant**: credit / buy / out-of-credit | admin | credits | ready | purchase-rail redirect shape (Q-PAY) |
| **Platform**: control plane (models/prompts/costs/accounts/sites/credits) | admin | control plane | data-pending | resolver preview shape (Q-RESOLVE) |
| **Widget**: injected button | widget | generation | ready | host add-to-cart selector (from scan) |
| **Widget**: modal (upload/height/consent) | widget | generation | ready | upload limits (Q-UPLOAD) |
| **Widget**: loading / result canvas | widget | generation | data-pending | signed result URL + low-quality signal (Q-RESULT) |
| **Widget**: gallery slider | widget | generation | data-pending | gallery persistence across reload (Q-GALLERY) |
| **Widget**: lead-signup + free-tries | widget | leads | ready | phone required? (Q-PHONE — answered, see flows) |
| **Widget**: out-of-credit (shopper) | widget | credits | ready | — |

Open product/contract questions are tracked at the foot of [`flows.md`](flows.md)
(`Q-*`). They are deferred-not-blocking: the spec states the default behavior and
flags the fork.
