# Widget Performance Contract (Phase 1c — widget-embed)

> The storefront script (`widget.js`) runs inside someone else's page. It must be a
> **weightless guest**: never block the host's render, LCP, CLS, or SEO, and never
> grow past its byte budget. This is a hard contract, mechanically enforced by
> `tests/widget/verify.mjs` — a regression here fails the whole widget run.

## The four guarantees

1. **Non-blocking install.**
   The embed snippet is a classic **`<script async>`** (never render-blocking, never
   `type=module`), authored once in
   `resources/views/components/to/embed-code.blade.php`. The browser downloads it off
   the critical path and never waits on it to paint.

2. **Zero synchronous work on load.**
   The entry (`resources/widget/src/loader.js`) does only the cheapest synchronous
   thing at parse time — read `data-site-key`, set the API base, set the double-boot
   **guard flag** — then schedules the real boot via **`requestIdleCallback`**
   (`BOOT_IDLE_TIMEOUT_MS`, `dom.js onIdle`, with a `setTimeout` fallback). No config
   fetch, no shadow host, no button, no layout read happens before `load`. Every new
   widget feature **must ride this same idle path** — never a synchronous hook.

3. **No layout shift (CLS ≈ 0).**
   The widget renders inside a **Shadow DOM** host (`#trayon-host`, `all: initial`)
   whose overlay/notification mounts are `position: fixed` — they take no flow space.
   The injected button is added after the merchant's add-to-cart anchor with reserved
   boxes. Nothing the widget does (mount, tracking, and any future price rewrite) may
   move a host node. The gate asserts host CLS `< 0.02` (Google's "good" is `< 0.1`).

4. **Under the gzip budget.**
   The entry bundle (`public/widget/v1/widget.js`, esbuild IIFE) is gzip-sized against
   `resources/widget/size-budget.json` (`maxGzipBytes`, currently 25,600; the build is
   ~18.9 KB). The lazy modal/result code is bundled in the single IIFE — the snippet is
   a classic async script, so dynamic `import()` is not available; weight is held down
   by keeping the entry small, not by a second chunk.

## Activity tracking rides the same rails (Phase 1d)

`resources/widget/src/track.js` records **one `page_view` per load** + **meaningful
interactions only** (`product_view`, `variant_change`, `tryon_open`, `add_to_cart`) —
never arbitrary DOM clicks. It is initialised from the loader's existing idle boot
(`track.init`), batches into a small queue (`TRACK_MAX_QUEUE`), and **flushes
fire-and-forget** on idle / `pagehide` / `visibilitychange→hidden` via
`api.recordEvents` (`navigator.sendBeacon` when available, else `fetch` **keepalive**).
It touches no host DOM, so it cannot cause CLS, and it never blocks the interaction
that triggered it or throws into the host page. It is consent-respecting: the per-site
bootstrap flag `tracking_enabled === false` disables it entirely; absent ⇒ on.

Ingest contract (must match `App\Http\Controllers\Widget\EventController`):
`POST {apiBase}/events` with `{ anon_token, events: [ { kind, at, path, referrer_host?,
interaction?: { type, label? } } ] }`, auth by `X-Tray-Site-Key` header + `Origin`
(the beacon path carries `?site_key=` since a beacon can't set a header). Only a bare
`path` (query/fragment stripped) + `referrer_host` + interaction `type`/`label` are
sent — never a full URL, query string, or PII payload.

## How it is enforced

`tests/widget/verify.mjs`:

- **static perf gates** — assert the blade snippet is `<script async>` and not
  `type=module`, and that the built bundle is within the gzip budget.
- **perf + tracking gate** — a real page load with a `layout-shift` PerformanceObserver
  installed before any page script. Asserts: no shadow host / button / resolved config
  at the `load` event (proves idle-only boot); exactly one `page_view` + all four
  meaningful interactions + **no arbitrary/unknown types**; the payload matches the
  ingest contract (no query string); and host **CLS `< 0.02`**.

Run: `npm run build` (gzip gate) then `node tests/widget/verify.mjs` (all gates).
