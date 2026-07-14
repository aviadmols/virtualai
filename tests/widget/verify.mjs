// =============================================================================
// Tray On widget verification harness (Playwright). Proves the BROWSER side against a
// mock PDP, with the signed widget API stubbed via route interception. Asserts:
//   - boots only on a PDP (product != null); the button mounts BELOW add-to-cart, once;
//   - the per-site appearance (placement, label, colors) is applied;
//   - no host-CSS bleed in / no widget-CSS bleed out (Shadow DOM);
//   - SPA re-render -> the button is re-injected, still exactly one;
//   - variant change propagates to window.__TrayOn.state.variant;
//   - the modal opens (consent gates the CTA);
//   - add-to-cart triggers the host's real add-to-cart element;
//   - EN + HE/RTL screenshots.
//
// Run (after `npm run build:widget`):  node tests/widget/verify.mjs
// =============================================================================

import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { mkdirSync } from 'node:fs';
import { deflateSync, crc32 } from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

// === CONSTANTS ===
const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const DIST = resolve(ROOT, 'public', 'widget', 'v1');
const WIDGET_JS = join(DIST, 'widget.js');
const MOCK_HTML = resolve(HERE, 'mock-pdp.html');
const MOCK_SHOPIFY_HTML = resolve(HERE, 'mock-pdp-shopify.html');
const EMBED_BLADE = resolve(ROOT, 'resources', 'views', 'components', 'to', 'embed-code.blade.php');
const SIZE_BUDGET = resolve(ROOT, 'resources', 'widget', 'size-budget.json');
const OUT = resolve(HERE, 'screenshots');
const PORT = 4599;
const ORIGIN = `http://localhost:${PORT}`;
const SHOPIFY_PATH = '/shopify';
const SENTINEL = 'data-trayon-mounted';

// The split bundle: the CORE is measured against maxGzipBytes, EVERY lazy chunk against
// maxLazyGzipBytes. Both are enforced by the build too — this gate makes a regression visible
// in the harness output as well as at the build step.
const CORE_BUNDLE = 'widget.js';
const LAZY_BUNDLES = ['widget.modal.js', 'widget.club.js'];

// The Shopify numeric variant ids the mock storefront + the stubbed ProductPayload agree on.
const SHOPIFY_VARIANT_S = '1111111111';
const SHOPIFY_VARIANT_M = '2222222222';
const TRAYON_LINE_PROPERTY = '_trayon';

// Perf gate: the total layout shift the widget may cause on the host page must stay well
// under Google's "good" CLS threshold (0.1). The widget renders in a Shadow DOM + reserves
// its boxes, so its own contribution should be ~0; we assert a strict ceiling.
const CLS_BUDGET = 0.02;

mkdirSync(OUT, { recursive: true });

// --- A tiny static server: serves the mock PDPs + the built bundles/fonts (a real origin). ---
// The lazy chunks are fetched with a plain <script src>, and the webfont with a CSS url(), both
// against the origin that served widget.js — so they must be served here exactly as production
// serves them out of public/widget/v1/.
function startServer() {
  return new Promise((resolveServer) => {
    const server = createServer((req, res) => {
      const path = req.url.split('?')[0];

      if (path.startsWith('/widget/v1/') && path.endsWith('.js')) {
        const file = join(DIST, path.slice('/widget/v1/'.length));
        if (!existsSync(file)) {
          res.writeHead(404).end('missing bundle: ' + path);
          return;
        }
        res.writeHead(200, { 'Content-Type': 'application/javascript' });
        res.end(readFileSync(file));
        return;
      }

      if (path.startsWith('/widget/v1/fonts/')) {
        const file = join(DIST, path.slice('/widget/v1/'.length));
        if (!existsSync(file)) {
          res.writeHead(404).end('missing font');
          return;
        }
        res.writeHead(200, { 'Content-Type': 'font/woff2' });
        res.end(readFileSync(file));
        return;
      }

      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(readFileSync(path.startsWith(SHOPIFY_PATH) ? MOCK_SHOPIFY_HTML : MOCK_HTML));
    });
    server.listen(PORT, () => resolveServer(server));
  });
}

// --- The stubbed bootstrap payload (matches BootstrapController's shape). ---
function bootstrapBody(locale) {
  return {
    ok: true,
    site: {
      locale,
      selectors: {
        add_to_cart: { primary: '#add-to-cart' },
        product_image: { primary: '.product__image' },
        title: { primary: '.product__title' },
        price: { primary: '.product__price' },
        variations: { primary: '#variant' },
      },
      gallery: {},
      privacy: { url: `${ORIGIN}/privacy` },
      free_generations_before_signup: 3,
      appearance: {
        button_placement: 'after_add_to_cart',
        button_label: locale === 'he' ? 'מדדו את זה' : 'Tray On',
        button_bg: '#0a7d52',
        button_text_color: '#ffffff',
        popup_theme: 'light',
        popup_accent: '#0a7d52',
      },
    },
    lead: { registered: false, free_remaining: 3, signup_required: false },
    // Customer Club: OFF by default so the existing gates stay a complete no-op; the club/pricing
    // gates below override this block with an enabled/member config.
    club: { enabled: false, discount_percent: 0, price_zones: { pdp: [], catalog: [], cart: [] }, member: { verified: false } },
    // Merchant banners: none by default (existing gates unaffected); the banner gate overrides this.
    banners: [],
    product: {
      id: 42,
      name: 'Aviad Linen Tee',
      description: 'A relaxed-fit linen tee.',
      product_type: 'apparel',
      price_minor: 8900,
      currency: 'USD',
      main_image_url: null,
      images: [],
      // ProductPayload's `source` (scan | shopify). A scanned product has no external ids.
      source: 'scan',
      variants: [
        { id: 1, external_id: null, options: { size: 'S' }, price_minor: 8900, image_url: null, sku: 'TEE-S', available: true },
        { id: 2, external_id: null, options: { size: 'M' }, price_minor: 8900, image_url: null, sku: 'TEE-M', available: true },
      ],
    },
  };
}

/**
 * The bootstrap for a SHOPIFY-sourced product: ProductPayload ships `source: 'shopify'` and the
 * NUMERIC Shopify variant id on every variant (`external_id`) — the id /cart/add.js actually
 * speaks. Our internal `id` stays our DB key; confusing the two is precisely the bug this gate
 * exists to prevent.
 */
function shopifyBootstrapBody(locale) {
  const body = bootstrapBody(locale);
  body.site.selectors.product_image = { primary: '.product__image' };
  body.product.source = 'shopify';
  body.product.variants = [
    { id: 1, external_id: SHOPIFY_VARIANT_S, options: { size: 'S' }, price_minor: 8900, image_url: null, sku: 'TEE-S', available: true },
    { id: 2, external_id: SHOPIFY_VARIANT_M, options: { size: 'M' }, price_minor: 8900, image_url: null, sku: 'TEE-M', available: true },
  ];
  return body;
}

/**
 * A working stand-in for the merchant's Ajax cart. `add.js` records what the widget POSTed and
 * appends the line; `cart.js` reports what is actually IN the cart. The whole point of the gate is
 * that a 200 from add.js proves nothing — only /cart.js does.
 */
function installMockCart(target, { addStatus = 200 } = {}) {
  const cart = { items: [], adds: [] };

  target.route('**/cart/add.js', (route) => {
    const body = route.request().postDataJSON();
    cart.adds.push(body);

    if (addStatus !== 200) {
      route.fulfill({
        status: addStatus,
        contentType: 'application/json',
        body: JSON.stringify({ status: addStatus, message: 'Cart Error', description: 'sold out' }),
      });
      return;
    }

    for (const item of (body && body.items) || []) {
      cart.items.push({
        variant_id: item.id,
        quantity: item.quantity,
        properties: item.properties || {},
      });
    }
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ items: cart.items }) });
  });

  target.route('**/cart.js', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ item_count: cart.items.length, items: cart.items }),
    }),
  );

  return cart;
}

// --- Club bootstrap helpers -------------------------------------------------
// The configured price-zone selectors for the mock PDP (pdp/catalog/cart resolve on this page).
const CLUB_ZONE_SELECTORS = {
  pdp: ['.product__price'],
  catalog: ['.catalog__price'],
  cart: ['.cart__price'],
};

// A bootstrap body with the Customer Club turned on. `member` toggles verified vs. not; `discount`
// is the percent off. The banner-behavior fields mirror the real BootstrapController shape (resolved
// defaults) and are overridable per gate. Reuses the base body so the PDP/appearance/product shape
// is unchanged.
function clubBootstrapBody(locale, {
  member = false, discount = 20, enabled = true,
  trigger = 'immediate', delaySeconds = 0, scrollPercent = 25,
  position = 'bottom-end', dismissDays = 7,
} = {}) {
  const body = bootstrapBody(locale);
  body.club = {
    enabled,
    discount_percent: discount,
    price_zones: CLUB_ZONE_SELECTORS,
    banner_trigger: trigger,
    banner_delay_seconds: delaySeconds,
    banner_scroll_percent: scrollPercent,
    banner_position: position,
    banner_dismiss_days: dismissDays,
    member: { verified: member },
  };
  return body;
}

// Stub the two club endpoints. `verifyResult` shapes the verify-code response so a gate can drive
// the happy path or a typed failure (invalid/expired/locked). request-code succeeds by default.
async function stubClub(target, { codeSent = true, requestReason = null, verifyResult = { verified: true } } = {}) {
  await target.route('**/widget/v1/club/request-code', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(codeSent ? { ok: true, code_sent: true } : { ok: true, code_sent: false, reason: requestReason || 'throttled' }),
    }),
  );
  await target.route('**/widget/v1/club/verify-code', (route) => {
    const ok = verifyResult.verified === true;
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(
        ok
          ? { ok: true, verified: true, member: { verified: true } }
          : { ok: true, verified: false, reason: verifyResult.reason || 'invalid' },
      ),
    });
  });
}

// A 1x1 transparent PNG data URL — the stubbed result image.
const RESULT_PNG =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

async function stubApi(page, locale) {
  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(bootstrapBody(locale)) }),
  );
  await page.route('**/widget/v1/generations', (route) =>
    route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, generation: { id: 7, status: 'pending' }, free_remaining: 2, reused: false }),
    }),
  );
  await page.route('**/widget/v1/generations/7*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, generation: { id: 7, status: 'succeeded', failure_code: null, result_url: RESULT_PNG, created_at: null } }),
    }),
  );
  await page.route('**/widget/v1/events/add-to-cart', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, recorded: true }) }),
  );
  // Behavioral tracking ingest (page_view + interactions) — fire-and-forget; the widget ignores it.
  await page.route('**/widget/v1/events', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, recorded: 0 }) }),
  );
  // Default: no past try-ons (individual gates override this to supply a gallery).
  await page.route('**/widget/v1/gallery*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, items: [] }) }),
  );
}

