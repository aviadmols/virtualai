# Design tokens — the single source of VALUES

> ⚠️ **LIVE TRUTH (2026-07): read `resources/css/to/tokens.css`, not the historical tables below.**
> The shipped system is the **"OpenRouter light"** base (Inter+Assistant, indigo `#4f46e5` accent,
> neutral `#fafafa` canvas, 8/10px radii, hairline-led shadows) — NOT the original Heebo/monochrome
> spec documented further down (kept for history). The **merchant panel** now layers an
> **"upgraded light"** skin on top, via *additive* tokens + merchant-`theme.css` `:root` overrides
> (the platform panel opts into none of them and is byte-identical):
>
> - **Radius:** `--to-r-card-lg: 16px` (hero/feature); merchant `--toa-radius-card: 14px`.
> - **Shadows:** `--to-shadow-layered`, `--to-shadow-layered-hover` (3-stop soft), `--to-shadow-accent-glow`; merchant aliases `--toa-shadow-card` onto the layered stack.
> - **Gradients (admin):** `--toa-grad-accent` (indigo→violet, CTAs/active), `--toa-grad-warm` (the Vsio logo pink→orange, hero garnish/badges), `--toa-grad-scrim` (image-overlay text protection).
> - **Secondary accents:** `--toa-accent-2..5` (pink/orange/sky/emerald) + `-soft` pairs for icon-tile variety.
> - **Hero geometry:** `--toa-hero-h`, `--toa-hero-tile-w`, `--toa-thumb-lg`.
> - **Component hooks (fallback pattern `var(--toa-hook, existing)`):** `--toa-btn-primary-bg`, `--toa-nav-rail` — the merchant sets them; shared components fall back to the flat look for the platform.
>
> **Law unchanged:** every value still lives in `tokens.css`; panels diverge only through the
> documented adaptable set in their own `theme.css`. `--tow-*` (widget) is untouched by the redesign.

> `product-ux-architect` owns these **values**. `admin-design-system` turns each
> into a CSS custom property and binds it; it never invents a value. **No literal
> (`#000`, `7px`, `0.5s`) may appear in any screen spec or any built file unless it
> is a token here.** If a spec needs a value that isn't here, the fix is to add the
> token (and decide its layer), not to hardcode.

## Three layers, one base — never duplicate a value across layers

```
shared base  ──  --to-*    the locked Tabuzzco anchor. Both families derive from it.
                            NEVER overridden: ink/paper/rule, Heebo + weights,
                            radius/shadow/motion scale.
                                 │
   ┌─────────────────────────────┴─────────────────────────────┐
admin family  ──  --toa-*                          widget family  ──  --tow-*
clean/dense functional SaaS                        premium, Tabuzzco-grounded,
(KPI density, calm monochrome)                     host-adaptive (button width +
                                                    accent adapt; type/corner/
                                                    shadow/motion lock)
```

When adding a token, decide its home: a structural value shared by both families →
`--to-*`; an admin-only density/chrome value → `--toa-*`; a widget-only premium /
host-adaptive value → `--tow-*`. A value defined in `--to-*` is **referenced**, not
copied, by the family layers (e.g. `--tow-radius-image: var(--to-r-card)`).

---

## 1. Shared base — `--to-*` (LOCKED Tabuzzco anchor)

### 1.1 Color (monochrome anchor + status)

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Ink | `--to-ink` | `#000000` | Primary text; the monochrome anchor. **Locked.** |
| Paper | `--to-paper` | `#FFFFFF` | Surfaces, modal background. **Locked.** |
| Rule | `--to-rule` | `#BCBCBC` | Form underlines, dividers. **Locked.** |
| Rule soft | `--to-rule-soft` | `#EFEFEF` | Subtle dividers, table row hairlines. **Locked.** |
| Ink muted | `--to-ink-muted` | `#6B7280` | Labels, captions, helper text. |
| Ink subtle | `--to-ink-subtle` | `#9CA3AF` | Placeholder, disabled text. |
| Success | `--to-success` | `#2D8A5A` | Succeeded generation, healthy, positive delta. |
| Success bg | `--to-success-bg` | `#E7F2EC` | Success pill background. |
| Warn | `--to-warn` | `#C77700` | Low credit, awaiting, retry-scheduled, last free try. |
| Warn bg | `--to-warn-bg` | `#FBF1E0` | Warning pill background. |
| Danger | `--to-danger` | `#C4452D` | Failed generation, error, destructive CTA, out of credit. |
| Danger bg | `--to-danger-bg` | `#FBE9E9` | Danger pill background. |
| Info | `--to-info` | `#2D6A8A` | Neutral-informational (teal lead state). |
| Info bg | `--to-info-bg` | `#E5EFF4` | Info pill background. |

