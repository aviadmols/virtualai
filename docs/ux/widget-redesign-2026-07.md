# Widget redesign — 2026-07 (glass + gradient rebuild)

> **Spec only.** No CSS, no JS, no PHP lives in this file. `widget-embed` builds from it;
> `admin-design-system` polishes after. Authored by `product-ux-architect`.
>
> **Source of truth for the look:** `C:\Users\user\Desktop\New\vyra-dash (2).html` (the mock).
> **Source of truth for behavior/contracts:** `ARCHITECTURE.md` + `CLAUDE.md` + the existing
> widget source under `resources/widget/`. Where the mock and the contract disagree, the
> contract wins and this spec says so explicitly.
>
> This document **supersedes** the widget half of `design-tokens.md` and `component-inventory.md`
> for the storefront widget (the admin `--toa-*` family is untouched). The widget's private token
> layer is and remains **`--ton-*`** (as already implemented in `resources/widget/styles/widget.css`),
> not `--tow-*`.

---

## 0. Locked decisions (do not re-open)

| # | Decision | Consequence for this spec |
|---|---|---|
| D1 | "VYRA" in the mock is a **placeholder**. The product is **Tray On**. | Every visible string is Tray On. The wordmark is the key `brand.wordmark` = `TRAY ON` in **both** locales (a brand name is not translated). |
| D2 | Font is **Outfit** for now; a Hebrew face arrives later. | The type stack is **one token** (`--ton-font`) resolved per-locale from `--ton-font-latin` / `--ton-font-he`. Components never name a family. §1.4. |
| D3 | **Share ships.** `navigator.share({files:[File]})` from the already-signed result blob. | No new public URL → no new privacy surface. Desktop fallback = download + copy link. §2.9, §5.6. |
| D4 | **"Save Look" opens the lead capture** and creates the shopper's account. | It is the `LeadGate` / `POST /widget/v1/leads` signup screen, not a silent local save. Registered shopper → a confirmation only. §2.9, §5.5. |
| D5 | **Add to Cart really adds to cart.** | States: `idle → adding → added` / `failed`. Shopify AJAX path with a `/cart.js` verify. §2.9, §5.7. |
| D6 | The trigger sits **on the product image** — an **optional** per-site placement. | One new enum value on the existing `button_placement` key. **Zero new appearance keys.** §7. |
| D7 | The bundle **splits**: tiny always-on core + a lazily fetched chunk. | I own what the shopper sees during the fetch. §6. |

---

## 1. Design tokens

Transcribed from the mock, normalized onto a scale, named. **Components reference tokens only** —
if a value is not in this table it may not appear in a built file. All tokens live on the widget's
own shadow roots (`:host` / `.ton-root`), never on the host page.

### 1.1 Brand gradient + color

| Token | Value | Intent |
|---|---|---|
| `--ton-grad-1` | `#f472b6` | Gradient stop 1 (pink). The single derived accent. |
| `--ton-grad-2` | `#fda4af` | Gradient stop 2 (rose). |
| `--ton-grad-3` | `#fdba74` | Gradient stop 3 (peach). |
| `--ton-gradient` | `linear-gradient(60deg, var(--ton-grad-1), var(--ton-grad-2), var(--ton-grad-3))` | The Tray On identity fill: primary CTA, gradient title word, status dot. **Fixed — never merchant-configurable.** |
| `--ton-accent` | `var(--ton-grad-1)` | Single-color accent where a gradient can't go: hover text, active-thumb ring, spinner arc, focus ring. |
| `--ton-ink` | `#111111` | Primary text: wordmark, HUD title, trigger label. |
| `--ton-ink-2` | `#222222` | Product title, secondary-button label. |
| `--ton-ink-muted` | `#666666` | Loader text, HUD sub, helper text. |
| `--ton-ink-subtle` | `#888888` | Close glyph at rest. |
| `--ton-danger` | `#dc2626` | Error text, failed-HUD dot, invalid field rule. |

**SVG gradient.** The sparkle icon is an SVG whose fill is `url(#ton-grad)`. Ids are scoped per
shadow root, so **every shadow root that renders the sparkle must carry its own `<linearGradient
id="ton-grad">` def** (`x1 0% y1 0% x2 100% y2 100%`, stops `--ton-grad-1` 0% / `--ton-grad-2` 50% /
`--ton-grad-3` 100%). The trigger root and the shell root each need one. A missing def = an invisible
icon; this is the single most likely silent bug in the rebuild.

### 1.2 Glass surfaces (rgba + blur)

| Token | Value | Blur token | Used by |
|---|---|---|---|
| `--ton-glass-trigger` | `rgba(255, 255, 255, 0.9)` | `--ton-blur-trigger: 8px` | On-image trigger (rest). Hover → `--ton-surface`. |
| `--ton-glass-modal` | `rgba(255, 255, 255, 0.9)` | `--ton-blur-modal: 20px` | Modal box. |
| `--ton-glass-hud` | `rgba(255, 255, 255, 0.95)` | `--ton-blur-hud: 10px` | Floating status HUD. |
| `--ton-glass-preview` | `rgba(255, 255, 255, 0.7)` | — | The preview-area bed (behind the image / spinner). |
| `--ton-glass-control` | `rgba(240, 240, 240, 0.8)` | — | Secondary buttons + the Upload tile (rest). Hover → `--ton-surface`. |
| `--ton-surface` | `#ffffff` | — | Solid fallback + the hover target of every glass control. |
| `--ton-overlay` | `rgba(0, 0, 0, 0.4)` | — | The modal scrim. |
| `--ton-scrim-text` | `rgba(255, 255, 255, 0.9)` | — | The disclaimer text laid over the result image. |
| `--ton-scrim-shadow` | `0 1px 3px rgba(0, 0, 0, 0.6)` | — | Text-shadow that keeps the disclaimer legible on any image. |
| `--ton-spinner-track` | `rgba(244, 114, 182, 0.15)` | — | The spinner's unlit ring. |

**Blur fallback (mandatory).** Where `backdrop-filter` is unsupported, every glass token falls back
to `--ton-surface` (opaque). Nothing may become unreadable. The scrim (`--ton-overlay`) is not a
glass token and never falls back.

**Dark theme.** `popup_theme: dark` is an existing merchant setting and stays. Overrides on
`.ton-root[data-theme='dark']` only — the gradient is unchanged in both themes:

| Token | Dark value |
|---|---|
| `--ton-glass-modal` | `rgba(17, 17, 17, 0.85)` |
| `--ton-glass-hud` | `rgba(17, 17, 17, 0.92)` |
| `--ton-glass-preview` | `rgba(255, 255, 255, 0.06)` |
| `--ton-glass-control` | `rgba(255, 255, 255, 0.08)` |
| `--ton-surface` | `#111111` |
| `--ton-ink` | `#fafafa` |
| `--ton-ink-2` | `#f4f4f5` |
| `--ton-ink-muted` | `#a1a1aa` |
| `--ton-ink-subtle` | `#71717a` |

### 1.3 Radius, shadow, elevation

| Token | Value | Intent |
|---|---|---|
| `--ton-radius-modal` | `24px` | The modal box. |
| `--ton-radius-image` | `16px` | Preview area, upload preview, skeleton. |
| `--ton-radius-control` | `10px` | Trigger, buttons, thumbs, Upload tile, HUD. |
| `--ton-radius-pill` | `999px` | The free-tries chip. |
| `--ton-radius-dot` | `50%` | Status dot, spinner. |
| `--ton-shadow-modal` | `0 30px 60px rgba(0, 0, 0, 0.15)` | The modal lift. |
| `--ton-shadow-trigger` | `0 10px 30px rgba(0, 0, 0, 0.08)` | The on-image trigger. |
| `--ton-shadow-hud` | `0 10px 30px rgba(0, 0, 0, 0.1)` | The HUD. |
| `--ton-shadow-cta` | `0 6px 20px rgba(244, 114, 182, 0.25)` | The gradient primary CTA at rest. |
| `--ton-shadow-cta-hover` | `0 8px 25px rgba(244, 114, 182, 0.4)` | The gradient primary CTA on hover. |
| `--ton-ring-active` | `0 0 0 2px var(--ton-accent)` | The active thumbnail's ring (a shadow, not a border — borders shift layout). |
| `--ton-z` | `2147483000` | Overlay + modal (existing value, unchanged). |
| `--ton-z-hud` | `2147482990` | The HUD: above the host page, **below** the modal overlay. |
| `--ton-z-trigger` | `2` | The on-image trigger, inside the host image's stacking context. |