function assert(cond, msg) {
  if (!cond) throw new Error('ASSERT FAILED: ' + msg);
  console.log('  ok -', msg);
}

async function waitForBoot(page) {
  await page.waitForFunction(() => window.__TrayOn && window.__TrayOn.booted === true, { timeout: 8000 });
  await page.waitForFunction(() => !!document.querySelector('[data-trayon-mounted]'), { timeout: 8000 });
}

/**
 * Tap the trigger and wait for the REAL modal (not the skeleton shell). The modal body now lives
 * in a lazily fetched chunk, so a fixed timeout here would be a flake generator: we wait on the
 * setup form's own CTA instead.
 */
async function openModal(page) {
  await page.evaluate(
    (sentinel) => document.querySelector('[' + sentinel + ']').shadowRoot.querySelector('.ton-button').click(),
    SENTINEL,
  );
  await page.waitForFunction(() => !!document.querySelector('#trayon-host'), { timeout: 5000 });
  await page.locator('.ton-cta').waitFor({ timeout: 8000 });
}

/** Fill the setup form (photo + height + consent) and start the generation. */
async function startGeneration(page) {
  await page.locator('.ton-upload__file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG_UPLOAD_BYTES });
  await page.locator('.ton-preview__img').waitFor({ timeout: 6000 }).catch(async () => {
    const err = await page.locator('.ton-error').first().textContent().catch(() => '');
    throw new Error('photo not accepted by prepare(); error box="' + (err || '').trim() + '"');
  });
  await page.locator('.ton-input').first().fill('175');
  await page.locator('.ton-consent__box').check();
  await page.locator('.ton-cta').click({ timeout: 6000 });
  await page.locator('.ton-loading__frame').waitFor({ timeout: 6000 });
}

// A real 64x64 RGB PNG for the file upload — big enough that the widget's client-side
// decode+downscale (createImageBitmap + canvas re-encode) succeeds (a 1x1 pixel fails it).
function pngChunk(type, data) {
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length, 0);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body) >>> 0, 0);
  return Buffer.concat([len, body, crc]);
}
function makePng(w, h) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 2; // color type 2 = RGB
  const row = Buffer.alloc(1 + w * 3); // filter byte + RGB pixels
  for (let x = 0; x < w; x++) {
    row[1 + x * 3] = 90;
    row[1 + x * 3 + 1] = 125;
    row[1 + x * 3 + 2] = 82;
  }
  const raw = Buffer.concat(Array.from({ length: h }, () => row));
  return Buffer.concat([sig, pngChunk('IHDR', ihdr), pngChunk('IDAT', deflateSync(raw)), pngChunk('IEND', Buffer.alloc(0))]);
}
const PNG_UPLOAD_BYTES = makePng(64, 64);

/**
 * Async-notification gate: submit a try-on, CLOSE the popup while it's still generating,
 * and prove the background poll continues, surfaces an on-page notification when ready, and
 * reopens to the finished result on click. The status endpoint returns `processing` first,
 * then `succeeded`, so the close happens mid-generation.
 */
async function asyncNotificationGate(browser) {
  console.log('\n=== async notification (close popup mid-generation) ===');
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  let failures = 0;
  let polls = 0;

  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(bootstrapBody('en')) }));
  await page.route('**/widget/v1/generations', (route) =>
    route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ ok: true, generation: { id: 8, status: 'pending' }, free_remaining: 2, reused: false }) }));
  await page.route('**/widget/v1/generations/8*', (route) => {
    polls++;
    const status = polls >= 2 ? 'succeeded' : 'processing';
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, generation: { id: 8, status, failure_code: null, result_url: status === 'succeeded' ? RESULT_PNG : null, created_at: null } }) });
  });

  try {
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);

    await openModal(page);

    // Generate -> loading; the Tray On button enters its "thinking" state.
    await startGeneration(page);
    await page.locator('.ton-button--busy').waitFor({ timeout: 5000 });
    assert(true, 'Tray On button shows the "thinking" state during generation');

    // CLOSE the popup while it is still generating — the busy button stays busy in the background.
    await page.locator('.ton-modal__close').click();
    await page.locator('.ton-overlay').waitFor({ state: 'detached', timeout: 5000 });
    assert(true, 'popup closed while generation still in flight');

    // The HUD picks the generation up and says so, with the modal closed.
    await page.locator('.ton-notification--thinking').waitFor({ timeout: 5000 });
    assert(true, 'the HUD carries the generation after the modal is closed (--thinking)');

    // The background poll completes -> the HUD flips to "ready" (the modal stays closed).
    await page.locator('.ton-notification--ready').waitFor({ timeout: 8000 });
    assert(true, 'on-page "ready" notification appeared after the popup was closed');

    // ...and the button returns to its normal (non-busy) state once generation finished.
    assert((await page.locator('.ton-button--busy').count()) === 0, 'Tray On button returns to normal after generation');

    // Click it -> the modal reopens straight to the finished result image.
    await page.locator('.ton-notification__main').click();
    await page.locator('.ton-result__img').waitFor({ timeout: 8000 });
    assert(true, 'clicking the notification reopened the modal on the result image');

    await page.screenshot({ path: resolve(OUT, 'widget-notification.en.png'), fullPage: false });
    console.log('  -> screenshot: widget-notification.en.png');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, 'widget-notification-FAIL.png') }).catch(() => {});
  }

  await page.close();
  return failures;
}

/**
 * Gallery gate: a shopper with past try-ons opens the modal and sees them as a strip atop the
 * form; tapping one opens that past image.
 */
async function galleryGate(browser) {
  console.log('\n=== gallery (past try-ons) ===');
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  let failures = 0;

  await stubApi(page, 'en');
  // Override the default empty gallery with two past try-ons (registered last -> wins).
  await page.route('**/widget/v1/gallery*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, items: [
      { id: 11, status: 'succeeded', result_url: RESULT_PNG, created_at: null },
      { id: 12, status: 'succeeded', result_url: RESULT_PNG, created_at: null },
    ] }) }));

  try {
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    await openModal(page);

    // The strip appears under the preview with the shopper's past looks.
    await page.locator('.ton-gallery__thumb').first().waitFor({ timeout: 6000 });
    const thumbs = await page.locator('.ton-gallery__thumb').count();
    assert(thumbs === 2, `gallery shows the shopper's past try-ons (${thumbs})`);

    // Tapping one opens that past image.
    await page.locator('.ton-gallery__thumb').first().click();
    await page.locator('.ton-result__img').waitFor({ timeout: 5000 });
    assert(true, 'tapping a past try-on opens it');

    await page.screenshot({ path: resolve(OUT, 'widget-gallery.en.png'), fullPage: false });
    console.log('  -> screenshot: widget-gallery.en.png');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, 'widget-gallery-FAIL.png') }).catch(() => {});
  }

  await page.close();
  return failures;
}

/**
 * Custom placement gate (the visual picker's runtime path): (1) a resolvable merchant-picked
 * anchor -> the button is placed relative to THAT element, not add-to-cart; (2) a custom anchor
 * that no longer resolves on the live page -> the button falls back to after add-to-cart so it
 * never vanishes.
 */
async function customPlacementGate(browser) {
  console.log('\n=== custom placement (picked anchor + runtime fallback) ===');
  let failures = 0;
  let appearanceOverride = {};

  const openPage = async () => {
    const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await page.route('**/widget/v1/bootstrap*', (route) => {
      const body = bootstrapBody('en');
      Object.assign(body.site.appearance, appearanceOverride);
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    });
    await page.route('**/widget/v1/gallery*', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, items: [] }) }));
    return page;
  };

  // (1) A resolvable custom anchor: place the button immediately BEFORE the price element.
  try {
    appearanceOverride = { button_placement: 'custom', custom_anchor_selector: '.product__price', custom_position: 'before' };
    const page = await openPage();
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    const placed = await page.evaluate((sentinel) => {
      const price = document.querySelector('.product__price');
      const btn = document.querySelector('[' + sentinel + ']');
      return !!btn && price.previousElementSibling === btn && btn.parentNode === price.parentNode;
    }, SENTINEL);
    assert(placed, 'resolvable custom anchor: button placed at the picked element (before .product__price)');
    await page.close();
  } catch (e) { failures++; console.error('  FAIL:', e.message); }

  // (2) A custom anchor that does NOT resolve: fall back to after add-to-cart.
  try {
    appearanceOverride = { button_placement: 'custom', custom_anchor_selector: '#nope-not-here', custom_position: 'before' };
    const page = await openPage();
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    const fellBack = await page.evaluate((sentinel) => {
      const atc = document.querySelector('#add-to-cart');
      const btn = document.querySelector('[' + sentinel + ']');
      const below = btn ? !!(atc.compareDocumentPosition(btn) & Node.DOCUMENT_POSITION_FOLLOWING) : false;
      return !!btn && below && btn.parentNode === atc.parentNode;
    }, SENTINEL);
    assert(fellBack, 'missing custom anchor: button falls back to after add-to-cart');
    await page.close();
  } catch (e) { failures++; console.error('  FAIL:', e.message); }

  return failures;
}

/**
 * Cross-PAGE resume gate: start a try-on, close the popup mid-generation, then RELOAD the page
 * (a fresh page load). The persisted pending entry in localStorage must resume polling in the
 * background and surface the on-page "ready" notification on the reloaded page; clicking it opens
 * the finished result. Proves the notification survives a full navigation, not just an in-memory poll.
 */