> **Never override** `--to-ink`, `--to-paper`, `--to-rule`, `--to-rule-soft`. Per-surface accents live on the family layers below.

### 1.2 Typography — Heebo, the only typeface (9 weights, Hebrew + Latin)

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Family | `--to-font` | `"Heebo", system-ui, sans-serif` | Single bilingual typeface. |
| Weight thin | `--to-fw-thin` | `100` | Rare oversized display only. |
| Weight extralight | `--to-fw-extralight` | `200` | Rare. |
| Weight light | `--to-fw-light` | `300` | **SIGNATURE** — display line, elegant body, editorial quote. |
| Weight regular | `--to-fw-regular` | `400` | Body. |
| Weight medium | `--to-fw-medium` | `500` | **SIGNATURE** — eyebrows, labels, card titles, FAQ. |
| Weight semibold | `--to-fw-semibold` | `600` | Admin emphasis where 700 is too heavy. |
| Weight bold | `--to-fw-bold` | `700` | Emphasis, primary admin labels. |
| Weight extrabold | `--to-fw-extrabold` | `800` | Rare. |
| Weight black | `--to-fw-black` | `900` | **SIGNATURE** — emphasis word, oversized numbers, the headline punch. |

**Line-height & tracking (shared):**

| Token | CSS var | Value | Intent |
|---|---|---|---|
| LH body | `--to-lh-body` | `1.75` | Paragraph body. |
| LH display | `--to-lh-display` | `0.95` | Tight dramatic display rhythm. |
| LH medium | `--to-lh-medium` | `1.2` | Card titles, headings. |
| Track tight | `--to-track-tight` | `-0.01em` | Big display occasional negative. |
| Track zero | `--to-track-zero` | `0` | Body default. |
| Track label | `--to-track-label` | `3px` | Uppercase section labels. |
| Track eyebrow | `--to-track-eyebrow` | `1px` | Eyebrow + footer micro-copy. |
| Track button | `--to-track-button` | `2px` | Button text. |
| Track display HE | `--to-track-he` | `2px` | **RTL** — Hebrew display (Latin 5px breaks Hebrew kerning). |
| Track display EN | `--to-track-en` | `5px` | Latin display stylistic moments. |

### 1.3 Spacing scale (never raw px)

| Token | CSS var | Value | Common use |
|---|---|---|---|
| 1 | `--to-space-1` | `4px` | Hairline gaps. |
| 2 | `--to-space-2` | `8px` | Inline icon-text gap. |
| 3 | `--to-space-3` | `12px` | Field internal padding. |
| 4 | `--to-space-4` | `16px` | Card internal padding. |
| 5 | `--to-space-5` | `20px` | Widget gap. |
| 6 | `--to-space-6` | `24px` | Block gap. |
| 8 | `--to-space-8` | `32px` | Component spacing. |
| 10 | `--to-space-10` | `40px` | Section internal. |
| 12 | `--to-space-12` | `48px` | Section internal large. |
| 16 | `--to-space-16` | `64px` | Section gap (mobile). |
| 20 | `--to-space-20` | `80px` | Section gap. |
| 24 | `--to-space-24` | `96px` | Section gap large. |
| 32 | `--to-space-32` | `128px` | Hero whitespace, big numbers. |

### 1.4 Radius

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Sharp | `--to-r-none` | `0` | **SIGNATURE** — widget buttons + inputs (sharp corners). |
| Card | `--to-r-card` | `7px` | **SIGNATURE** — images, thumbnails, result canvas, gallery tiles, upload preview. |
| Pill | `--to-r-pill` | `999px` | Status badges, free-tries chip. |

### 1.5 Shadows (layered soft — SIGNATURE)

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Card | `--to-shadow-card` | `rgb(0 0 0 / 16%) 0 47px 46px -27px, rgb(0 0 0 / 6%) 0 2px 12px 0` | Image cards, result canvas, gallery tiles. |
| Card hover | `--to-shadow-hover` | `rgb(0 0 0 / 16%) 0 47px 46px -27px, rgb(0 0 0 / 16%) 0 2px 12px 0` | Second layer 6% → 16% on hover. |
| Soft | `--to-shadow-soft` | `0 5px 10px rgb(0 0 0 / 10%)` | Modal lift, sticky bars. |

### 1.6 Motion (slow / calm — SIGNATURE)

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Fast | `--to-t-fast` | `0.3s ease` | Micro-interactions. |
| Base | `--to-t-base` | `0.5s ease` | **SIGNATURE** default slow/calm transition. |
| Slow | `--to-t-slow` | `0.7s ease` | Modal enter, result reveal. |