### 1.4 Type — one swappable stack (D2)

| Token | Value | Note |
|---|---|---|
| `--ton-font-latin` | `'Outfit', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` | Self-hosted, **subset to latin + latin-ext**, weights **300/400/500/600 only**, `woff2`, `font-display: swap`. **Never Google Fonts** — no third-party request on the merchant's page. |
| `--ton-font-he` | `'Segoe UI', 'Arial Hebrew', 'Noto Sans Hebrew', system-ui, sans-serif` | **Outfit has no Hebrew glyphs.** Today HE ships **no webfont** — a Hebrew-safe system stack. When Aviad supplies the Hebrew face, **this one token changes** (and only this one). |
| `--ton-font-system` | `system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` | The **core** surfaces (trigger + HUD) are locked to this forever — see §6.4. |
| `--ton-font` | `var(--ton-font-latin)`; on a root with `dir="rtl"` → `var(--ton-font-he)` | **The only family token a component may name.** |

| Token | Size / weight / tracking | Used by |
|---|---|---|
| `--ton-fs-logo` | `16px` / `--ton-fw-semibold` / `--ton-track-logo` / uppercase | The `TRAY ON` wordmark. |
| `--ton-fs-title` | `28px` / `--ton-fw-light` | The product title in the brand header. |
| `--ton-fs-cta` | `18px` / `--ton-fw-medium` / `--ton-track-cta` / uppercase | The primary gradient CTA. |
| `--ton-fs-trigger` | `15px` / `--ton-fw-semibold` | The on-image trigger label. |
| `--ton-fs-button` | `14px` / `--ton-fw-regular` | Secondary buttons (Save Look / Share). |
| `--ton-fs-body` | `14px` / `--ton-fw-regular` / `--ton-lh-body` | Form labels, helper text. |
| `--ton-fs-hud-title` | `13px` / `--ton-fw-medium` | HUD title. |
| `--ton-fs-hud-sub` | `11px` / `--ton-fw-light` | HUD sub-line. |
| `--ton-fs-loader` | `13px` / `--ton-fw-light` / `--ton-track-loader` | The "Creating your look…" line. |
| `--ton-fs-upload` | `11px` / `--ton-fw-regular` | The Upload tile label. |
| `--ton-fs-chip` | `11px` / `--ton-fw-medium` | The free-tries chip. |
| `--ton-fs-disclaimer` | `10px` / `--ton-fw-light` / `--ton-track-disclaimer` | The disclaimer over the image. |

| Weight token | Value |
|---|---|
| `--ton-fw-light` | `300` |
| `--ton-fw-regular` | `400` |
| `--ton-fw-medium` | `500` |
| `--ton-fw-semibold` | `600` |

| Tracking token | LTR | **RTL / HE** | Why |
|---|---|---|---|
| `--ton-track-logo` | `4px` | `4px` | The wordmark is Latin in both locales — it keeps its tracking. |
| `--ton-track-cta` | `3px` | **`0`** | Letter-spacing shatters Hebrew. |
| `--ton-track-loader` | `1px` | **`0`** | Same. |
| `--ton-track-disclaimer` | `0.5px` | **`0`** | Same. |
| `--ton-lh-body` | `1.5` | `1.5` | — |

**Gradient title word.** The product title renders with its **last whitespace-delimited token**
filled by `--ton-gradient` (mock: "Oversized **Blazer**"). Rules: a one-word title is entirely
gradient; the title clamps to **2 lines** with an ellipsis; in Hebrew the "last token" is the last
in *logical* order (it renders leftmost — correct, do not special-case). The title text comes from
the bootstrap `product.title` and is **inserted as text, never as HTML**.

### 1.5 Spacing + sizing

| Token | Value | Token | Value |
|---|---|---|---|
| `--ton-space-1` | `4px` | `--ton-space-5` | `20px` |
| `--ton-space-2` | `8px` | `--ton-space-6` | `24px` |
| `--ton-space-3` | `12px` | `--ton-space-8` | `32px` |
| `--ton-space-4` | `16px` | `--ton-space-10` | `40px` |

| Token | Value | Intent |
|---|---|---|
| `--ton-modal-pad` | `var(--ton-space-10)` | Modal padding ≥ 480px. |
| `--ton-modal-pad-sm` | `var(--ton-space-6)` | Modal padding < 480px. |
| `--ton-modal-max-w` | `500px` | Modal width cap. |
| `--ton-modal-max-h` | `95vh` | Modal height cap (scrolls internally). |
| `--ton-preview-ratio` | `3 / 4` | The preview area's **fixed aspect box** (portrait). |
| `--ton-preview-max-h` | `60vh` (`55vh` < 480px) | Keeps the strip + actions reachable. |
| `--ton-thumb-w` / `--ton-thumb-h` | `70px` / `85px` | Thumb + Upload tile. |
| `--ton-hud-inset` | `24px` | The HUD's distance from the viewport edges. |
| `--ton-trigger-inset` | `16px` | The trigger's distance from the product image's edges. |
| `--ton-spinner-size` | `44px` | The AI spinner. |
| `--ton-spinner-stroke` | `2px` | Its ring width. |
| `--ton-dot-size` | `12px` | The HUD status dot. |
| `--ton-close-size` | `36px` | The close hit-target. |
| `--ton-icon` / `--ton-icon-sm` / `--ton-icon-lg` | `20px` / `18px` / `24px` | Icon sizes. |

> **The mock's `height: 480px` preview is replaced by `--ton-preview-ratio` with `object-fit: contain`.**
> Reason, stated for the record: the mock uses `object-fit: cover` because its images were
> pre-cropped. **Cropping a try-on defeats the product** — it cuts off heads and feet, which is
> precisely what the shopper is evaluating. A fixed aspect box gives us a stable frame (no jump
> between spinner → image) *and* shows the whole generated image, letterboxed on `--ton-glass-preview`.

### 1.6 Motion — durations + the exact easings

| Token | Value | Applied to |
|---|---|---|
| `--ton-t-fast` | `0.15s ease` | Thumb / secondary-button hover (transform, background, color). |
| `--ton-t` | `0.2s ease` | Trigger hover, primary CTA hover, close hover. |
| `--ton-t-overlay` | `0.25s ease` | Overlay opacity in/out. |
| `--ton-ease-out-expo` | `cubic-bezier(0.16, 1, 0.3, 1)` | — |
| `--ton-t-modal` | `0.3s var(--ton-ease-out-expo)` | The modal box's `translateY(20px) scale(0.98) → 0/1`. |
| `--ton-ease-hud` | `cubic-bezier(0.19, 1, 0.22, 1)` | — |
| `--ton-t-hud` | `0.4s var(--ton-ease-hud)` | The HUD's slide-in from the inline-start edge. |
| `--ton-t-image` | `0.3s ease` | The result image's opacity fade-in. |
| `--ton-dir` | `1`; on `[dir='rtl']` → `-1` | The direction multiplier every translate-based animation uses (§4). |

**Keyframes (exact).**

| Name | Definition | Where |
|---|---|---|
| `ton-spin` | `100% { transform: rotate(360deg); }` — `0.8s linear infinite` | The AI spinner ring; the HUD thinking ring; the trigger busy glyph. |
| `ton-pulse` | `0% { opacity: 1; transform: scale(1); }` · `50% { opacity: 0.6; transform: scale(1.1); }` · `100% { opacity: 1; transform: scale(1); }` — `2s infinite` | The HUD status dot (idle + ready). |
| `ton-shimmer` | existing (`1.9s linear infinite`, 115° sweep) | The lazy-chunk skeleton + the loading bed. |
| `ton-fade` | `from { opacity: 0 } to { opacity: 1 }` | Overlay + result appearance. |

**`prefers-reduced-motion: reduce` (mandatory, nobody else will remember it).**
`ton-spin`, `ton-pulse`, `ton-shimmer` stop. The spinner becomes a **static ring** and the loading
copy carries the meaning. The modal appears with **opacity only** (no translate/scale). The HUD
appears with **opacity only** (no slide). Hover transforms (`translateY(-2px)`) are dropped.

---

## 2. Component inventory

Every surface in the mock. Each: purpose · states · tokens · RTL rule.

### 2.0 THE CLASS-NAME CONTRACT (read before touching anything)

`tests/widget/verify.mjs` (1,468 lines, 14 gates) drives the widget **through these class names**.
Renaming any of them turns a green harness red and the rebuild will be rejected. They are the
machine-readable contract; the rebuild is a **restyle + extend**, not a rename:

`.ton-root` · `.ton-button` · `.ton-button--busy` · `.ton-overlay` · `.ton-modal` ·
`.ton-modal__close` · `.ton-cta` · `.ton-input` · `.ton-consent__box` · `.ton-upload__file` ·
`.ton-upload__hint` · `.ton-preview__img` · `.ton-loading__frame` · `.ton-result__img` ·
`.ton-action--primary` · `.ton-gallery__thumb` · `.ton-error` · `.ton-notification` ·
`.ton-notification--ready` · `.ton-notification__main` · `.ton-notification__close` ·
`.ton-club-banner*` · `.ton-banner*` · `[data-trayon-mounted]`

New classes are **additive** (`--thinking`, `--idle`, `--on-image`, `.ton-hud__*`, `.ton-strip__*`,
`.ton-skeleton`, …). The **floating status HUD IS the restyled `.ton-notification`** — same element,
same mount (`shell.js:48`), same cross-tab plumbing (`pending.js` / `resume.js`), new look and two
new modifiers. The Customer-Club modal reuses `.ton-modal` / `.ton-input` / `.ton-cta` / `.ton-error`
and therefore **inherits the new glass look for free** — its four gates must stay green.

### 2.1 On-image trigger (`.ton-button`, modifier `--on-image`)

**Purpose.** The single entry point. Sits **on** the product image, bottom-**start**, glass, blurred.
An *optional* per-site placement (D6); the classic below-add-to-cart placement is unchanged and stays
the default.

**Anatomy.** `[ sparkle SVG (gradient fill) ] [ label ]` — `--ton-glass-trigger` + `--ton-blur-trigger`,
`--ton-radius-control`, `--ton-shadow-trigger`, padding `12px 24px`, label `--ton-fs-trigger` /
`--ton-ink`. The mock's `style="…"` on the label span becomes a class. **Zero inline CSS.**

| State | Presentation | Notes |
|---|---|---|
| default | glass, ink label, gradient sparkle | Label = `button_label` (merchant) or `button.label`. |
| hover | background → `--ton-surface`, `translateY(-2px)`, `--ton-t` | Desktop only. |
| focus-visible | `--ton-ring-active` | Keyboard reachability is not optional. |
| **loading** (chunk fetching) | sparkle → spinning ring, label → `button.loading`, `aria-busy=true`, pointer-events off | §6.3. |
| **busy** (generation in flight) | `.ton-button--busy`: sparkle → spinning ring, label → `button.busy` | Existing `button.setBusy()`; survives a theme re-inject. |
| disabled / not configured | **not rendered at all** — fail silent | Never a broken button on a stranger's PDP. |

**Placement mechanics (my call — the mock is silent).** The trigger is anchored to the element
resolved by the existing `product_image` selector role (no new config). To position over it, Tray On
may make **exactly one** host-style write: set `position: relative` on that container **only when its
computed position is `static`**, reverted on teardown. Nothing else on a host node is ever written.
If the container does not resolve, or it is a bare `<img>` with no positionable block wrapper, the
trigger **falls back to `after_add_to_cart`** — the button must never vanish (the existing custom-anchor
fallback rule). *Rejected alternative:* a viewport-fixed trigger tracked with `ResizeObserver` + scroll
— jank, and it breaks on sticky galleries. Absolute positioning inside an existing box causes **no
layout shift**, so the CLS gate stays green.

**RTL.** `inset-inline-start: var(--ton-trigger-inset)` + `inset-block-end: var(--ton-trigger-inset)`.
The mock's `bottom-left` therefore mirrors to **bottom-right** in Hebrew, automatically. The sparkle
is not directional — **do not flip it**.

### 2.2 Floating status HUD (`.ton-notification`)

**Purpose.** The shopper's tether to a generation that is happening while the modal is closed. It is
the evolution of the existing notification, not a new mechanism.

**Anatomy.** `[ status dot / ring ] [ title / sub ] [ × ]` — `--ton-glass-hud` + `--ton-blur-hud`,
`--ton-radius-control`, `--ton-shadow-hud`, padding `16px 24px`, gap `--ton-space-4`,
`--ton-z-hud`. The whole body is a button (`.ton-notification__main`); the `×` is separate.

| Variant | Dot / ring | Title | Sub | On click |
|---|---|---|---|---|
| **idle** (`--idle`) | gradient dot, `ton-pulse` | `hud.idle_title` | `hud.idle_sub` (count) | Reopen the modal on the last look. |
| **thinking** (`--thinking`) | gradient **ring**, `ton-spin`, `--ton-spinner-stroke` | `hud.thinking_title` | `hud.thinking_sub` | Reopen the modal into its loading state. |
| **ready** (`--ready`) | gradient dot, `ton-pulse` | `hud.ready_title` | `hud.ready_sub` | Reopen on the finished image (re-fetches a fresh signed URL). |
| **failed** (`--error`) | `--ton-danger` dot, static | `hud.failed_title` | `hud.failed_sub` (says "not charged") | Reopen at the retry state. |
| **unavailable** (`--error`) | `--ton-danger` dot, static | `hud.unavailable_title` | `hud.unavailable_sub` | Retry the chunk fetch (§6.3). |

**When it is *not* shown.** The HUD is **hidden whenever the modal is open** (the mock does this too:
`openModal()` removes `.visible`). Two spinners in two places is noise. And a shopper who has never
generated anything **never sees an idle HUD** — a permanent floating badge with nothing to say is
visual pollution on the merchant's page and a support ticket. The idle variant appears **only after
the shopper has produced at least one look in this session and then closed the modal**, and it is
dismissible (`×` → `pending.dismiss()`, remembered).

**Rejected:** making the HUD merchant-configurable. Every new appearance key is a support cost (§7).
Its behavior is fixed.

**RTL.** The HUD is anchored `inset-inline-start: var(--ton-hud-inset)`; `inset-block-end:
var(--ton-hud-inset)`. **The mock's `left: -350px → left: 30px` slide must NOT be ported as a `left`
transition** — that is exactly the kind of physical-property rule that ships an LTR-only widget. It
enters from the **inline-start edge** via `opacity` + `transform: translateX(calc(var(--ton-dir) * -120%))
→ translateX(0)` with `--ton-t-hud`. In Hebrew `--ton-dir: -1`, so it slides in from the right. No
width guess, no magic `-350px`.

### 2.3 Modal shell (`.ton-overlay` + `.ton-modal`)

**Purpose.** The container for every in-modal surface.

**Anatomy.** Scrim `--ton-overlay` (fade `--ton-t-overlay`) → centered box: `--ton-glass-modal` +
`--ton-blur-modal`, `--ton-radius-modal`, `--ton-shadow-modal`, `--ton-modal-max-w`,
`--ton-modal-max-h` (internal scroll), padding `--ton-modal-pad` (→ `--ton-modal-pad-sm` < 480px).
Enter: `translateY(20px) scale(0.98)` + opacity 0 → `translateY(0) scale(1)` + opacity 1 with
`--ton-t-modal`.

| State | Behavior |
|---|---|
| entering | scrim fades, box rises. |
| open | focus is trapped; `Esc` closes; scrim click closes; `role="dialog"` `aria-modal="true"`. |
| closing | reverse; the HUD re-appears if there is anything to say. |
| error-boundary | any render throw → close cleanly + HUD `--error`. **Never** a half-drawn modal on a merchant's page. |

**Close (`.ton-modal__close`).** `--ton-close-size`, transparent, glyph `--ton-ink-subtle`;
hover → `--ton-accent` + `scale(1.1)`, `--ton-t`. Position **top-`inset-inline-end`** — mirrors in RTL.

**Mobile (< 480px).** Stays a centered card (not a sheet): `width: 100%`, `--ton-modal-pad-sm`,
`--ton-preview-max-h: 55vh`. The 2-column action grid survives (the labels are short).

### 2.4 Brand header

**Purpose.** Says *whose* premium moment this is, and *what* is being tried on.

**Anatomy.** Centered. Row 1: gradient sparkle (`--ton-icon-lg`) + `brand.wordmark` at `--ton-fs-logo`
/ `--ton-track-logo` / uppercase. Row 2: the product title at `--ton-fs-title` / `--ton-fw-light`,
**last word gradient-filled** (§1.4).

| State | Behavior |
|---|---|
| default | wordmark + title. |
| title missing (bootstrap gave none) | wordmark only — never an empty `<h2>` or a dangling gradient span. |
| free-tries chip present | pill at the top-**inline-start** corner (opposite the close), `--ton-radius-pill`, `--ton-fs-chip`, `--ton-glass-control`. §2.10. |