async function crossPageResumeGate(browser) {
  console.log('\n=== cross-page resume (reload while generating -> notify on the fresh page) ===');
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  let failures = 0;
  let polls = 0;

  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(bootstrapBody('en')) }));
  await page.route('**/widget/v1/generations', (route) =>
    route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ ok: true, generation: { id: 9, status: 'pending' }, free_remaining: 2, reused: false }) }));
  // Stay `processing` for a while so the same-page poll has NOT finished when we reload; the
  // resume path (fresh load) then polls it to `succeeded`.
  await page.route('**/widget/v1/generations/9*', (route) => {
    polls++;
    const status = polls >= 3 ? 'succeeded' : 'processing';
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, generation: { id: 9, status, failure_code: null, result_url: status === 'succeeded' ? RESULT_PNG : null, created_at: null } }) });
  });
  await page.route('**/widget/v1/gallery*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, items: [] }) }));

  try {
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);

    // Start a try-on.
    await openModal(page);
    await startGeneration(page);

    // The pending generation is persisted, site-scoped by the site_key (written once the
    // create-generation response returns the id — poll for it rather than racing the fetch).
    const readPending = () => page.evaluate(() => {
      const key = Object.keys(localStorage).find((k) => k.startsWith('trayon.pending.') && !k.endsWith('.signal'));
      return key ? JSON.parse(localStorage.getItem(key)) : null;
    });
    let persisted = null;
    for (let i = 0; i < 30 && !(persisted && persisted.generationId === 9); i++) {
      persisted = await readPending();
      if (!(persisted && persisted.generationId === 9)) await page.waitForTimeout(200);
    }
    assert(persisted && persisted.generationId === 9 && persisted.phase === 'active', 'pending generation persisted to a site-scoped localStorage key');

    // RELOAD the page while it is still generating — the in-memory poll dies; the resume takes over.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await waitForBoot(page);

    // No modal is open, no button was clicked — the resumer polls in the background and shows
    // the on-page "ready" notification on this fresh page load.
    await page.locator('.ton-notification--ready').waitFor({ timeout: 12000 });
    assert(true, 'after reload: the resumer surfaced the "ready" notification with no user action');

    // Click it -> opens the finished result.
    await page.locator('.ton-notification__main').click();
    await page.locator('.ton-result__img').waitFor({ timeout: 8000 });
    assert(true, 'clicking the resumed notification opens the finished result');

    // The entry is now marked viewed -> a further reload does NOT re-notify.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    await page.waitForTimeout(1200);
    const reNotified = await page.locator('.ton-notification').count();
    assert(reNotified === 0, 'a reload after viewing does NOT re-show the notification');

    await page.screenshot({ path: resolve(OUT, 'widget-resume.en.png'), fullPage: false });
    console.log('  -> screenshot: widget-resume.en.png');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, 'widget-resume-FAIL.png') }).catch(() => {});
  }

  await page.close();
  return failures;
}

/**
 * Cross-TAB gate: two pages in ONE browser context (shared localStorage + BroadcastChannel).
 * A try-on is started + completed in tab 1; tab 2 (a plain non-generating PDP) must receive the
 * completion and show the "ready" notification too, without having clicked anything.
 */
async function crossTabGate(browser) {
  console.log('\n=== cross-tab sync (completion in tab 1 notifies tab 2) ===');
  const context = await browser.newContext({ viewport: { width: 900, height: 900 } });
  let failures = 0;
  let polls = 0;

  // Route on the CONTEXT so both tabs share the same stubs.
  await context.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(bootstrapBody('en')) }));
  await context.route('**/widget/v1/generations', (route) =>
    route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ ok: true, generation: { id: 10, status: 'pending' }, free_remaining: 2, reused: false }) }));
  // Stay `processing` for several polls so the generation is guaranteed still in-flight when
  // tab 1 closes the popup (the form-fill in two contexts is slower — avoid a completion race).
  await context.route('**/widget/v1/generations/10*', (route) => {
    polls++;
    const status = polls >= 4 ? 'succeeded' : 'processing';
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, generation: { id: 10, status, failure_code: null, result_url: status === 'succeeded' ? RESULT_PNG : null, created_at: null } }) });
  });
  await context.route('**/widget/v1/gallery*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, items: [] }) }));

  try {
    // Tab 1 boots first so it owns the anon token; tab 2 shares it via localStorage.
    const tab1 = await context.newPage();
    await tab1.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(tab1);

    // Tab 2: a second PDP in the SAME context (shares localStorage + BroadcastChannel).
    const tab2 = await context.newPage();
    await tab2.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(tab2);

    // Start + finish a try-on in tab 1 (close the popup mid-generation so it completes in the bg).
    await openModal(tab1);
    await startGeneration(tab1);
    await tab1.locator('.ton-modal__close').click();
    await tab1.locator('.ton-overlay').waitFor({ state: 'detached', timeout: 5000 });

    // Tab 1 completes in the background and broadcasts `done` (its own on-page notice appears).
    await tab1.locator('.ton-notification--ready').waitFor({ timeout: 12000 });
    assert(true, 'tab 1 completed the try-on and showed its own notification');

    // Tab 2 — which never generated — receives the broadcast and shows the notification too.
    await tab2.locator('.ton-notification--ready').waitFor({ timeout: 8000 });
    assert(true, 'tab 2 received the cross-tab completion and showed the "ready" notification');

    // Viewing in tab 2 clears tab 1's popup (broadcast `viewed` -> no zombie notifications).
    await tab2.locator('.ton-notification__main').click();
    await tab2.locator('.ton-result__img').waitFor({ timeout: 8000 });
    await tab1.locator('.ton-notification').first().waitFor({ state: 'detached', timeout: 6000 });
    assert(true, 'viewing in tab 2 cleared the notification in tab 1 (no zombie popups)');

    await tab2.screenshot({ path: resolve(OUT, 'widget-crosstab.en.png'), fullPage: false });
    console.log('  -> screenshot: widget-crosstab.en.png');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }

  await context.close();
  return failures;
}

/**
 * Static perf gates (no browser): the install snippet must be a classic `<script async>`
 * (never render-blocking, never type=module), and the built entry bundle must stay under the
 * gzip budget. These are cheap, deterministic guards that fail the whole run if regressed.
 */
function staticPerfGates() {
  console.log('\n=== static perf gates (install snippet + gzip budget) ===');
  let failures = 0;

  try {
    const blade = readFileSync(EMBED_BLADE, 'utf8');
    // The snippet is built in a @php block: <script src="..." data-site-key="..." async>.
    assert(/data-site-key=/.test(blade), 'embed snippet carries data-site-key');
    assert(/\basync\b/.test(blade), 'embed snippet is a <script async> (non-blocking install)');
    assert(!/type=("|\\')module/.test(blade), 'embed snippet is NOT type=module (classic, cacheable, no CORS module fetch)');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }

  // The SPLIT budget: the core is what every page view pays for, so it has its own ceiling; each
  // lazy chunk has its own. Raising one must never silently pay for the other.
  try {
    const budget = JSON.parse(readFileSync(SIZE_BUDGET, 'utf8'));

    const core = gzipBundle(CORE_BUNDLE);
    assert(core <= budget.maxGzipBytes, `CORE bundle within gzip budget (${core} <= ${budget.maxGzipBytes})`);

    for (const name of LAZY_BUNDLES) {
      const gz = gzipBundle(name);
      assert(gz <= budget.maxLazyGzipBytes, `LAZY ${name} within gzip budget (${gz} <= ${budget.maxLazyGzipBytes})`);
    }

    // The modal chunk must actually BE lazy: the core may not contain the modal's markup/copy.
    const coreSource = readFileSync(WIDGET_JS, 'utf8');
    assert(!coreSource.includes('ton-consent__box'), 'the core bundle does NOT contain the modal body (it is genuinely lazy)');
    assert(!coreSource.includes('cart/add.js'), 'the core bundle does NOT contain the cart bridge (it is genuinely lazy)');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }

  return failures;
}

/** Gzip a built bundle (zlib deflate + a gzip header/trailer) to size it like the CDN would. */
function gzipBundle(name) {
  const raw = readFileSync(join(DIST, name));
  const body = deflateSync(raw, { level: 9 });
  // gzip = 10-byte header + raw deflate + CRC32 + ISIZE (mtime/os zeroed — size is what matters).
  const header = Buffer.from([0x1f, 0x8b, 0x08, 0, 0, 0, 0, 0, 0, 0xff]);
  const trailer = Buffer.alloc(8);
  trailer.writeUInt32LE(crc32(raw) >>> 0, 0);
  trailer.writeUInt32LE(raw.length >>> 0, 4);
  return header.length + body.length + trailer.length;
}

/**
 * Perf + tracking gate: prove (1) the widget does NO synchronous work before window `load`
 * (it boots only on the idle path); (2) the tracking module records ONE page_view + the
 * meaningful interactions (product_view, variant_change, tryon_open, add_to_cart) and NOTHING
 * arbitrary; (3) nothing the widget does causes a host-page layout shift (CLS budget); and
 * (4) tracking flushes are fire-and-forget (never block the interaction that triggered them).
 */
async function perfTrackingGate(browser) {
  console.log('\n=== perf + tracking (no sync work / CLS / meaningful events only) ===');
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  let failures = 0;
  const sentEvents = []; // every /events body the widget posts

  // Install a CLS observer + a "did the widget touch the DOM before load?" probe BEFORE any
  // page script runs. layout-shift entries accumulate the host-page shift; we also snapshot
  // whether the widget's shadow host existed at the moment `load` fired (it must NOT — boot is
  // idle-scheduled AFTER load), which proves no synchronous pre-load work.
  await page.addInitScript(() => {
    window.__cls = 0;
    try {
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          if (!entry.hadRecentInput) window.__cls += entry.value;
        }
      }).observe({ type: 'layout-shift', buffered: true });
    } catch { /* layout-shift unsupported -> the assertion below tolerates 0 */ }

    window.__widgetAtLoad = null;
    window.addEventListener('load', () => {
      // At the `load` event the idle boot has NOT run yet: no shadow host, no button, and no
      // resolved config (config is only stashed AFTER the idle bootstrap fetch returns). The
      // one thing set synchronously is the cheap double-boot GUARD flag — that is not "work".
      window.__widgetAtLoad = {
        host: !!document.querySelector('#trayon-host'),
        button: !!document.querySelector('[data-trayon-mounted]'),
        configResolved: !!(window.__TrayOn && window.__TrayOn.state && window.__TrayOn.state.config),
      };
    }, { once: true });
  });

  await stubApi(page, 'en');
  // Capture every tracking batch the widget sends (still fulfilling fire-and-forget).
  await page.route('**/widget/v1/events', (route) => {
    try {
      const body = route.request().postDataJSON();
      if (body) sentEvents.push(body);
    } catch { /* a beacon Blob may not parse as JSON here — ignore */ }
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, recorded: 0 }) });
  });

  try {
    await page.goto(ORIGIN, { waitUntil: 'load' });

    // --- no synchronous pre-load work: at `load`, the widget had not booted or mounted ---
    const atLoad = await page.evaluate(() => window.__widgetAtLoad);
    assert(atLoad && atLoad.host === false, 'no widget shadow host at window `load` (boot is idle-scheduled)');
    assert(atLoad && atLoad.button === false, 'no injected button at window `load` (no sync mount)');
    assert(atLoad && atLoad.configResolved === false, 'no resolved config at `load` (bootstrap fetch + boot run only on idle, after load)');

    await waitForBoot(page);

    // --- a meaningful interaction: change the variant, then open the flow, then add-to-cart ---
    await page.selectOption('#variant', 'TEE-S');
    await page.waitForTimeout(400);

    await openModal(page);

    // Run a full try-on so the add-to-cart interaction fires (its own funnel + track signal).
    await page.locator('.ton-upload__file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG_UPLOAD_BYTES });
    await page.locator('.ton-preview__img').waitFor({ timeout: 6000 });
    await page.locator('.ton-input').first().fill('175');
    await page.locator('.ton-consent__box').check();
    await page.locator('.ton-cta').click({ timeout: 6000 });
    await page.locator('.ton-result__img').waitFor({ timeout: 8000 });
    await page.locator('.ton-action--primary').click(); // add to cart

    // Force a flush (idle batches may still be pending) by hiding the page, then settle.
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await page.waitForFunction(() => window.__cls != null, { timeout: 2000 }).catch(() => {});
    // Poll until the batches arrive (fire-and-forget, so give the network a beat).
    for (let i = 0; i < 25; i++) {
      await page.waitForTimeout(120);
      const kinds = collectKinds(sentEvents);
      if (kinds.pageView >= 1 && kinds.interactions.has('product_view') && kinds.interactions.has('variant_change')
        && kinds.interactions.has('tryon_open') && kinds.interactions.has('add_to_cart')) break;
    }

    // --- meaningful-events gate: exactly the curated kinds, nothing arbitrary ---
    const kinds = collectKinds(sentEvents);
    assert(kinds.pageView === 1, `exactly ONE page_view recorded (${kinds.pageView})`);
    assert(kinds.interactions.has('product_view'), 'product_view interaction recorded');
    assert(kinds.interactions.has('variant_change'), 'variant_change interaction recorded');
    assert(kinds.interactions.has('tryon_open'), 'tryon_open interaction recorded');
    assert(kinds.interactions.has('add_to_cart'), 'add_to_cart interaction recorded');
    assert(kinds.unknown.length === 0, `no arbitrary/unknown interaction types recorded (${kinds.unknown.join(',') || 'none'})`);

    // --- payload-shape gate: matches the ingest CONTRACT exactly (no PII beyond path/host) ---
    const shapeOk = sentEvents.every((batch) =>
      typeof batch.anon_token === 'string' && Array.isArray(batch.events) &&
      batch.events.every((e) =>
        (e.kind === 'page_view' || e.kind === 'interaction') &&
        typeof e.at === 'string' && typeof e.path === 'string' && !e.path.includes('?') &&
        (e.referrer_host === undefined || typeof e.referrer_host === 'string') &&
        (e.interaction === undefined || (typeof e.interaction.type === 'string'))),
    );
    assert(shapeOk, 'every event matches the ingest contract shape (anon_token + kind/at/path, no query string)');

    // --- CLS gate: the widget caused (essentially) no host layout shift ---
    const cls = await page.evaluate(() => window.__cls || 0);
    assert(cls < CLS_BUDGET, `widget caused no host layout shift (CLS ${cls.toFixed(4)} < ${CLS_BUDGET})`);

    console.log(`  -> events captured: ${sentEvents.length} batch(es); CLS=${cls.toFixed(4)}`);
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, 'widget-perf-FAIL.png') }).catch(() => {});
  }

  await page.close();
  return failures;
}