### 1.7 Borders

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Input | `--to-bw-input` | `1px` | Form input bottom border (resting). |
| Input focus | `--to-bw-input-focus` | `2px` | Focused form input. |
| Button | `--to-bw-button` | `1px` | Button outline. |

### 1.8 Z-index scale (shared — avoids ad-hoc stacking wars)

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Base | `--to-z-base` | `0` | Document flow. |
| Sticky | `--to-z-sticky` | `100` | Admin sticky table headers, low-credit banner. |
| Dropdown | `--to-z-dropdown` | `200` | Admin menus, selects. |
| Widget button | `--to-z-widget-btn` | `9000` | Injected button — above host chrome, below host modals where possible. |
| Overlay | `--to-z-overlay` | `2147483000` | Widget scrim — must beat host z-index wars (near max int). |
| Modal | `--to-z-modal` | `2147483001` | Widget modal shell — one above the scrim. |
| Toast | `--to-z-toast` | `2147483002` | Widget transient toasts (e.g. "added to cart"). |

> The widget uses near-max-int z-index on purpose: it lives on a stranger's site and must not be buried by the host's own modals/headers. The injected **button** stays low (`9000`) so it sits inline in the PDP; only the **open modal** escalates.

### 1.9 Breakpoints (mobile-first, min-width to scale up)

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Tablet | `--to-bp-tablet` | `768px` | Tablet and up. |
| Desktop | `--to-bp-desktop` | `1024px` | Desktop and up (display type gets dramatic here). |
| Wide | `--to-bp-wide` | `1400px` | Wide containers. |