**RTL.** Centered — nothing to mirror. The wordmark stays Latin and keeps `--ton-track-logo`.

### 2.5 Preview area (`.ton-loading__frame` / `.ton-result__frame`)

**Purpose.** The one thing the shopper came for. This is where the product is judged.

**Anatomy.** A fixed-aspect box (`--ton-preview-ratio`, capped by `--ton-preview-max-h`), bed
`--ton-glass-preview`, `--ton-radius-image`, `overflow: hidden`. Contents swap by state; **the box
never changes size** (no jump between spinner and image).

| State | What the shopper sees |
|---|---|
| **empty / setup** | The upload dropzone, centered: prompt `upload.prompt` + hint `upload.hint`, dashed rule. This *is* the empty state — there is no separate "no try-ons yet" panel. |
| **photo chosen** | The shopper's own photo, `object-fit: contain`, plus a `upload.replace` affordance. **No disclaimer overlay** (it is not a generated image). |
| **loading / thinking** | The shopper's photo, heavily blurred + breathing + shimmering (the existing `ton-breathe` + `ton-shimmer` beds — keep them, they are better than a bare spinner), with the mock's loader on top: `--ton-spinner-size` ring (`--ton-spinner-track` + `--ton-accent` arc, `ton-spin`) over `loading.title` at `--ton-fs-loader`. A quiet `loading.sub` says they may close it. Cancel = closing the modal (the HUD carries it). |
| **success** | The result image, `object-fit: contain`, fading in with `--ton-t-image`. Tap to zoom (keep the existing pan/zoom). **The disclaimer overlay is present.** A glass **regenerate** icon-button sits at the top-`inset-inline-end` of the frame. |
| **error** | Ink title `result.error` + sub (`errors.network` / `loading.timeout` / `result.error_body`) + a primary `result.regenerate` CTA. The shopper is **never billed and never blamed**; the copy says so. |
| **unavailable** (merchant out of credits) | `state.out_of_credits` + `unavailable.body`. No retry CTA (there is nothing the shopper can do). Actions row hidden except close. Never mentions the merchant's balance. |
| **skeleton** (lazy chunk still loading) | A shimmering block at `--ton-radius-image`. §6.3. |

**Disclaimer overlay (`.ton-result__disclaimer`).** Always on a **generated** image, never on the
shopper's own photo. Bottom-centered, `--ton-fs-disclaimer`, `--ton-scrim-text` +
`--ton-scrim-shadow`, `pointer-events: none`, above the image. Key: `result.disclaimer`. It must
survive zoom (it is a sibling of the image, not a child).

**Low-quality result.** The backend exposes **no quality signal** (see `Q-QUALITY`, §8). We do not
invent one. The always-visible **regenerate** icon on the frame *is* the answer to "that doesn't look
like me", and `result.regenerate` reads as an invitation, not an apology.

**RTL.** The regenerate icon uses `inset-inline-end`. The disclaimer is centered. Zoom origin math is
pointer-based and direction-agnostic.

### 2.6 Thumbnail strip + Upload tile (`.ton-gallery__strip`, `.ton-gallery__thumb`)

**Purpose.** The shopper's past looks + the way to start a new one.

**A deliberate divergence from the mock.** In the mock the thumbs are *source selfies* and clicking
one **starts a generation** (`processImage(index)`). In Tray On the strip is the shopper's **past
try-on results** (the existing `GET /widget/v1/gallery` contract) and **clicking a thumb only
*views* it**. A tap that silently spends a free try or a merchant credit — with no consent checkbox
in that path — is a money-safety **and** a consent violation. Not negotiable.

**Anatomy.** Horizontal scroll, scrollbar hidden, gap `--ton-space-3`, padding-block-end
`--ton-space-4`. The **Upload tile is the first item** (`--ton-glass-control`, upload icon +
`upload.tile`), then the thumbs (newest first), `--ton-thumb-w × --ton-thumb-h`, `--ton-radius-control`,
`opacity: 0.7`.

| Element | States |
|---|---|
| Upload tile | default · hover (`--ton-surface`, `--ton-accent` icon, `translateY(-2px)`) · **disabled while a generation is in flight**. Click → the setup state (this **is** "change photo"). |
| Thumb | default (0.7) · hover (opacity 1, `translateY(-2px)`) · **active** (`--ton-ring-active`, opacity 1) · loading (shimmer placeholder while the signed URL loads) · **disabled while a generation is in flight**. Click → view that look in the preview area. |
| Strip — **empty** | Only the Upload tile. **No broken grid, no placeholder tiles, no "coming soon" text** — the dropzone in the preview area carries the invitation. |
| Strip — **error** (gallery fetch failed) | The thumbs are silently omitted; the Upload tile remains. A gallery failure must **never** block a new try-on (existing rule — keep it). |

**Viewing a past look** shows the disclaimer overlay and the action row, but **Add to Cart is
disabled** when that look's variant is not the variant currently selected on the page (a historical
look must not add the wrong SKU). Copy: `cart.variant_changed`.

**RTL.** Logical padding/gap; a native `overflow-x` strip in an RTL container starts at the right by
itself. **Do not** add a manual scroll-left reset — that is how RTL strips get shipped backwards.

### 2.7 Setup form (photo · height · optional details · **consent**)

**The mock has no consent checkbox and no height field. That is not an option.** The widget processes
a photo of the shopper's **body**; an unconsented generation is a legal problem and a release blocker
(`TS-PRIVACY-001`). Both fields keep their existing classes so the harness's consent gate keeps
passing (`.ton-consent__box`, `.ton-cta` disabled until valid).

**Layout inside the modal (setup state).** Brand header → preview area (dropzone / photo preview) →
strip → **height** (`.ton-input`, underline, cm; skipped when `ask_height` is false) → collapsed
"`details.toggle`" (body / age / gender / angle) → **consent block** → primary CTA.

| Element | States |
|---|---|
| Dropzone | empty · drag-over · invalid (`upload.errors.type` / `upload.errors.size`) · uploading · with-preview. |
| Height | empty · valid · invalid (`height.errors.range`, 50–260 cm) · focus (rule thickens to `--ton-ink`). |
| Details | collapsed (default) · expanded · filled. |
| **Consent** | unchecked (**CTA disabled**) · checked · error (`consent.required`). Copy is explicit (`consent.photo`) + a privacy link (`consent.privacy_link`) when the site configured one. |
| Primary CTA (`.ton-cta`) | disabled with a **reason label** — `cta.need_photo` → `cta.need_height` → `cta.need_consent` → enabled `cta.generate`. The shopper always knows what is missing. |