/** Flatten the captured /events batches into counts of page_views + a set of interaction types. */
function collectKinds(batches) {
  const known = new Set(['product_view', 'variant_change', 'tryon_open', 'add_to_cart']);
  let pageView = 0;
  const interactions = new Set();
  const unknown = [];
  for (const batch of batches) {
    for (const e of batch.events || []) {
      if (e.kind === 'page_view') pageView++;
      else if (e.kind === 'interaction' && e.interaction) {
        const type = e.interaction.type;
        interactions.add(type);
        if (!known.has(type)) unknown.push(type);
      }
    }
  }
  return { pageView, interactions, unknown };
}

/**
 * Club banner visibility gate: the floating join banner shows for a NON-member on a club-enabled
 * site, and is ABSENT for a verified member AND for a club-disabled site. The banner reuses the
 * `ton-notification` skin inside the shadow root (carries the `ton-club-banner` marker class).
 */
async function clubBannerGate(browser, locale) {
  console.log(`\n=== club banner visibility (${locale}) ===`);
  let failures = 0;
  const bannerCount = async (page) =>
    page.evaluate(() => {
      const host = document.querySelector('#trayon-host');
      if (!host || !host.shadowRoot) return 0;
      return host.shadowRoot.querySelectorAll('.ton-club-banner').length;
    });

  try {
    // (1) non-member, enabled -> banner shows (exactly one).
    const p1 = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await stubApi(p1, locale);
    await p1.route('**/widget/v1/bootstrap*', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(clubBootstrapBody(locale, { member: false })) }));
    await p1.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    if (locale === 'he') await p1.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
    await waitForBoot(p1);
    await p1.waitForFunction(() => {
      const h = document.querySelector('#trayon-host');
      return h && h.shadowRoot && h.shadowRoot.querySelector('.ton-club-banner');
    }, { timeout: 6000 });
    assert((await bannerCount(p1)) === 1, 'non-member on a club-enabled site: floating banner shows (exactly one)');
    await p1.screenshot({ path: resolve(OUT, `widget-club-banner.${locale}.png`), fullPage: false });
    console.log(`  -> screenshot: widget-club-banner.${locale}.png`);
    await p1.close();

    // (2) verified member -> NO banner.
    const p2 = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await stubApi(p2, locale);
    await p2.route('**/widget/v1/bootstrap*', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(clubBootstrapBody(locale, { member: true })) }));
    await p2.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(p2);
    await p2.waitForTimeout(600);
    assert((await bannerCount(p2)) === 0, 'verified member: NO join banner shown');
    await p2.close();

    // (3) club disabled -> NO banner (the default bootstrap has club.enabled=false).
    const p3 = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await stubApi(p3, locale);
    await p3.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(p3);
    await p3.waitForTimeout(600);
    assert((await bannerCount(p3)) === 0, 'club disabled: NO join banner shown');
    await p3.close();
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }
  return failures;
}

/**
 * Club login flow gate: a non-member clicks the banner, enters an email (request-code), enters the
 * 6-digit code (verify-code), and on success the banner hides, the member flag persists, AND member
 * prices are applied live (no reload). Uses the shadow-root form (reuses the modal/lead classes).
 */
async function clubLoginFlowGate(browser) {
  console.log('\n=== club login flow (email -> code -> verified -> member pricing) ===');
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  let failures = 0;

  await stubApi(page, 'en');
  await stubClub(page, { codeSent: true, verifyResult: { verified: true } });
  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(clubBootstrapBody('en', { member: false, discount: 20 })) }));

  const sr = (fn) => page.evaluate(fn);

  try {
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);

    // Open the banner -> the login modal (email step).
    await page.waitForFunction(() => {
      const h = document.querySelector('#trayon-host');
      return h && h.shadowRoot && h.shadowRoot.querySelector('.ton-club-banner');
    }, { timeout: 6000 });
    await sr(() => document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-club-banner .ton-notification__main').click());
    await page.waitForFunction(() => document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-overlay .ton-modal'), { timeout: 5000 });
    assert(true, 'clicking the banner opened the email login step');

    // Enter the email + request a code.
    await sr(() => {
      const sr2 = document.querySelector('#trayon-host').shadowRoot;
      sr2.querySelector('.ton-overlay .ton-input').value = 'shopper@example.com';
      sr2.querySelector('.ton-overlay .ton-cta').click();
    });
    // The code step renders (its hint mentions the email).
    await page.waitForFunction(() => {
      const el = document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-overlay .ton-upload__hint');
      return el && el.textContent.includes('shopper@example.com');
    }, { timeout: 5000 });
    assert(true, 'after request-code, the 6-digit code step is shown (keyed on the same email)');

    // Enter the code + verify.
    await sr(() => {
      const sr2 = document.querySelector('#trayon-host').shadowRoot;
      sr2.querySelector('.ton-overlay .ton-input').value = '123456';
      sr2.querySelector('.ton-overlay .ton-cta').click();
    });

    // On verify: the banner disappears and the member flag is persisted.
    await page.waitForFunction(() => {
      const h = document.querySelector('#trayon-host');
      return h && h.shadowRoot && h.shadowRoot.querySelectorAll('.ton-club-banner').length === 0;
    }, { timeout: 6000 });
    assert(true, 'after verify: the join banner is hidden (flipped to member mode)');

    const memberPersisted = await page.evaluate(() => {
      const key = Object.keys(localStorage).find((k) => k.startsWith('trayon.club.member.'));
      return key ? localStorage.getItem(key) : null;
    });
    assert(memberPersisted === '1', 'member state persisted to site-scoped localStorage');

    // Member pricing applied live (no reload): $89.00 * (1 - 0.20) = $71.20, with the club badge.
    await page.waitForFunction(() => {
      const n = document.querySelector('.product__price');
      return n && n.textContent.includes('71.20');
    }, { timeout: 5000 });
    const pdpText = await page.evaluate(() => document.querySelector('.product__price').textContent);
    assert(pdpText.includes('71.20'), `PDP price rewritten to the member price after join (${pdpText.trim()})`);
    assert(pdpText.includes('$'), 'the member price keeps the store currency symbol');
    const hasBadge = await page.evaluate(() => !!document.querySelector('.product__price .trayon-club-badge'));
    assert(hasBadge, 'the "club price" badge affordance is appended');

    await page.screenshot({ path: resolve(OUT, 'widget-club-verified.en.png'), fullPage: false });
    console.log('  -> screenshot: widget-club-verified.en.png');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, 'widget-club-FAIL.png') }).catch(() => {});
  }

  await page.close();
  return failures;
}

/**
 * Club typed-failure gate: an invalid code surfaces a friendly, specific error and does NOT flip to
 * member; a throttled request-code still advances to the code step with a notice.
 */