> Breakpoints are documented as tokens for reference; in CSS they are `@media` query values, not custom properties (custom properties can't drive media queries). `admin-design-system` keeps the literal values in sync with this table.

---

## 2. Admin family — `--toa-*` (clean functional SaaS)

The admin is the utilitarian cousin: same Heebo + monochrome anchor, but tuned for
dense, legible data. It softens the radius (functional density reads friendlier than
razor corners on a 200-row table) and uses a calm near-black accent.

### 2.1 Admin color

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Accent | `--toa-accent` | `#111111` | Primary CTAs, active nav, focus ring (calm monochrome). |
| Accent hover | `--toa-accent-hover` | `#000000` | Accent hover/active. |
| Canvas | `--toa-bg` | `#F8F8F5` | App background behind cards. |
| Surface | `--toa-surface` | `var(--to-paper)` | Cards, tables, panels. |
| Surface alt | `--toa-surface-alt` | `#FBFBF9` | Zebra rows, nested panels. |
| Border | `--toa-border` | `#E6E6E1` | Card borders, input borders, row dividers. |
| Focus ring | `--toa-focus` | `rgb(17 17 17 / 35%)` | Keyboard focus halo. |

### 2.2 Admin typography (density-tuned)

| Token | CSS var | Size / weight | Intent |
|---|---|---|---|
| KPI value | `--toa-type-kpi` | `28px` / `var(--to-fw-black)` | The big number in a KPI card. |
| Heading | `--toa-type-h` | `18px` / `var(--to-fw-medium)` | Card titles, section headers. |
| Body | `--toa-type-body` | `14px` / `var(--to-fw-regular)` | Default admin text. |
| Body strong | `--toa-type-body-strong` | `14px` / `var(--to-fw-semibold)` | Table key cells, emphasis. |
| Caption | `--toa-type-caption` | `12px` / `var(--to-fw-medium)` | Labels, badge text, table headers (tracked, uppercase). |

### 2.3 Admin radius (softer than widget — density reads friendlier)

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Card | `--toa-radius-card` | `12px` | KPI cards, panels. |
| Control | `--toa-radius-control` | `8px` | Admin buttons / inputs / chips (NOT the sharp widget CTA). |
| Field | `--toa-radius-field` | `8px` | Filament form fields. |

### 2.4 Admin shadow / spacing aliases

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Card shadow | `--toa-shadow-card` | `0 1px 3px rgb(0 0 0 / 6%), 0 1px 2px rgb(0 0 0 / 4%)` | Lighter than the widget's dramatic layered shadow — dense dashboards need calm, not lift. |
| Page gutter | `--toa-gutter` | `var(--to-space-6)` | Panel page padding. |

---

## 3. Widget family — `--tow-*` (premium, Tabuzzco-grounded, host-adaptive)

The widget defaults to the monochrome anchor and the Heebo typography-first language.
Where the merchant configures (or the host safely exposes) a brand accent, it
overrides **only** `--tow-accent`; the structural tokens stay locked. The injected
button width adapts to the host add-to-cart. See [host-adaptation contract](#4-host-adaptation-contract).

### 3.1 Widget color

| Token | CSS var | Value / source | Intent |
|---|---|---|---|
| Accent | `--tow-accent` | default `var(--to-ink)`; **merchant/host override** | Primary CTA fill (filled variant), active states. |
| Accent on | `--tow-accent-on` | default `var(--to-paper)`; derived if accent overridden | Text/icon on the accent fill. |
| Surface | `--tow-surface` | `var(--to-paper)` | Modal shell, result canvas background. |
| Overlay | `--tow-overlay` | `rgb(0 0 0 / 55%)` | Scrim behind the modal. |
| Hairline | `--tow-hairline` | `var(--to-rule-soft)` | Internal modal dividers. |

### 3.2 Widget typography (the premium moments)

| Token | CSS var | Size / weight / treatment | Intent |
|---|---|---|---|
| Display (mobile) | `--tow-type-display` | `36px` / mixed `300`+`900` / lh `0.95` | Modal hero moment, mobile. |
| Display (desktop) | `--tow-type-display-lg` | `60px` / mixed `300`+`900` / lh `0.95` | Modal hero moment, desktop. |
| Eyebrow | `--tow-type-eyebrow` | `14px` / `500` / tracking `4px` / uppercase | "Tray On" label, step labels. |
| Body | `--tow-type-body` | `16px` / `400` / lh `1.75` | Modal copy, helper text. |
| Button text | `--tow-type-button` | `13px` / `500` / tracking `2px` / uppercase | Injected button + modal CTAs. |
| Caption | `--tow-type-caption` | `13px` / `400` | Consent line, micro-copy, free-tries chip. |

### 3.3 Widget radius / motion (locked Tabuzzco)

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Button radius | `--tow-radius-button` | `var(--to-r-none)` | **SIGNATURE** sharp corner — never rounded. |
| Image radius | `--tow-radius-image` | `var(--to-r-card)` | Result canvas, gallery tiles, upload preview. |
| Chip radius | `--tow-radius-chip` | `var(--to-r-pill)` | Free-tries chip, status pill. |
| Button transition | `--tow-t-button` | `var(--to-t-base)` | Slow invert on hover (no scale). |
| Modal transition | `--tow-t-modal` | `var(--to-t-slow)` | Enter fade+rise, result reveal. |

### 3.4 Widget layout

| Token | CSS var | Value | Intent |
|---|---|---|---|
| Modal max-width | `--tow-modal-w` | `560px` | Centered desktop modal. |
| Modal radius | `--tow-modal-radius` | `var(--to-r-card)` | Modal shell corners (images-language 7px, not razor — the modal is a surface, not a CTA). |
| Result canvas aspect | `--tow-result-aspect` | `3 / 4` | Default try-on portrait ratio (overridable by `ai_operations.aspect_ratio` — see Q-RESULT). |
| Gallery tile width | `--tow-tile-w` | `120px` | Slider thumbnail width. |
| Button width | `--tow-btn-w` | **inherited** from host add-to-cart (see §4) | Native fit under the existing CTA. |

---

## 4. Host-adaptation contract (premium AND belonging)

The widget must feel like Tray On **and** belong on the merchant's PDP. This table is
the boundary; `widget-embed` builds to it. Detail lives in [`flows.md`](flows.md).

| Property | Behavior | Token | Why |
|---|---|---|---|
| Button width | **Inherit** — match host add-to-cart width | `--tow-btn-w` | Native fit under the existing CTA. |
| Button corner | **Lock** — sharp corner | `--tow-radius-button` | The Tray On identity. |
| Button style | **Lock** — outline → invert on hover (Tabuzzco) | `--tow-t-button` | The signature interaction. |
| Accent color | **Adapt** — merchant-set or host-exposed brand color overrides `--tow-accent`; default ink | `--tow-accent` | Never clash; never hardcode `#000` over a navy brand. |
| Body font | **Adapt** — Heebo for modal moments; fall back to host font for inherited chrome if Heebo can't load | `--to-font` | Display moments stay Heebo; the modal never looks broken if the CDN fails. |
| Modal display moments | **Lock** — mixed-weight Heebo (300 + 900), dramatic, slow reveal | `--tow-type-display*` | The premium feel. |
| Image radius / shadow | **Lock** — 7px + layered soft shadow | `--tow-radius-image`, `--to-shadow-card` | The signature image treatment. |
| Motion | **Lock** — slow `0.5s`; no flashy `200ms` | `--to-t-base` | Calm / premium. |
| RTL | **Adapt** — flip via logical properties when host/locale is Hebrew | — | A flip, not a redesign. |

**Rule of thumb:** adapt **color + width** to belong; lock **type, corner, shadow,
motion** to feel like Tray On. With no host brand and no merchant accent, default to
the monochrome anchor — **never invent a color.**

**Isolation note (for `widget-embed`):** the widget renders inside a Shadow DOM or a
prefixed namespace so the host's CSS cannot bleed into these tokens and these tokens
cannot bleed out. The `--tow-*` / `--to-*` custom properties are scoped to the widget
root, never set on `:root` of the host page.

---

## 5. Status → badge map (canonical — maps onto the state machines)

Every status badge in the admin and widget resolves through this map. **Do not invent
a status** — these come from `ARCHITECTURE.md` (`generation.status`,
`credit_ledger.type`, `end_user.status`) + the scan confidence levels from
`pdp-scanner`. A status not here is a backend/scanner conversation, not a new badge.

| Domain status | Badge tone | Color tokens | i18n key |
|---|---|---|---|
| generation `pending` | warn | `--to-warn` / `--to-warn-bg` | `status.generation.pending` |
| generation `processing` | warn | `--to-warn` / `--to-warn-bg` | `status.generation.processing` |
| generation `succeeded` | success | `--to-success` / `--to-success-bg` | `status.generation.succeeded` |
| generation `failed` | danger | `--to-danger` / `--to-danger-bg` | `status.generation.failed` |
| generation `cancelled` | neutral | `--to-ink-muted` / `--to-rule-soft` | `status.generation.cancelled` |
| ledger `grant` | success | `--to-success` / `--to-success-bg` | `status.ledger.grant` |
| ledger `purchase` | success | `--to-success` / `--to-success-bg` | `status.ledger.purchase` |
| ledger `charge` | neutral (ink) | `--to-ink` / `--to-rule-soft` | `status.ledger.charge` |
| ledger `refund` | warn | `--to-warn` / `--to-warn-bg` | `status.ledger.refund` |
| ledger `adjustment` | info | `--to-info` / `--to-info-bg` | `status.ledger.adjustment` |
| account credit `low` (≤ per-site threshold) | warn | `--to-warn` / `--to-warn-bg` | `status.credit.low` |
| account credit `empty` (balance − reserved < estimate) | danger | `--to-danger` / `--to-danger-bg` | `status.credit.empty` |
| end_user `new` | neutral | `--to-ink-muted` / `--to-rule-soft` | `status.lead.new` |
| end_user `generated` | info | `--to-info` / `--to-info-bg` | `status.lead.generated` |
| end_user `added_to_cart` | neutral (ink) | `--to-ink` / `--to-rule-soft` | `status.lead.added_to_cart` |
| end_user `purchased` | success | `--to-success` / `--to-success-bg` | `status.lead.purchased` |
| end_user `incomplete` | danger | `--to-danger` / `--to-danger-bg` | `status.lead.incomplete` |
| scan confidence `high` | success | `--to-success` / `--to-success-bg` | `scan.confidence.high` |
| scan confidence `medium` | warn | `--to-warn` / `--to-warn-bg` | `scan.confidence.medium` |
| scan confidence `low` | danger | `--to-danger` / `--to-danger-bg` | `scan.confidence.low` |
| scan field `not_detected` | neutral | `--to-ink-muted` / `--to-rule-soft` | `scan.confidence.none` |

> `scan` confidence levels (`high`/`medium`/`low`/`not_detected`) are the contract
> `pdp-scanner` must supply per field and per selector. If the scanner emits a numeric
> score instead, the thresholds that bucket it into these four levels are a
> `pdp-scanner` decision — this map keys off the bucketed level, not the raw score.

---

## 6. Token governance rules

1. **One value, one home.** A value lives in `--to-*` OR `--toa-*` OR `--tow-*` — never copied. Family layers reference the base (`var(--to-…)`), never restate its value.
2. **Locked structural tokens** (never overridden by any family, brand, or host): `--to-ink`, `--to-paper`, `--to-rule`, `--to-rule-soft`, all `--to-font` / `--to-fw-*`, all radius / shadow / motion tokens. These are the Tabuzzco language.
3. **The only adapt-able widget token is `--tow-accent`** (+ its derived `--tow-accent-on`). Everything else the widget locks.
4. **No literal outside this file.** A literal in a screen spec is a spec bug. A literal in built CSS/JS is a review BLOCKER.
5. **Status comes from the state machines**, never invented here. New status = a `laravel-backend` / `pdp-scanner` conversation.
6. **Breakpoint values** are kept in sync by `admin-design-system`; this table is authoritative for the numbers.