The primary CTA in setup **is the gradient CTA** (`--ton-gradient`, `--ton-shadow-cta`,
`--ton-fs-cta`, `--ton-track-cta`, uppercase, full width). Hover: `translateY(-2px)` +
`--ton-shadow-cta-hover` + label → `--ton-ink` (the mock's inversion).

### 2.8 Action row (result state) (`.ton-actions`)

The mock's 2×2 grid: **[ Save Look ] [ Share ]** on row 1, **[ Add to Cart ]** spanning row 2
(`.ton-action--primary`, the gradient CTA).

**Where the old actions went** (nothing is lost; the row stays at three buttons):

| Old action | New home |
|---|---|
| `result.regenerate` | The glass **icon button** on the preview frame (top-`inset-inline-end`). |
| `result.change_photo` | The **Upload tile** in the strip. |
| `result.change_height` | The setup state (reached via Upload) — height is pre-filled from the draft. |
| `result.back` | The modal **close** (`×`) + scrim click. |

### 2.9 The three actions

**Save Look** (secondary, `--ton-glass-control`, download-ish icon):

| Shopper | Behavior |
|---|---|
| **not registered** | Opens the **lead capture** (D4) — the existing signup screen, `POST /widget/v1/leads`. Copy explains *why*: their looks live on this device only until they have an account. On success: return to the result; the button becomes **Saved** (check glyph, `save.saved`, disabled). |
| **registered** | Immediate confirmation toast `save.already_saved` + the button becomes **Saved**. **It does not lie**: the generation is already persisted and already in their gallery; the honest wording is "Saved to your looks", not "Saving…". |
| in flight | busy spinner in the button. |
| failed | `signup.errors.network`; the button returns to default. |

**Share** (secondary):

| State | Behavior |
|---|---|
| supported (`navigator.canShare({files})`) | Fetch the **already-signed** result URL → `Blob` → `File` → `navigator.share({ files, title, text })`. No new public URL, no new privacy surface (D3). |
| sharing | button busy. |
| shared / cancelled (`AbortError`) | **Silent.** The OS sheet is its own feedback; a "shared!" toast after the user cancelled is a lie. Restore default. |
| **unsupported** (most desktops) | Fallback: **download the image** + **copy the product-page link** to the clipboard. One toast: `share.fallback_done`. |
| **failed** (blob fetch / CORS / clipboard) | Toast `share.errors.failed`. The button returns to default. |

> **`Q-SHARE-CORS` — a hard dependency, flag it now.** `navigator.share({files})` needs the image
> **bytes**, not an `<img>` src. Fetching the signed media URL cross-origin requires
> `Access-Control-Allow-Origin` for the site's allowed origins **or** a same-origin bytes endpoint
> (e.g. `GET /widget/v1/generations/{id}/image`). Without one of the two, Share can never build a
> `File` on any storefront and silently degrades to the desktop fallback everywhere — including on
> mobile, where it is the whole point. **`laravel-backend` / `railway-infra` must confirm which.**

**Add to Cart** (primary, gradient):

| State | Label | Behavior |
|---|---|---|
| idle | `result.add_to_cart` | — |
| **adding** | `cart.adding` | Button busy, disabled, `aria-busy`. |
| **added** | `cart.added` + check glyph | Stays disabled for this variant. A HUD-free, in-modal confirmation. |
| **added (optimistic)** | `cart.added` | `add.js` returned 200 but the `/cart.js` verify was inconclusive (network). We say "Added to cart" — we do **not** claim a verification we did not get, and we do **not** cry failure on a cart that probably worked. |
| **failed** | back to `result.add_to_cart` + toast `cart.error` | Copy tells them the way out: add it from the product page. |
| **sold out / unavailable** (422) | toast `cart.errors.unavailable` | A distinct, honest message — not "something went wrong". |
| **variant drifted** | disabled + `cart.variant_changed` | The look on screen belongs to a different variant than the one now selected on the page. |

### 2.10 Free-tries chip

Pill at the modal's top-`inset-inline-start`. `tries.many` / `tries.one` / **`tries.last`** /
`tries.zero`.

**The scar this closes:** a "2 free tries left" nudge that never says what happens on the third.
On the **last** try the chip turns to the warn tone and reads `tries.last` — *"Last free try — sign up
to keep creating looks"*. The shopper is never surprised by the signup wall.

### 2.11 Signup / lead-capture screen

Unchanged in mechanics (`POST /widget/v1/leads`, `LeadGate`), restyled: brand header → `signup.title`
→ `signup.why` → name / email / phone(optional) as underline `.ton-input`s → consent line → gradient
CTA `signup.submit`.

| Reached from | Title | Body |
|---|---|---|
| free tries exhausted (gate denial) | `tries.exhausted_title` | `tries.exhausted_body` |
| **Save Look** (D4) | `save.signup_title` | `save.signup_why` |

States: empty · validating · submitting · error (`signup.errors.network`) · success. On success from
the **gate** path → resume the pending try-on. On success from the **Save Look** path → return to the
result with the button showing **Saved**. **Never re-show the signup form to a shopper who just
registered** (the existing infinite-loop guard — keep it).

---

## 3. The thinking state, precisely

> *"When generation starts, the small side popup becomes a thinking circle, and stays until the
> 'image is ready' message appears."*

The full lifecycle. `M` = modal, `H` = HUD, `B` = trigger button.

| # | Event | M | H | B |
|---|---|---|---|---|
| 1 | Shopper taps `cta.generate` | preview → **loading** (blurred photo + spinner + `loading.title`; strip and actions **disabled**) | hidden (the modal is open) | **busy** |
| 2 | Shopper **closes** the modal mid-generation | torn down; the poll **keeps running** (existing rule: `close()` does not `cancelPoll()` while `state.submitting`) | **appears → `--thinking`**: spinning gradient ring, `hud.thinking_title` / `hud.thinking_sub` | **busy** (still on the page) |
| 3 | Shopper **navigates** to another page / tab mid-generation | — | `resume.js` re-attaches on the new page and shows the **`--thinking`** HUD there | absent on a non-PDP page — the HUD is the only tether, which is why it is **core** (§6) |
| 4 | Shopper **re-opens** the modal mid-generation | back into the **loading** state (never a fresh empty form) | hidden again | busy |
| 5 | **Success**, modal open | result fades in (`--ton-t-image`) | — | busy off |
| 6 | **Success**, modal closed | — | **`--ready`**: gradient dot (`ton-pulse`), `hud.ready_title` / `hud.ready_sub`. **It never auto-opens the modal** — hijacking a stranger's page is unforgivable. Tap → modal opens on the image (re-fetching a fresh signed URL; the captured one may have passed its ~10-min TTL). | busy off |
| 7 | **Failure** (`failed` / `cancelled`), modal open | preview → **error**, `result.regenerate` CTA | — | busy off |
| 8 | **Failure**, modal closed | — | **`--error`**: `--ton-danger` dot, `hud.failed_title` / **`hud.failed_sub` = "You weren't charged. Tap to try again."** | busy off |
| 9 | **Timeout** (90 s poll cap) | preview → error with `loading.timeout` | **`--error`**, same copy as (8) | busy off |
| 10 | Shopper dismisses the HUD (`×`) | — | cleared + broadcast to other tabs (`pending.dismiss()`); no zombie HUD anywhere | — |
| 11 | Another tab views/dismisses it | — | this tab's HUD clears (existing `PENDING_MSG.viewed` / `dismissed`) | — |
| 12 | Shopper closes the modal **after** a successful look | — | **`--idle`**: pulsing gradient dot, `hud.idle_title` / `hud.idle_sub` (the look count) — the re-entry affordance from the mock. Dismissible. | default |

**Why the strip and the action row are disabled during (1).** One thing happens at a time. If the
shopper could browse an old look while a new one lands, the new result would either yank the view out
from under them or land invisibly. Both are worse than a 30-second wait — and they can always close
the modal and keep shopping; the HUD carries the generation. State it, build it.

**What the HUD says when done:** `hud.ready_title` — *"Your look is ready"* — with `hud.ready_sub`
*"Tap to view it"*. That is the "image is ready" message the user asked for.

---

## 4. RTL — the mirroring rules

RTL is a **flip via logical properties**, not a redesign. `i18n.js:221-228` already sets `dir` on the
root; the CSS is already logical. Everything new obeys the same discipline.

| Element | Physical in the mock | **The rule** |
|---|---|---|
| On-image trigger | `bottom: 15px; left: 15px` | `inset-block-end` / `inset-inline-start` + `--ton-trigger-inset` → mirrors to bottom-right in HE. |
| HUD | `bottom: 30px; left: -350px → 30px` | `inset-inline-start: var(--ton-hud-inset)` + enter via `transform: translateX(calc(var(--ton-dir) * -120%))`. **Never animate `left`.** |
| Close button | `top: 20px; right: 20px` | `inset-block-start` / `inset-inline-end`. |
| Free-tries chip | — | `inset-inline-start` (opposite the close). |
| Regenerate icon on the frame | — | `inset-inline-end`. |
| Thumb strip | `overflow-x` LTR | Logical padding/gap; the browser starts an RTL strip at the right on its own. **No manual scroll reset.** |
| Brand gradient (`60deg`) | — | **Do NOT mirror.** A brand gradient carries no directional semantics; flipping it is a "helpful" bug. |
| Sparkle / upload / share / download icons | — | **Not directional. Do not flip.** Only a directional glyph (a back arrow) would use the existing `.ton-glyph--directional`. |
| CTA letter-spacing | `3px` | `--ton-track-cta: 0` under `[dir='rtl']` — letter-spacing shatters Hebrew. Same for the loader and disclaimer tracking. |
| Wordmark | `letter-spacing: 4px` | Stays — it is Latin in both locales. |
| Numbers in copy (`:count`) | — | Interpolated, never concatenated; the catalog carries the whole sentence per locale. |

---

## 5. Copy — every flow, every state (EN authoritative · HE 1:1)

### 5.1 i18n catalogue

Keys land in the inline catalogue (`resources/widget/src/i18n.js`). **`core` keys must be in the
always-on bundle; `lazy` keys ship with the modal chunk** (§6) — that partition is a spec decision,
made here.

| Key | Chunk | EN | HE |
|---|---|---|---|
| `brand.wordmark` | core | `TRAY ON` | `TRAY ON` |
| `button.label` | core | `Try it on` | `מדדו את זה` |
| `button.loading` | core | `Loading…` | `טוען…` |
| `button.busy` | core | `Creating…` | `יוצרים…` |
| `hud.idle_title` | core | `Your Tray On looks` | `הלוקים שלכם ב-Tray On` |
| `hud.idle_sub` | core | `:count ready to view` | `:count מוכנים לצפייה` |
| `hud.thinking_title` | core | `Creating your look` | `יוצרים את הלוק שלכם` |
| `hud.thinking_sub` | core | `Keep browsing — we'll tell you when it's ready` | `אפשר להמשיך לגלוש — נעדכן כשיהיה מוכן` |
| `hud.ready_title` | core | `Your look is ready` | `הלוק שלכם מוכן` |
| `hud.ready_sub` | core | `Tap to view it` | `הקישו כדי לראות` |
| `hud.failed_title` | core | `Your look didn't finish` | `הלוק לא הושלם` |
| `hud.failed_sub` | core | `You weren't charged. Tap to try again.` | `לא חויבתם. הקישו כדי לנסות שוב.` |
| `hud.unavailable_title` | core | `Try-on couldn't load` | `לא הצלחנו לטעון את המדידה` |
| `hud.unavailable_sub` | core | `Check your connection and tap to retry` | `בדקו את החיבור והקישו לניסיון חוזר` |
| `hud.close` | core | `Dismiss` | `סגירה` |
| `modal.close` | lazy | `Close` | `סגירה` |
| `upload.tile` | lazy | `Upload` | `העלאה` |
| `upload.prompt` | lazy | `Add a photo of yourself` | `הוסיפו תמונה שלכם` |
| `upload.hint` | lazy | `A clear, well-lit, full-body photo works best` | `תמונה ברורה, מוארת ובגוף מלא תעבוד הכי טוב` |
| `upload.replace` | lazy | `Change photo` | `החלפת תמונה` |
| `upload.uploading` | lazy | `Uploading…` | `מעלים…` |
| `upload.errors.type` | lazy | `Please use a JPG, PNG or WebP image` | `אנא השתמשו בתמונת JPG, PNG או WebP` |
| `upload.errors.size` | lazy | `That image is too large` | `התמונה גדולה מדי` |
| `upload.errors.failed` | lazy | `We couldn't read that image. Try another one.` | `לא הצלחנו לקרוא את התמונה. נסו אחרת.` |
| `height.label` | lazy | `Your height` | `הגובה שלכם` |
| `height.unit_cm` | lazy | `cm` | `ס"מ` |
| `height.errors.range` | lazy | `Enter a height between :min and :max cm` | `הזינו גובה בין :min ל-:max ס"מ` |
| `details.toggle` | lazy | `Add details (optional)` | `הוספת פרטים (לא חובה)` |
| `details.body` | lazy | `Body type` | `מבנה גוף` |
| `details.age` | lazy | `Age range` | `טווח גיל` |
| `details.gender` | lazy | `Gender` | `מגדר` |
| `details.angle` | lazy | `Photo angle` | `זווית הצילום` |
| `consent.photo` | lazy | `I agree to let Tray On use my photo to generate a virtual try-on of this product.` | `אני מאשר/ת ל-Tray On להשתמש בתמונה שלי כדי ליצור הדמיה של המוצר עליי.` |
| `consent.privacy_link` | lazy | `How we handle your photo` | `איך אנחנו מטפלים בתמונה שלכם` |
| `consent.required` | lazy | `Please agree before we create your look` | `יש לאשר לפני יצירת הלוק` |
| `cta.generate` | lazy | `Create my look` | `יצירת הלוק שלי` |
| `cta.need_photo` | lazy | `Add a photo to continue` | `הוסיפו תמונה כדי להמשיך` |
| `cta.need_height` | lazy | `Add your height to continue` | `הוסיפו גובה כדי להמשיך` |
| `cta.need_consent` | lazy | `Agree to the terms to continue` | `אשרו את התנאים כדי להמשיך` |
| `loading.title` | lazy | `Creating your look…` | `יוצרים את הלוק שלכם…` |
| `loading.sub` | lazy | `This takes a few seconds. You can close this — we'll let you know.` | `זה לוקח כמה שניות. אפשר לסגור — נעדכן אתכם.` |
| `loading.timeout` | lazy | `This is taking longer than usual. You weren't charged — try again?` | `זה לוקח יותר מהרגיל. לא חויבתם — לנסות שוב?` |
| `result.title` | lazy | `Here's your look` | `הנה הלוק שלכם` |
| `result.disclaimer` | lazy | `* For visualization only. The actual fit may vary.` | `* להמחשה בלבד. הגזרה בפועל עשויה להשתנות.` |
| `result.zoom_hint` | lazy | `Tap to zoom` | `הקישו להגדלה` |
| `result.regenerate` | lazy | `Create it again` | `ליצור שוב` |
| `result.error` | lazy | `Something went wrong` | `משהו השתבש` |
| `result.error_body` | lazy | `You weren't charged. Let's try that again.` | `לא חויבתם. בואו ננסה שוב.` |
| `result.add_to_cart` | lazy | `Add to cart` | `הוספה לעגלה` |
| `gallery.title` | lazy | `Your looks` | `הלוקים שלכם` |
| `gallery.view` | lazy | `View this look` | `צפייה בלוק הזה` |
| `gallery.viewing` | lazy | `Your look` | `הלוק שלכם` |
| `save.action` | lazy | `Save Look` | `שמירת הלוק` |
| `save.saved` | lazy | `Saved` | `נשמר` |
| `save.already_saved` | lazy | `Saved to your looks` | `נשמר ללוקים שלכם` |
| `save.signup_title` | lazy | `Save your looks` | `שמרו את הלוקים שלכם` |
| `save.signup_why` | lazy | `Create a free account and your looks stay with you — on any device, any time.` | `פתחו חשבון חינם והלוקים יישארו אתכם — בכל מכשיר, בכל זמן.` |
| `share.action` | lazy | `Share` | `שיתוף` |
| `share.title` | lazy | `My Tray On look` | `הלוק שלי ב-Tray On` |
| `share.text` | lazy | `Here's how :product looks on me.` | `ככה :product נראה עליי.` |
| `share.fallback_done` | lazy | `Image saved. Link copied.` | `התמונה נשמרה. הקישור הועתק.` |
| `share.errors.failed` | lazy | `We couldn't share that. Please try again.` | `לא הצלחנו לשתף. אנא נסו שוב.` |
| `cart.adding` | lazy | `Adding…` | `מוסיפים…` |
| `cart.added` | lazy | `Added to cart` | `נוסף לעגלה` |
| `cart.error` | lazy | `We couldn't add it to your cart — please add it from the product page.` | `לא הצלחנו להוסיף לעגלה — אנא הוסיפו מדף המוצר.` |
| `cart.errors.unavailable` | lazy | `That option isn't available right now.` | `האפשרות הזו לא זמינה כרגע.` |
| `cart.variant_changed` | lazy | `This look is of a different option than the one selected.` | `הלוק הזה מתייחס לאפשרות אחרת מזו שנבחרה.` |
| `tries.zero` | lazy | `No free tries left` | `לא נותרו ניסיונות חינם` |
| `tries.one` | lazy | `:count free try left` | `נותר :count ניסיון חינם` |
| `tries.many` | lazy | `:count free tries left` | `נותרו :count ניסיונות חינם` |
| `tries.last` | lazy | `Last free try — sign up to keep creating looks` | `ניסיון חינם אחרון — הירשמו כדי להמשיך ליצור לוקים` |
| `tries.exhausted_title` | lazy | `Sign up to keep creating` | `הירשמו כדי להמשיך` |
| `tries.exhausted_body` | lazy | `Create a free account to keep making looks.` | `פתחו חשבון חינם כדי להמשיך ליצור לוקים.` |
| `signup.title` | lazy | `Quick sign-up` | `הרשמה מהירה` |
| `signup.name` | lazy | `Full name` | `שם מלא` |
| `signup.email` | lazy | `Email` | `אימייל` |
| `signup.phone_optional` | lazy | `Phone (optional)` | `טלפון (לא חובה)` |
| `signup.why` | lazy | `We use this to keep your looks and let you know when they're ready.` | `אנחנו משתמשים בזה כדי לשמור את הלוקים ולעדכן אתכם כשהם מוכנים.` |
| `signup.consent` | lazy | `I agree to Tray On creating an account for me to save my looks.` | `אני מאשר/ת ל-Tray On לפתוח עבורי חשבון לשמירת הלוקים.` |
| `signup.submit` | lazy | `Continue` | `המשך` |
| `signup.errors.network` | lazy | `We couldn't sign you up. Please try again.` | `ההרשמה נכשלה. אנא נסו שוב.` |
| `state.out_of_credits` | lazy | `Try-on isn't available right now` | `המדידה אינה זמינה כרגע` |
| `state.rate_limited` | lazy | `Too many requests — please wait a moment and try again` | `יותר מדי בקשות — אנא המתינו רגע ונסו שוב` |
| `state.limit_reached` | lazy | `You've used all your looks` | `ניצלתם את כל הלוקים שלכם` |
| `state.limit_reached_body` | lazy | `Thanks for trying! You have no more looks available right now.` | `תודה שניסיתם! אין לכם כרגע לוקים נוספים זמינים.` |
| `unavailable.body` | lazy | `Please check back soon.` | `אנא נסו שוב בקרוב.` |
| `errors.generic` | lazy | `Something went wrong. Please try again.` | `משהו השתבש. אנא נסו שוב.` |
| `errors.network` | lazy | `Check your connection and try again.` | `בדקו את החיבור ונסו שוב.` |

**Retained, unchanged:** every `club.*` key (the Customer Club is out of scope for the redesign and
its copy does not move). **Removed:** `result.change_photo`, `result.change_height`, `result.back`,
`gallery.back`, `notify.*` (superseded by `hud.*`), `modal.title` (superseded by `brand.wordmark`).
`widget-embed` deletes them in the same commit — a dead key is a future mistranslation.

**Rules.** Named placeholders only (`:count`, `:product`, `:min`, `:max`). Counts go through
`tries()` (the existing choice helper). **A key with an empty HE value is a release blocker.**

### 5.2 Consent copy (non-negotiable)

The generate CTA is **disabled** until the box is checked; an attempted submit shows
`consent.required`. The disclosure is explicit — it names the photo, the purpose, and the product —
never a bare "I agree". The privacy link renders only when the site configured a privacy URL, and it
opens in a new tab (`rel="noopener"`).

### 5.3 The shopper with no credits *(the merchant is out — not the shopper)*

Preview area → `state.out_of_credits` + `unavailable.body`. **No** retry CTA. **No** mention of
credits, balance, money, or the merchant. **No** 402/500 surfaced. The shopper did nothing wrong and
is told nothing they can't act on. The merchant, meanwhile, gets the actionable banner in the admin
(`merchant.credit.empty` — a different surface, a different audience).

### 5.4 A failed generation

`result.error` + `result.error_body` (*"You weren't charged. Let's try that again."*) + a primary
`result.regenerate`. **Never a stack trace, never an error code, never a charge** (ARCHITECTURE.md:
reserve → debit only on success). The HUD equivalent is `hud.failed_title` / `hud.failed_sub`, which
carries the same "you weren't charged" promise.

### 5.5 Save Look

Not-registered → the signup screen with `save.signup_title` / `save.signup_why`. The "why" is
**true**: an anonymous shopper's looks are bound to a `localStorage` anon-token that a cleared browser
destroys; an account attaches them to a real `EndUser`. Registered → `save.already_saved` and the
button reads `save.saved`. **Never** claim a save the backend didn't make (`Q-SAVE`, §8).

### 5.6 An unsupported Share

Desktop / no `canShare({files})` → the fallback runs **without asking**: download the image, copy the
product URL, one toast `share.fallback_done`. A "your browser doesn't support sharing" message is a
dead end and we don't ship dead ends. A genuine failure (blob/CORS/clipboard) → `share.errors.failed`.
A user-cancelled OS sheet → **silence**.

### 5.7 A failed cart add

`cart.error` names the escape hatch ("add it from the product page"). A 422 gets the honest
`cart.errors.unavailable`. Neither ever blames the shopper, and neither is a raw status code.

---

## 6. The split bundle — core vs lazy, and the first 200 ms

`resources/widget/size-budget.json`: entry ≤ **25,600 B** gzip (currently **25,221 B** — 379 B free),
lazy ≤ **20,480 B** (the unused `maxLazyGzipBytes` slot). The build **fails** over budget.

### 6.1 CORE (always on every page — this is what LCP/CLS pay for)

Everything needed to (a) mount the trigger, (b) render the **HUD in all five variants**, (c) **resume
and poll** a generation across pages/tabs, and (d) fail gracefully:

`loader` · `constants` · `dom` · `state` · `api` · `pdp` · `shell` · `button` (both placements,
incl. on-image) · **HUD** (`.ton-notification`) · `pending` · `resume` · the **status poll** ·
`track` · the **core i18n keys** (`brand.*`, `button.*`, `hud.*`) · **core CSS** (the token block +
`.ton-button*` + `.ton-notification*` + the skeleton) · the **modal skeleton shell** (§6.3).

> The HUD **cannot** be lazy: on a non-PDP page there is no trigger to click, and the HUD is the
> shopper's only tether to a generation started elsewhere (§3, row 3).

### 6.2 LAZY

**Chunk A — the modal** (fetched on the first trigger interaction): the modal shell, brand header,
preview area (upload/loading/result/error), the strip, the action row, Save/Share/Cart, signup,
zoom, image prep, `generation.submit`, the **lazy i18n keys**, the **modal CSS**, and the **Outfit
woff2**.

**Chunk B — club + banners** (fetched on **idle**, not on click): `club.js`, `banners.js`, their CSS
and keys. They are site-wide but never on the first-paint path. *Caveat for `widget-embed`:* the
member-price rewrite already runs post-bootstrap on idle, so this adds ~1 cached RTT and no new
class of delay — but the four club gates in `verify.mjs` must be re-run to prove the banner still
appears within its trigger window. If they can't stay green, keep club in core and take the bytes out
of chunk A instead. **Escalate to `trayon-orchestrator` rather than shipping a red gate.**

**CSS split.** `widget-core.css` (tokens + trigger + HUD + skeleton) is inlined into **both** shadow
roots (the trigger has its own root; the shell has the other). `widget-modal.css` is appended to the
**shell's** root when chunk A lands — it references tokens already declared by the core sheet. No
token is declared twice.

**The font is not a bundle cost** — it is a separate `woff2` fetch, so the JS budget does not force
it lazy. **Host-page performance does:** we will not put a webfont request on a merchant's PDP for a
shopper who never engages. It ships with chunk A.

### 6.3 What the shopper sees while chunk A loads — **the UX decision**

| Phase | What happens |
|---|---|
| **Prefetch (free win)** | On `pointerenter` / `focus` / `touchstart` on the trigger, start fetching chunk A. Most clicks then find it warm and the modal opens instantly. A hint, never a guarantee. |
| **t = 0** (tap) | The **trigger itself** becomes the progress indicator: sparkle → spinning ring, label → `button.loading`, `aria-busy="true"`, pointer-events off (no double-open). **Nothing else appears.** The feedback is exactly where the shopper's finger and eyes already are, and it costs zero CLS. |
| **t < 250 ms** (the common case) | The chunk lands; the modal opens with its normal enter transition. The shopper perceives one instant tap. **We do not flash an empty modal for 200 ms** — a skeleton that appears and is replaced within a quarter-second reads as a glitch, not as polish. |
| **t ≥ 250 ms** (`CHUNK_SHELL_DELAY_MS`) | Now the wait is real, so we acknowledge it: the **modal shell opens** (scrim + glass box + brand header + close) with a **shimmering skeleton** at `--ton-radius-image` where the preview will be, and no action row. When the chunk lands, the real content cross-fades in over `--ton-t-image`. This shell is small and lives in **core** — that is the deliberate byte cost of never leaving a tap unanswered. |
| **t ≥ 8 s or fetch error** (`CHUNK_TIMEOUT_MS`) | The shell closes; the trigger returns to default; the **HUD appears in `--error`**: `hud.unavailable_title` / `hud.unavailable_sub`. Tapping it retries the fetch. **Never** a half-drawn modal, never a host-page exception, never a silent dead button. |

Both thresholds are constants in `constants.js` (`CHUNK_SHELL_DELAY_MS = 250`, `CHUNK_TIMEOUT_MS = 8000`),
not literals mid-file (CONST-at-top).

### 6.4 The font swap that must not happen

The core surfaces (**trigger + HUD**) are locked to `--ton-font-system` **permanently**. They render
before Outfit exists and would otherwise visibly re-flow the moment the modal chunk loads Outfit —
a flicker on the merchant's product image. The modal (its own root) uses `--ton-font` (Outfit). This
is a conscious, stated trade: the trigger's label is a 15px system-font string; the modal is the
jewelry.

---

## 7. Per-site appearance config — exactly what changes

> **Every new key is a support cost.** The rebuild adds **zero new keys** and **one new enum value**.

| Key | Change | Applies to |
|---|---|---|
| `button_placement` | **+ one value: `on_product_image`** (`WidgetAppearance::PLACEMENT_ON_IMAGE`), added to `PLACEMENTS`, surfaced on the merchant Appearance page. Optional; the default stays `after_add_to_cart`. Falls back to `after_add_to_cart` when the `product_image` selector doesn't resolve. | The trigger. |
| `button_label` | unchanged | Both trigger placements. |
| `button_bg`, `button_text_color` | unchanged **but scoped**: they paint the **classic** button only. The on-image trigger is **glass by definition** — it ignores them (its label is `--ton-ink`, its sparkle is the fixed gradient). Otherwise a merchant with `button_bg: #0a7d52` who switches to on-image gets a green glass slab on their product photo. | Classic button only. |
| `popup_theme` | unchanged (`light` / `dark`) — dark values in §1.2. | The modal + HUD. |
| `popup_accent` | **Still persisted and validated (no migration, no breaking change) — but the redesigned modal no longer reads it.** The modal's identity (gradient, glass, radii, motion) is **fixed**. `admin-design-system` relabels the field on the merchant page to **"Button accent colour"** and scopes its help text to the button. A key that silently paints nothing is worse than a key that clearly paints one thing. | Classic button only. |
| `ask_height`, `custom_anchor_selector`, `custom_position` | unchanged. | — |

**FIXED — never merchant-configurable** (say no now, or support it forever): the gradient and its
three stops · the glass surfaces and blur radii · every radius · every shadow · the type scale and
weights · every motion duration and easing · the HUD's existence, position, and behavior · the
disclaimer text's presence · the presence of the consent checkbox.

**The `product_image` selector role already exists** (`SELECTOR_ROLES.productImage`, supplied by
`pdp-scanner`). On-image placement reuses it. No new selector, no new scan field.

---

## 8. Open contracts (`TODO-DATA`) — confirm before build

| # | Question | Owner | Default this spec assumes |
|---|---|---|---|
| **Q-SHARE-CORS** | Can the signed result URL be fetched as a **blob** from a storefront origin (CORS), or must we add `GET /widget/v1/generations/{id}/image` (same-origin bytes)? **Share cannot exist without one of the two.** | `laravel-backend` + `railway-infra` | Assume a same-origin bytes endpoint is needed. **Blocking for §2.9 Share.** |
| **Q-SAVE** | Is there (or should there be) a `saved` / favourite flag on `generation`, or is presence in `GET /gallery` the save? | `laravel-backend` | Presence in the gallery **is** the save. Save Look = lead capture (D4) + confirmation. |
| **Q-LEAD-ATTACH** | Does `POST /widget/v1/leads` attach the anon-token's **existing** generations to the new `EndUser`? The Save Look copy ("your looks stay with you") is only true if it does. | `laravel-backend` | It does (the lead upgrades the same `end_user` row keyed by `anon_token`). **If not, `save.signup_why` must change.** |
| **Q-CART-ID** | `ProductPayload::variant()` must expose `external_id` (the real Shopify numeric variant id) — today it strips it. | `laravel-backend` | Exposed. Required by D5. |
| **Q-QUALITY** | Is there any model-side signal for a poor result? | `ai-openrouter` | **No.** Do not invent one; the regenerate affordance is the answer. |

---

## 9. Definition of Done — the rebuild

### 9.1 Existing Playwright gates that must stay green (`node tests/widget/verify.mjs`)

- Static: the embed snippet is `<script async>`, **not** `type=module`; the entry bundle is within
  `maxGzipBytes`.
- Mount: the button is mounted **exactly once**, **below** add-to-cart (classic placement), and
  re-injects after an SPA `cloneNode()` re-render **without duplicating** (`TS-WIDGET-004`).
- Isolation: the button lives in a **ShadowRoot**; the host's `.ton-button { background: lime;
  font-size: 40px }` does **not** bleed in; **no widget markup leaks into the host light DOM**.
- Appearance: label + `button_bg` come from the config (classic placement).
- Variant sync: selecting a variant updates `state.variant`.
- Consent: `.ton-cta` is **disabled** on modal open until photo + height + consent.
- `dir`: the root is `ltr` in EN and `rtl` in HE.
- Non-PDP (`product: null`): **nothing mounts**, no errors.
- Perf: **no synchronous work before `load`** (idle-only boot); host **CLS < 0.02**.
- Tracking: exactly one `page_view` + only the meaningful interactions.
- Async notification / cross-page resume / cross-tab: close mid-generation → notify → reopen; reload
  mid-generation → notify on the fresh page; completion in tab 1 clears tab 2's popup.
- Gallery: the strip loads and a thumb opens the look.
- Custom placement + its runtime fallback.
- Customer Club: banner (EN + HE), login flow, typed failures, member pricing, banner behavior,
  merchant-banner runtime.
- Screenshots: EN + HE, `widget-pdp.*.png` + `widget-modal.*.png`.

### 9.2 New gates this rebuild must add

- **Split budget:** entry ≤ `maxGzipBytes` **and** each lazy chunk ≤ `maxLazyGzipBytes`. Both
  enforced in `build.config.mjs` (over budget → non-zero exit).
- **On-image placement:** with `button_placement: on_product_image` the trigger renders **inside the
  product-image container**, exactly once; with an unresolvable `product_image` selector it **falls
  back** to below add-to-cart; **host CLS stays < 0.02** in both cases.
- **Lazy-chunk UX:** with the chunk request delayed > 250 ms, the **skeleton shell** appears; with the
  chunk request **failed**, the HUD shows `--error` and the trigger returns to default (no orphan
  modal, no host exception).
- **HUD lifecycle:** generating → `--thinking` on close; success → `--ready`; failure → `--error` with
  the "not charged" copy; a click reopens to the right state; `×` clears it in **every** tab.
- **Real add-to-cart:** `POST /cart/add.js` carries the **numeric external variant id** + the
  `_trayon` line-item property, and the item is **verified present in `/cart.js`**. `cart.adding` →
  `cart.added`; a 422 renders `cart.errors.unavailable`.
- **Share:** `canShare({files})` true → `navigator.share` is called with a real `File`; false → the
  download + copy-link fallback runs and `share.fallback_done` is shown; an `AbortError` shows
  **nothing**.
- **Save Look:** an unregistered shopper is taken to the signup screen; on success they return to the
  **result** (not to an empty form) and the button reads `save.saved`.
- **Screenshots (EN + HE), new:** on-image trigger · modal setup · modal thinking · modal result ·
  HUD thinking · HUD ready · signup.

### 9.3 Spec-completeness gates (mine)

- Every surface in §2 has **empty / loading / success / error** written.
- Every visible string has a key, and **every key has a non-empty HE value**.
- Every direction-sensitive rule from the mock is respecified **logically** in §4 — **no `left`,
  `right`, `margin-left`, or `text-align: left` anywhere in the rebuilt CSS**.
- **No literal** (`#f472b6`, `24px`, `0.4s`, `cubic-bezier(...)`) appears in a component rule — only
  `var(--ton-*)` from §1.