async function clubFailureGate(browser) {
  console.log('\n=== club typed failures (invalid code / throttled) ===');
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  let failures = 0;

  await stubApi(page, 'en');
  await stubClub(page, { codeSent: false, requestReason: 'throttled', verifyResult: { verified: false, reason: 'invalid' } });
  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(clubBootstrapBody('en', { member: false })) }));

  const sr = (fn) => page.evaluate(fn);

  try {
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    await page.waitForFunction(() => {
      const h = document.querySelector('#trayon-host');
      return h && h.shadowRoot && h.shadowRoot.querySelector('.ton-club-banner');
    }, { timeout: 6000 });
    await sr(() => document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-club-banner .ton-notification__main').click());
    await page.waitForFunction(() => document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-overlay .ton-modal'), { timeout: 5000 });

    // Throttled request-code still advances to the code step (a prior code may be in the inbox).
    await sr(() => {
      const s = document.querySelector('#trayon-host').shadowRoot;
      s.querySelector('.ton-overlay .ton-input').value = 'shopper@example.com';
      s.querySelector('.ton-overlay .ton-cta').click();
    });
    await page.waitForFunction(() => {
      const el = document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-overlay .ton-error');
      return el && !el.hasAttribute('hidden') && el.textContent.trim().length > 0;
    }, { timeout: 5000 });
    assert(true, 'throttled request-code advances to the code step WITH a typed notice');

    // Invalid code -> a specific error, still NOT a member (banner would still be gone only on success).
    await sr(() => {
      const s = document.querySelector('#trayon-host').shadowRoot;
      s.querySelector('.ton-overlay .ton-input').value = '000000';
      s.querySelector('.ton-overlay .ton-cta').click();
    });
    await page.waitForFunction(() => {
      const el = document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-overlay .ton-error');
      return el && !el.hasAttribute('hidden') && el.textContent.trim().length > 0;
    }, { timeout: 5000 });
    const stillNotMember = await page.evaluate(() => {
      const key = Object.keys(localStorage).find((k) => k.startsWith('trayon.club.member.'));
      return key ? localStorage.getItem(key) : null;
    });
    assert(stillNotMember !== '1', 'an invalid code did NOT flip the shopper to member');
    // The PDP price is untouched (still the original) since verify failed.
    const pdpText = await page.evaluate(() => document.querySelector('.product__price').textContent);
    assert(pdpText.includes('89.00') && !pdpText.includes('71.20'), 'a failed verify leaves the PDP price untouched');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }

  await page.close();
  return failures;
}

/**
 * Member-pricing gate: a VERIFIED member (bootstrap) sees every configured zone rewritten by the
 * discount, in the store's NATIVE format (locale-aware money parse), with ZERO host layout shift
 * (CLS < budget). A NON-member's prices are left completely untouched. Re-apply on a variant
 * change never double-discounts.
 */
async function memberPricingGate(browser) {
  console.log('\n=== member pricing (rewrite + locale-aware parse + no CLS + no double-discount) ===');
  let failures = 0;

  // (A) Verified member: prices rewritten in every zone, no CLS.
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  await page.addInitScript(() => {
    window.__cls = 0;
    try {
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) if (!entry.hadRecentInput) window.__cls += entry.value;
      }).observe({ type: 'layout-shift', buffered: true });
    } catch { /* unsupported -> tolerates 0 */ }
  });
  await stubApi(page, 'en');
  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(clubBootstrapBody('en', { member: true, discount: 20 })) }));

  try {
    await page.goto(ORIGIN, { waitUntil: 'load' });
    await waitForBoot(page);

    // Wait for the idle-scheduled rewrite of all three zones.
    await page.waitForFunction(() => {
      const pdp = document.querySelector('.product__price');
      const cat = document.querySelector('.catalog__price');
      const cart = document.querySelector('.cart__price');
      return pdp && cat && cart
        && pdp.hasAttribute('data-trayon-club-price')
        && cat.hasAttribute('data-trayon-club-price')
        && cart.hasAttribute('data-trayon-club-price');
    }, { timeout: 6000 });

    const prices = await page.evaluate(() => ({
      pdp: document.querySelector('.product__price').textContent,
      catalog: document.querySelector('.catalog__price').textContent,
      cart: document.querySelector('.cart__price').textContent,
    }));

    // $89.00 * 0.8 = $71.20 (US format kept).
    assert(prices.pdp.includes('71.20') && prices.pdp.includes('$'), `PDP $89.00 -> member $71.20 (${prices.pdp.trim()})`);
    // ₪1,299.00 * 0.8 = ₪1,039.20 (comma-grouped, dot-decimal kept — the ILS scar case).
    assert(prices.catalog.includes('1,039.20') && prices.catalog.includes('₪'), `catalog ₪1,299.00 -> ₪1,039.20 (${prices.catalog.trim()})`);
    // 1.299,00 € * 0.8 = 1.039,20 € (European dot-grouped, comma-decimal kept — the inverse scar case).
    assert(prices.cart.includes('1.039,20') && prices.cart.includes('€'), `cart 1.299,00 € -> 1.039,20 € (${prices.cart.trim()})`);

    // Every zone carries the club badge exactly once.
    const badges = await page.evaluate(() =>
      ['.product__price', '.catalog__price', '.cart__price'].map((s) => document.querySelector(s).querySelectorAll('.trayon-club-badge').length));
    assert(badges.every((n) => n === 1), `each rewritten zone has exactly one club badge (${badges.join(',')})`);

    // No double-discount on re-apply: change the variant, prices must stay at the member price.
    await page.selectOption('#variant', 'TEE-S');
    await page.waitForTimeout(700);
    const afterVariant = await page.evaluate(() => document.querySelector('.product__price').textContent);
    assert(afterVariant.includes('71.20') && !afterVariant.includes('57'), `variant change did NOT double-discount (${afterVariant.trim()})`);

    // CLS gate: the in-place rewrite caused essentially no host layout shift.
    const cls = await page.evaluate(() => window.__cls || 0);
    assert(cls < CLS_BUDGET, `member-price rewrite caused no host layout shift (CLS ${cls.toFixed(4)} < ${CLS_BUDGET})`);
    console.log(`  -> member-pricing CLS=${cls.toFixed(4)}`);

    await page.screenshot({ path: resolve(OUT, 'widget-member-pricing.en.png'), fullPage: false });
    console.log('  -> screenshot: widget-member-pricing.en.png');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, 'widget-member-pricing-FAIL.png') }).catch(() => {});
  }
  await page.close();

  // (B) Non-member: prices are completely untouched (no rewrite, no badge, no marker attr).
  const p2 = await browser.newPage({ viewport: { width: 900, height: 900 } });
  await stubApi(p2, 'en');
  await p2.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(clubBootstrapBody('en', { member: false, discount: 20 })) }));
  try {
    await p2.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(p2);
    await p2.waitForTimeout(800); // give the idle rewrite a chance to (not) run
    const untouched = await p2.evaluate(() => {
      const pdp = document.querySelector('.product__price');
      return {
        text: pdp.textContent,
        marked: pdp.hasAttribute('data-trayon-club-price'),
        badge: !!pdp.querySelector('.trayon-club-badge'),
      };
    });
    assert(untouched.text.includes('89.00') && !untouched.text.includes('71.20'), 'non-member: PDP price is the ORIGINAL (not discounted)');
    assert(!untouched.marked && !untouched.badge, 'non-member: no marker attr, no club badge (complete no-op)');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }
  await p2.close();

  return failures;
}

/**
 * Banner behavior gate: proves the four merchant-configurable behaviors — (1) a dismissal PERSISTS
 * across a reload (the × writes a future-dated flag; the banner stays hidden on the next load);
 * (2) the DELAY trigger does not show the banner immediately but after the delay; (3) the SCROLL
 * trigger reveals it only once the shopper scrolls past the configured depth; (4) the merchant's
 * POSITION choice is applied as the corner modifier class.
 */
async function clubBannerBehaviorGate(browser) {
  console.log('\n=== club banner behavior (dismissal persistence / delay / scroll / position) ===');
  let failures = 0;

  const bannerCount = (page) => page.evaluate(() => {
    const h = document.querySelector('#trayon-host');
    return h && h.shadowRoot ? h.shadowRoot.querySelectorAll('.ton-club-banner').length : 0;
  });
  const waitBanner = (page) => page.waitForFunction(() => {
    const h = document.querySelector('#trayon-host');
    return h && h.shadowRoot && h.shadowRoot.querySelector('.ton-club-banner');
  }, { timeout: 5000 });
  const routeClub = (page, opts) => page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(clubBootstrapBody('en', opts)) }));

  // (1) Persistent dismissal: close the banner -> gone AND stays gone after a reload.
  try {
    const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await stubApi(page, 'en');
    await routeClub(page, { member: false, dismissDays: 7 });
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    await waitBanner(page);

    await page.evaluate(() =>
      document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-club-banner .ton-notification__close').click());
    await page.waitForFunction(() => {
      const h = document.querySelector('#trayon-host');
      return h && h.shadowRoot && h.shadowRoot.querySelectorAll('.ton-club-banner').length === 0;
    }, { timeout: 4000 });
    assert(true, 'clicking × dismisses the banner');

    const persisted = await page.evaluate(() => {
      const key = Object.keys(localStorage).find((k) => k.startsWith('trayon.club.dismissed.'));
      return key ? Number(localStorage.getItem(key)) : null;
    });
    assert(persisted && persisted > Date.now(), 'dismissal persisted with a future-dated expiry');

    await page.reload({ waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    await page.waitForTimeout(700); // give club.init its idle tick to (not) show the banner
    assert((await bannerCount(page)) === 0, 'after reload: the dismissed banner stays hidden');
    await page.close();
  } catch (e) { failures++; console.error('  FAIL:', e.message); }

  // (2) Delay trigger: NOT shown immediately, then appears after the delay.
  try {
    const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await stubApi(page, 'en');
    await routeClub(page, { member: false, trigger: 'delay', delaySeconds: 1 });
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    assert((await bannerCount(page)) === 0, 'delay trigger: banner is NOT shown immediately at boot');
    await waitBanner(page);
    assert((await bannerCount(page)) === 1, 'delay trigger: banner appears after the delay');
    await page.close();
  } catch (e) { failures++; console.error('  FAIL:', e.message); }

  // (3) Scroll trigger: appears only after scrolling past the depth.
  try {
    const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await stubApi(page, 'en');
    await routeClub(page, { member: false, trigger: 'scroll', scrollPercent: 50 });
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    await page.waitForTimeout(300); // let club.init arm the scroll listener
    assert((await bannerCount(page)) === 0, 'scroll trigger: banner hidden before scrolling');
    await page.evaluate(() => {
      const spacer = document.createElement('div');
      spacer.style.height = '3000px';
      document.body.appendChild(spacer);
      window.scrollTo(0, document.body.scrollHeight);
      window.dispatchEvent(new Event('scroll'));
    });
    await waitBanner(page);
    assert((await bannerCount(page)) === 1, 'scroll trigger: banner appears once scrolled past the depth');
    await page.close();
  } catch (e) { failures++; console.error('  FAIL:', e.message); }

  // (4) Position: the chosen corner is applied as a modifier class.
  try {
    const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await stubApi(page, 'en');
    await routeClub(page, { member: false, position: 'top-start' });
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);
    await waitBanner(page);
    const hasClass = await page.evaluate(() =>
      !!document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-club-banner.ton-club-banner--top-start'));
    assert(hasClass, 'position config applies the ton-club-banner--top-start modifier class');
    await page.close();
  } catch (e) { failures++; console.error('  FAIL:', e.message); }

  return failures;
}

/**
 * Merchant banner runtime gate: an image banner injects at a merchant-picked host spot (own shadow
 * root); an overlay banner renders crisp HTML text over the image; a club-members-only banner is
 * hidden from a non-member (client-side rule eval); each SHOWN banner logs exactly one impression;
 * and a click beacons a click event.
 */
async function bannerRuntimeGate(browser) {
  console.log('\n=== merchant banners (inject / overlay / rule eval / impression / click) ===');
  let failures = 0;
  const events = [];
  const page = await browser.newPage({ viewport: { width: 900, height: 1400 } });

  await stubApi(page, 'en');
  await page.route('**/widget/v1/banners/event*', (route) => {
    // The trailing * matches the ?site_key= a sendBeacon click appends (a query-less fetch impression
    // matches too). One entry per request. Impressions are fetch-keepalive (body parses -> impression); a
    // click is a sendBeacon Blob whose body Playwright does not expose, so it lands as {kind:'beacon'}
    // — still counted, so "a click fired an event" is provable by the request appearing.
    let entry = { kind: 'beacon', banner_id: null };
    try { const raw = route.request().postData(); if (raw) entry = JSON.parse(raw); } catch { /* beacon Blob */ }
    events.push(entry);
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, recorded: true }) });
  });

  const dest = `${ORIGIN}/banner-dest`; // same-origin so a click navigates cleanly
  const rulesAny = { audience: 'any', pages: { context: 'any', url_contains: null }, frequency: { max_per_session: 0 }, locales: [] };
  const banners = [
    { id: 1, composition: 'image', image_url: RESULT_PNG, width: 1200, height: 240, target_url: dest, alt: 'Sale',
      overlay: {}, placements: [{ selector: '.product__price', position: 'after' }], rules: rulesAny },
    { id: 2, composition: 'overlay', image_url: RESULT_PNG, width: 1200, height: 240, target_url: 'https://shop.example/promo', alt: 'Promo',
      overlay: { headline: 'Big Summer Sale', subtext: 'Ends soon', cta_label: 'Shop now' },
      placements: [{ selector: '#add-to-cart', position: 'before' }], rules: rulesAny },
    { id: 3, composition: 'image', image_url: RESULT_PNG, width: 1200, height: 240, target_url: 'https://x', alt: 'Members',
      overlay: {}, placements: [{ selector: '#variant', position: 'after' }],
      rules: { audience: 'club_members', pages: { context: 'any', url_contains: null }, frequency: { max_per_session: 0 }, locales: [] } },
  ];
  await page.route('**/widget/v1/bootstrap*', (route) => {
    const body = bootstrapBody('en');
    body.banners = banners;
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
  });

  try {
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);

    // (1) image banner injected at the picked spot, inside its own shadow root.
    await page.waitForFunction(() => !!document.querySelector('[data-trayon-banner="1"]'), { timeout: 6000 });
    assert(await page.evaluate(() => {
      const w = document.querySelector('[data-trayon-banner="1"]');
      return !!(w && w.shadowRoot && w.shadowRoot.querySelector('img.ton-banner__img'));
    }), 'image banner injected at the merchant-picked spot (own shadow root)');

    // (2) overlay banner renders crisp HTML text over the image.
    await page.waitForFunction(() => {
      const w = document.querySelector('[data-trayon-banner="2"]');
      return w && w.shadowRoot && w.shadowRoot.querySelector('.ton-banner__headline');
    }, { timeout: 6000 });
    const headline = await page.evaluate(() => document.querySelector('[data-trayon-banner="2"]').shadowRoot.querySelector('.ton-banner__headline').textContent);
    assert(headline.includes('Big Summer Sale'), `overlay banner renders crisp HTML text (${headline.trim()})`);

    // (3) rule eval: the club-members banner is hidden from a non-member.
    assert((await page.evaluate(() => document.querySelectorAll('[data-trayon-banner="3"]').length)) === 0,
      'club-members-only banner is hidden from a non-member (client-side rule eval)');

    // (4) exactly one impression per SHOWN banner (1 + 2), none for the hidden 3.
    for (let i = 0; i < 25; i++) {
      await page.waitForTimeout(120);
      if (events.filter((e) => e.kind === 'impression').length >= 2) break;
    }
    const impressions = events.filter((e) => e.kind === 'impression').map((e) => e.banner_id).sort();
    assert(impressions.length === 2 && impressions.includes(1) && impressions.includes(2),
      `one impression per shown banner, none for the hidden one (${impressions.join(',')})`);

    await page.screenshot({ path: resolve(OUT, 'widget-banners.en.png'), fullPage: false });
    console.log('  -> screenshot: widget-banners.en.png');

    // (5) a click beacons a banner event (the destination is stubbed so the navigation resolves).
    await page.route(`${dest}*`, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>ok</body></html>' }));
    const preClick = events.length;
    await page.evaluate(() => document.querySelector('[data-trayon-banner="1"]').shadowRoot.querySelector('a').click());
    for (let i = 0; i < 25; i++) {
      await page.waitForTimeout(120);
      if (events.length > preClick) break;
    }
    assert(events.length > preClick, 'clicking a banner fires a banner event (beacon)');
    // If the beacon body happened to be readable, it must be a click for the clicked banner.
    const clickEvt = events.slice(preClick).find((e) => e.kind === 'click');
    if (clickEvt) assert(clickEvt.banner_id === 1, 'the recorded click event is for the clicked banner');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, 'widget-banners-FAIL.png') }).catch(() => {});
  }

  await page.close();
  return failures;
}

/**
 * THE REAL CART. The old bridge did a blind anchor.click() on the host's button and never checked
 * whether anything reached the cart. This gate proves the three things that were broken:
 *   1. the widget reads the platform context the Theme App Extension stamps on the tag;
 *   2. it POSTs /cart/add.js with the NUMERIC Shopify variant id (not our internal DB key) and
 *      the `_trayon` line-item property (the hook Phase 6 attributes the purchase with);
 *   3. it VERIFIES via /cart.js — a 200 from add.js is not proof — before saying "Added to cart".
 * Then: a 422 (sold out) surfaces its own honest message, not "something went wrong".
 */
async function realCartGate(browser) {
  console.log('\n=== REAL add-to-cart (shopify ajax + /cart.js verify + _trayon property) ===');
  let failures = 0;

  // (A) The happy path: the line really lands in the cart.
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  await stubApi(page, 'en');
  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(shopifyBootstrapBody('en')) }));
  const cart = installMockCart(page);

  try {
    await page.goto(ORIGIN + SHOPIFY_PATH, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);

    // The tag's platform context reached the widget.
    const context = await page.evaluate(() => ({
      platform: window.__TrayOn.state.platform,
      externalVariantId: window.__TrayOn.state.externalVariantId,
      variantExternal: window.__TrayOn.state.variant && window.__TrayOn.state.variant.externalId,
    }));
    assert(context.platform === 'shopify', `data-platform reached the widget (${context.platform})`);
    assert(context.externalVariantId === SHOPIFY_VARIANT_M, `data-variant-id reached the widget (${context.externalVariantId})`);
    assert(context.variantExternal === SHOPIFY_VARIANT_M, 'the selected variant carries the NUMERIC Shopify id, not our DB key');

    await openModal(page);
    await page.locator('.ton-upload__file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG_UPLOAD_BYTES });
    await page.locator('.ton-preview__img').waitFor({ timeout: 6000 });
    await page.locator('.ton-input').first().fill('175');
    await page.locator('.ton-consent__box').check();
    await page.locator('.ton-cta').click({ timeout: 6000 });
    await page.locator('.ton-result__img').waitFor({ timeout: 8000 });

    await page.locator('.ton-action--primary').click();
    await page.waitForFunction(
      () => {
        const btn = document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-action--primary');
        return btn && /Added/i.test(btn.textContent);
      },
      { timeout: 8000 },
    );

    // What the widget actually POSTed.
    assert(cart.adds.length === 1, `exactly one /cart/add.js POST (${cart.adds.length})`);
    const line = cart.adds[0].items[0];
    assert(String(line.id) === SHOPIFY_VARIANT_M, `add.js carried the REAL numeric variant id (${line.id})`);
    assert(line.quantity === 1, 'add.js carried quantity 1');
    assert(line.properties && line.properties[TRAYON_LINE_PROPERTY], `the ${TRAYON_LINE_PROPERTY} line-item property is attached (${line.properties[TRAYON_LINE_PROPERTY]})`);

    // What the CART actually contains (the only proof that counts).
    const inCart = await page.evaluate(async () => {
      const res = await fetch('/cart.js');
      return res.json();
    });
    const found = inCart.items.find((i) => String(i.variant_id) === SHOPIFY_VARIANT_M);
    assert(!!found, '/cart.js really contains the line for the selected variant');
    assert(!!(found && found.properties && found.properties._trayon), '/cart.js line carries the _trayon attribution property');

    await page.screenshot({ path: resolve(OUT, 'widget-cart.en.png'), fullPage: false });
    console.log('  -> screenshot: widget-cart.en.png');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, 'widget-cart-FAIL.png') }).catch(() => {});
  }
  await page.close();

  // (B) Sold out (422): an honest, distinct message — and the button comes back.
  const p2 = await browser.newPage({ viewport: { width: 900, height: 900 } });
  await stubApi(p2, 'en');
  await p2.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(shopifyBootstrapBody('en')) }));
  installMockCart(p2, { addStatus: 422 });

  try {
    await p2.goto(ORIGIN + SHOPIFY_PATH, { waitUntil: 'domcontentloaded' });
    await waitForBoot(p2);
    await openModal(p2);
    await p2.locator('.ton-upload__file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG_UPLOAD_BYTES });
    await p2.locator('.ton-preview__img').waitFor({ timeout: 6000 });
    await p2.locator('.ton-input').first().fill('175');
    await p2.locator('.ton-consent__box').check();
    await p2.locator('.ton-cta').click({ timeout: 6000 });
    await p2.locator('.ton-result__img').waitFor({ timeout: 8000 });
    await p2.locator('.ton-action--primary').click();

    await p2.waitForFunction(
      () => {
        const toast = document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-toast');
        return toast && !toast.hasAttribute('hidden') && /available/i.test(toast.textContent);
      },
      { timeout: 8000 },
    );
    assert(true, 'a 422 renders the honest "that option isn\'t available" message');

    const enabled = await p2.evaluate(
      () => !document.querySelector('#trayon-host').shadowRoot.querySelector('.ton-action--primary').disabled,
    );
    assert(enabled, 'after a failed add the button returns (the shopper can retry)');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }
  await p2.close();

  return failures;
}