- **Zero inline CSS** — the mock's `style="font-weight:600…"` on the trigger label becomes a class.
- `prefers-reduced-motion` is honored (§1.6).
- The consent checkbox exists, is required, and its copy names the photo and the purpose.
- Every `TODO-DATA` in §8 is answered or explicitly deferred with its default behavior shipped.

---

## 10. Handoff

**Status: `ready` for `widget-embed`, with two data confirmations outstanding** —
`Q-SHARE-CORS` (blocks Share only) and `Q-LEAD-ATTACH` (blocks the *wording* of `save.signup_why`
only). Neither blocks the restyle, the split, the HUD, the on-image trigger, or the cart.

Build order that keeps the harness green at every step:

1. Tokens + core CSS (§1) — restyle in place, class names untouched.
2. The bundle split (§6) + the skeleton shell + the two constants.
3. The HUD (§2.2, §3) — the existing `.ton-notification`, restyled and extended.
4. The on-image trigger (§2.1) + the one enum value (§7).
5. The modal surfaces (§2.3–2.8) + the i18n catalogue (§5.1).
6. Save Look → lead gate (§2.9), Share (§2.9), real Add to Cart (§2.9).
7. The new gates (§9.2). Then `code-review-gatekeeper`.

Blockers found and resolved during the build go to `troubleshooting-archivist`.