/**
 * Variant sync FROM THE THEME EXTENSION. The extension keeps data-variant-id truthful across
 * swatch clicks / ?variant= / popstate / DOM mutation and dispatches `trayon:variant-change`
 * (note: NOT our internal `trayon:variant-changed`). If the widget ignores it, the shopper tries
 * on one variant and buys another. This proves the new id reaches BOTH state and the cart call.
 */
async function variantSyncGate(browser) {
  console.log('\n=== variant sync from the Theme Extension (trayon:variant-change) ===');
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  let failures = 0;

  await stubApi(page, 'en');
  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(shopifyBootstrapBody('en')) }));
  const cart = installMockCart(page);

  try {
    await page.goto(ORIGIN + SHOPIFY_PATH, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);

    // The shopper switches to S: the theme rewrites the tag and fires the extension's event.
    // The sync is debounced (one sync per burst of host signals), so wait for the mapped variant,
    // not just the raw attribute.
    await page.selectOption('#variant', SHOPIFY_VARIANT_S);
    await page.waitForFunction(
      (id) => window.__TrayOn.state.variant && window.__TrayOn.state.variant.externalId === id,
      SHOPIFY_VARIANT_S,
      { timeout: 5000 },
    );
    const after = await page.evaluate(() => ({
      key: window.__TrayOn.state.variant.key,
      external: window.__TrayOn.state.variant.externalId,
    }));
    assert(after.external === SHOPIFY_VARIANT_S, `trayon:variant-change updated the external id (${after.external})`);
    assert(after.key === '1', `and it re-mapped onto the right confirmed variant (key=${after.key})`);

    // ...and the cart call for the NEW variant carries the NEW id.
    await openModal(page);
    await page.locator('.ton-upload__file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG_UPLOAD_BYTES });
    await page.locator('.ton-preview__img').waitFor({ timeout: 6000 });
    await page.locator('.ton-input').first().fill('175');
    await page.locator('.ton-consent__box').check();
    await page.locator('.ton-cta').click({ timeout: 6000 });
    await page.locator('.ton-result__img').waitFor({ timeout: 8000 });
    await page.locator('.ton-action--primary').click();
    await page.waitForFunction(() => window.__TrayOn && true, { timeout: 1000 });

    for (let i = 0; i < 30 && cart.adds.length === 0; i++) await page.waitForTimeout(100);
    assert(cart.adds.length === 1, 'the cart was called once');
    assert(String(cart.adds[0].items[0].id) === SHOPIFY_VARIANT_S,
      `the cart add used the NEWLY selected variant (${cart.adds[0].items[0].id})`);
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, 'widget-variant-FAIL.png') }).catch(() => {});
  }

  await page.close();
  return failures;
}

/**
 * The lazy-chunk UX (§6.3). Two halves:
 *  - a SLOW chunk: the trigger becomes the spinner immediately, and past 250 ms the skeleton shell
 *    opens in the very box the modal will land in (never an empty modal flashed for 200 ms);
 *  - a FAILED chunk: no half-drawn modal, no exception into the host page — the trigger comes back
 *    and the HUD offers a retry.
 */
async function lazyChunkGate(browser) {
  console.log('\n=== lazy chunk UX (trigger spinner -> skeleton -> retryable HUD) ===');
  let failures = 0;

  // (A) Slow chunk -> the skeleton shell.
  const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
  await stubApi(page, 'en');
  await page.route('**/widget/v1/widget.modal.js', async (route) => {
    await new Promise((r) => setTimeout(r, 1500));
    route.continue();
  });

  try {
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);

    await page.evaluate((s) => document.querySelector('[' + s + ']').shadowRoot.querySelector('.ton-button').click(), SENTINEL);

    await page.locator('.ton-button--loading').waitFor({ timeout: 2000 });
    assert(true, 'the trigger itself becomes the progress indicator the moment it is tapped');

    await page.locator('.ton-skeleton').waitFor({ timeout: 3000 });
    assert(true, 'past 250 ms the skeleton shell opens (the wait is real, so we acknowledge it)');

    await page.locator('.ton-cta').waitFor({ timeout: 8000 });
    assert((await page.locator('.ton-skeleton').count()) === 0, 'the real modal replaces the skeleton when the chunk lands');
    await page.screenshot({ path: resolve(OUT, 'widget-skeleton.en.png'), fullPage: false }).catch(() => {});
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }
  await page.close();

  // (B) Failed chunk -> the retryable HUD, and nothing broken on the merchant's page.
  const p2 = await browser.newPage({ viewport: { width: 900, height: 900 } });
  const pageErrors = [];
  p2.on('pageerror', (e) => pageErrors.push(e.message));
  await stubApi(p2, 'en');
  await p2.route('**/widget/v1/widget.modal.js', (route) => route.abort());

  try {
    await p2.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
    await waitForBoot(p2);
    await p2.evaluate((s) => document.querySelector('[' + s + ']').shadowRoot.querySelector('.ton-button').click(), SENTINEL);

    await p2.locator('.ton-notification--error').waitFor({ timeout: 8000 });
    assert(true, 'a failed chunk surfaces the retryable HUD (--error)');
    assert((await p2.locator('.ton-modal').count()) === 0, 'no half-drawn modal is left on the merchant page');
    assert((await p2.locator('.ton-button--loading').count()) === 0, 'the trigger returns to its default state');
    assert(pageErrors.length === 0, `no exception thrown into the host page (${pageErrors.join(' | ') || 'none'})`);
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }
  await p2.close();

  return failures;
}

/**
 * The ON-IMAGE trigger (the one new placement enum value). It renders INSIDE the merchant's
 * product-image container, exactly once, with zero host layout shift — and when the product_image
 * selector does not resolve it FALLS BACK to below add-to-cart, because the button must never
 * vanish from a live PDP.
 */
async function onImagePlacementGate(browser) {
  console.log('\n=== on-image trigger placement (+ its fallback, + zero CLS) ===');
  let failures = 0;

  const openPage = async (appearance) => {
    const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await page.addInitScript(() => {
      window.__cls = 0;
      try {
        new PerformanceObserver((list) => {
          for (const e of list.getEntries()) if (!e.hadRecentInput) window.__cls += e.value;
        }).observe({ type: 'layout-shift', buffered: true });
      } catch { /* unsupported -> tolerates 0 */ }
    });
    await stubApi(page, 'en');
    await page.route('**/widget/v1/bootstrap*', (route) => {
      const body = shopifyBootstrapBody('en');
      Object.assign(body.site.appearance, appearance);
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    });
    return page;
  };

  // (1) The trigger sits inside the product-image container.
  try {
    const page = await openPage({ button_placement: 'on_product_image' });
    await page.goto(ORIGIN + SHOPIFY_PATH, { waitUntil: 'load' });
    await waitForBoot(page);

    const placed = await page.evaluate((sentinel) => {
      const media = document.querySelector('.product__media');
      const btn = document.querySelector('[' + sentinel + ']');
      return {
        inside: !!(btn && media && media.contains(btn)),
        count: document.querySelectorAll('[' + sentinel + ']').length,
        containerPosition: getComputedStyle(media).position,
        glass: !!btn.shadowRoot.querySelector('.ton-button--on-image'),
      };
    }, SENTINEL);

    assert(placed.inside, 'the trigger renders INSIDE the product-image container');
    assert(placed.count === 1, 'exactly once (no duplicate)');
    assert(placed.glass, 'and it wears the glass on-image skin (it ignores button_bg by design)');
    assert(placed.containerPosition === 'relative', 'the one host style write (position: relative) was made');

    const cls = await page.evaluate(() => window.__cls || 0);
    assert(cls < CLS_BUDGET, `on-image placement caused no host layout shift (CLS ${cls.toFixed(4)} < ${CLS_BUDGET})`);

    await page.screenshot({ path: resolve(OUT, 'widget-on-image.en.png'), fullPage: false });
    console.log('  -> screenshot: widget-on-image.en.png');
    await page.close();
  } catch (e) { failures++; console.error('  FAIL:', e.message); }

  // (2) An unresolvable product_image selector -> fall back to below add-to-cart.
  try {
    const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
    await stubApi(page, 'en');
    await page.route('**/widget/v1/bootstrap*', (route) => {
      const body = shopifyBootstrapBody('en');
      body.site.appearance.button_placement = 'on_product_image';
      body.site.selectors.product_image = { primary: '#nope-not-here' };
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    });
    await page.goto(ORIGIN + SHOPIFY_PATH, { waitUntil: 'domcontentloaded' });
    await waitForBoot(page);

    const fellBack = await page.evaluate((sentinel) => {
      const atc = document.querySelector('#add-to-cart');
      const btn = document.querySelector('[' + sentinel + ']');
      return !!btn && !!(atc.compareDocumentPosition(btn) & Node.DOCUMENT_POSITION_FOLLOWING) && btn.parentNode === atc.parentNode;
    }, SENTINEL);
    assert(fellBack, 'an unresolvable product_image selector falls back to below add-to-cart');
    await page.close();
  } catch (e) { failures++; console.error('  FAIL:', e.message); }

  return failures;
}

/**
 * The visual record of the rebuild, EN and HE. Not an assertion — a receipt. Every surface the
 * redesign touches, in both directions, so a reviewer can see that Hebrew mirrors (the trigger
 * moves to the image's bottom-RIGHT, the HUD slides in from the right, the CTA drops its
 * letter-spacing) without opening a browser.
 */
async function designScreenshotGate(browser, locale) {
  console.log(`\n=== design screenshots (${locale}) ===`);
  const page = await browser.newPage({ viewport: { width: 900, height: 1000 } });
  let failures = 0;
  let polls = 0;

  await stubApi(page, locale);
  await page.route('**/widget/v1/bootstrap*', (route) => {
    const body = shopifyBootstrapBody(locale);
    body.site.appearance.button_placement = 'on_product_image';
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
  });
  // Hold `processing` for a few polls so the thinking states are actually photographable.
  await page.route('**/widget/v1/generations/7*', (route) => {
    polls++;
    const status = polls >= 4 ? 'succeeded' : 'processing';
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, generation: { id: 7, status, failure_code: null, result_url: status === 'succeeded' ? RESULT_PNG : null, created_at: null } }),
    });
  });
  installMockCart(page);

  try {
    await page.goto(ORIGIN + SHOPIFY_PATH, { waitUntil: 'domcontentloaded' });
    if (locale === 'he') await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
    await waitForBoot(page);

    await page.screenshot({ path: resolve(OUT, `design-trigger.${locale}.png`) });

    await openModal(page);
    await page.screenshot({ path: resolve(OUT, `design-setup.${locale}.png`) });

    await page.locator('.ton-upload__file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG_UPLOAD_BYTES });
    await page.locator('.ton-preview__img').waitFor({ timeout: 6000 });
    await page.locator('.ton-input').first().fill('175');
    await page.locator('.ton-consent__box').check();
    await page.screenshot({ path: resolve(OUT, `design-setup-ready.${locale}.png`) });

    await page.locator('.ton-cta').click({ timeout: 6000 });
    await page.locator('.ton-loading__frame').waitFor({ timeout: 6000 });
    await page.screenshot({ path: resolve(OUT, `design-thinking.${locale}.png`) });

    // Close mid-generation: the HUD picks the look up and carries it.
    await page.locator('.ton-modal__close').click();
    await page.locator('.ton-notification--thinking').waitFor({ timeout: 5000 });
    await page.screenshot({ path: resolve(OUT, `design-hud-thinking.${locale}.png`) });

    await page.locator('.ton-notification--ready').waitFor({ timeout: 10000 });
    await page.screenshot({ path: resolve(OUT, `design-hud-ready.${locale}.png`) });

    await page.locator('.ton-notification__main').click();
    await page.locator('.ton-result__img').waitFor({ timeout: 8000 });
    await page.screenshot({ path: resolve(OUT, `design-result.${locale}.png`) });

    assert(true, `design screenshots captured (trigger / setup / thinking / HUD / result) — ${locale}`);
    console.log(`  -> design-{trigger,setup,setup-ready,thinking,hud-thinking,hud-ready,result}.${locale}.png`);
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
    await page.screenshot({ path: resolve(OUT, `design-FAIL.${locale}.png`) }).catch(() => {});
  }

  await page.close();
  return failures;
}

async function run() {
  // Static perf gates first (no browser needed): async snippet + the SPLIT gzip budget.
  let failures = staticPerfGates();

  const server = await startServer();
  // Prefer bundled Chromium; fall back to system Chrome (the existing visual harnesses do).
  const browser = await chromium
    .launch()
    .catch(() => chromium.launch({ channel: 'chrome' }));

  for (const locale of ['en', 'he']) {
    console.log(`\n=== locale: ${locale} ===`);
    const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
    if (locale === 'he') await page.evaluate(() => {}).catch(() => {});

    await stubApi(page, locale);
    await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });

    // Set the document dir to mirror the host for HE (the widget inherits it).
    if (locale === 'he') {
      await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
    }

    try {
      await waitForBoot(page);

      // --- mount gate: button is BELOW add-to-cart and exactly one ---
      const mountInfo = await page.evaluate((sentinel) => {
        const atc = document.querySelector('#add-to-cart');
        const wrappers = document.querySelectorAll('[' + sentinel + ']');
        const btn = wrappers[0];
        const below = btn ? !!(atc.compareDocumentPosition(btn) & Node.DOCUMENT_POSITION_FOLLOWING) : false;
        return { count: wrappers.length, below, hasBtn: !!btn };
      }, SENTINEL);
      assert(mountInfo.hasBtn, 'button is mounted');
      assert(mountInfo.count === 1, 'button mounted exactly once (no duplicate)');
      assert(mountInfo.below, 'button sits BELOW the add-to-cart element');

      // --- appearance gate: label + bg color applied from config ---
      const appearance = await page.evaluate((sentinel) => {
        const wrapper = document.querySelector('[' + sentinel + ']');
        const root = wrapper.shadowRoot;
        const button = root.querySelector('.ton-button');
        const cs = root.host.shadowRoot ? getComputedStyle(button) : null;
        return { label: button.textContent.trim(), bg: getComputedStyle(button).backgroundColor };
      }, SENTINEL);
      assert(
        appearance.label.includes(locale === 'he' ? 'מדדו' : 'Tray On'),
        `button label from config (${appearance.label})`,
      );
      assert(appearance.bg === 'rgb(10, 125, 82)', `button bg from config (${appearance.bg})`);

      // --- CSS-bleed gate: the button lives in a shadow root; host .ton-button rule can't reach it ---
      const bleed = await page.evaluate((sentinel) => {
        const wrapper = document.querySelector('[' + sentinel + ']');
        const isShadow = wrapper.shadowRoot instanceof ShadowRoot;
        // The host page declares .ton-button{background:lime!important;font-size:40px!important}.
        // If isolation holds, the button is NOT lime/40px.
        const button = wrapper.shadowRoot.querySelector('.ton-button');
        const cs = getComputedStyle(button);
        const lightDomLeak = !!document.querySelector('body > .ton-modal, main .ton-modal');
        return { isShadow, bg: cs.backgroundColor, fontSize: cs.fontSize, lightDomLeak };
      }, SENTINEL);
      assert(bleed.isShadow, 'widget button is inside a ShadowRoot');
      assert(bleed.bg !== 'rgb(0, 255, 0)', 'host .ton-button{lime} did NOT bleed in');
      assert(bleed.fontSize !== '40px', 'host font-size:40px did NOT bleed in');
      assert(!bleed.lightDomLeak, 'widget markup does NOT leak into the host light DOM');

      // --- variant sync gate: select S -> state.variant updates ---
      await page.selectOption('#variant', 'TEE-S');
      await page.waitForTimeout(400);
      const variantKey = await page.evaluate(() => window.__TrayOn.state.variant && window.__TrayOn.state.variant.key);
      assert(variantKey === '1', `variant change propagated to state.variant (key=${variantKey})`);
      // restore M for the screenshots
      await page.selectOption('#variant', 'TEE-M');
      await page.waitForTimeout(300);

      // --- SPA re-render gate: remove + re-add the PDP subtree, button re-injects (still one) ---
      await page.evaluate(() => {
        const main = document.querySelector('main');
        const clone = main.cloneNode(true);
        main.replaceWith(clone);
      });
      await page.waitForFunction(() => document.querySelectorAll('[data-trayon-mounted]').length === 1, { timeout: 5000 });
      const afterRerender = await page.evaluate((sentinel) => document.querySelectorAll('[' + sentinel + ']').length, SENTINEL);
      assert(afterRerender === 1, 'after SPA re-render: button re-injected, still exactly one');

      // --- open the modal + consent gate ---
      // Wait for a LIVE button (shadow root + .ton-button) before clicking — the debounced
      // observer re-injects asynchronously after the SPA re-render.
      await page.waitForFunction((sentinel) => {
        const w = document.querySelector('[' + sentinel + ']');
        return !!(w && w.shadowRoot && w.shadowRoot.querySelector('.ton-button'));
      }, SENTINEL, { timeout: 5000 });
      await openModal(page);

      const modalState = await page.evaluate(() => {
        const host = document.querySelector('#trayon-host');
        const sr = host.shadowRoot;
        const modal = sr.querySelector('.ton-modal');
        const cta = sr.querySelector('.ton-cta');
        return { hasModal: !!modal, ctaDisabled: cta ? cta.disabled : null, dir: sr.querySelector('.ton-root').getAttribute('dir') };
      });
      assert(modalState.hasModal, 'modal opened inside the shadow root');
      assert(modalState.ctaDisabled === true, 'submit CTA is disabled until photo + height + consent');
      assert(modalState.dir === (locale === 'he' ? 'rtl' : 'ltr'), `modal dir inherited (${modalState.dir})`);

      // Screenshot the modal (EN + HE record).
      await page.screenshot({ path: resolve(OUT, `widget-modal.${locale}.png`), fullPage: false });

      // Close + screenshot the mounted button on the page.
      await page.evaluate(() => {
        const host = document.querySelector('#trayon-host');
        host.shadowRoot.querySelector('.ton-modal__close').click();
      });
      await page.waitForTimeout(200);
      await page.screenshot({ path: resolve(OUT, `widget-pdp.${locale}.png`), fullPage: false });

      console.log(`  -> screenshots: widget-pdp.${locale}.png, widget-modal.${locale}.png`);
    } catch (e) {
      failures++;
      console.error('  FAIL:', e.message);
      await page.screenshot({ path: resolve(OUT, `widget-FAIL.${locale}.png`) }).catch(() => {});
    }

    await page.close();
  }

  // --- non-PDP gate: product=null -> the widget does nothing ---
  console.log('\n=== non-PDP (product=null) ===');
  const page = await browser.newPage();
  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, site: bootstrapBody('en').site, lead: { registered: false, free_remaining: 3, signup_required: false }, product: null }),
    }),
  );
  await page.goto(ORIGIN, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  try {
    const mounted = await page.evaluate((sentinel) => document.querySelectorAll('[' + sentinel + ']').length, SENTINEL);
    assert(mounted === 0, 'non-PDP (product=null): no button mounted, no errors');
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }
  await page.close();

  // --- async notification gate (close popup mid-generation -> notify -> reopen) ---
  failures += await asyncNotificationGate(browser);

  // --- cross-page resume gate (reload while generating -> notify on the fresh page) ---
  failures += await crossPageResumeGate(browser);

  // --- cross-tab gate (completion in tab 1 notifies tab 2, shared storage + BroadcastChannel) ---
  failures += await crossTabGate(browser);

  // --- gallery gate (past try-ons strip -> tap to view) ---
  failures += await galleryGate(browser);

  // --- custom placement gate (visual picker anchor + runtime fallback) ---
  failures += await customPlacementGate(browser);

  // --- perf + tracking gate (no sync work / CLS / meaningful-events-only) ---
  failures += await perfTrackingGate(browser);

  // --- THE REBUILD's new gates -------------------------------------------------
  failures += await realCartGate(browser); // the cart is real, verified, and attributed
  failures += await variantSyncGate(browser); // the extension's variant reaches state AND the cart
  failures += await lazyChunkGate(browser); // the split bundle's UX, including its failure mode
  failures += await onImagePlacementGate(browser); // the one new placement + its fallback

  // --- Customer Club: banner visibility (EN + HE), login flow, typed failures, member pricing ---
  failures += await clubBannerGate(browser, 'en');
  failures += await clubBannerGate(browser, 'he');
  failures += await clubLoginFlowGate(browser);
  failures += await clubFailureGate(browser);
  failures += await memberPricingGate(browser);
  failures += await clubBannerBehaviorGate(browser);
  failures += await bannerRuntimeGate(browser);

  await browser.close();
  server.close();

  console.log(`\n${failures === 0 ? 'ALL WIDGET GATES PASSED' : failures + ' GATE(S) FAILED'}`);
  process.exit(failures === 0 ? 0 : 1);
}

run().catch((e) => {
  console.error(e);
  process.exit(1);
});
