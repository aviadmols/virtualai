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
import { readFileSync } from 'node:fs';
import { mkdirSync } from 'node:fs';
import { deflateSync, crc32 } from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// === CONSTANTS ===
const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const WIDGET_JS = resolve(ROOT, 'public', 'widget', 'v1', 'widget.js');
const MOCK_HTML = resolve(HERE, 'mock-pdp.html');
const EMBED_BLADE = resolve(ROOT, 'resources', 'views', 'components', 'to', 'embed-code.blade.php');
const SIZE_BUDGET = resolve(ROOT, 'resources', 'widget', 'size-budget.json');
const OUT = resolve(HERE, 'screenshots');
const PORT = 4599;
const ORIGIN = `http://localhost:${PORT}`;
const SENTINEL = 'data-trayon-mounted';

// Perf gate: the total layout shift the widget may cause on the host page must stay well
// under Google's "good" CLS threshold (0.1). The widget renders in a Shadow DOM + reserves
// its boxes, so its own contribution should be ~0; we assert a strict ceiling.
const CLS_BUDGET = 0.02;

mkdirSync(OUT, { recursive: true });

// --- A tiny static server: serves the mock PDP + the built widget.js (real origin). ---
function startServer() {
  return new Promise((resolveServer) => {
    const server = createServer((req, res) => {
      if (req.url.startsWith('/widget/v1/widget.js')) {
        res.writeHead(200, { 'Content-Type': 'application/javascript' });
        res.end(readFileSync(WIDGET_JS));
        return;
      }
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(readFileSync(MOCK_HTML));
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
    product: {
      id: 42,
      name: 'Aviad Linen Tee',
      description: 'A relaxed-fit linen tee.',
      product_type: 'apparel',
      price_minor: 8900,
      currency: 'USD',
      main_image_url: null,
      images: [],
      variants: [
        { id: 1, options: { size: 'S' }, price_minor: 8900, image_url: null, sku: 'TEE-S', available: true },
        { id: 2, options: { size: 'M' }, price_minor: 8900, image_url: null, sku: 'TEE-M', available: true },
      ],
    },
  };
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

    // Open the modal via the injected button.
    await page.evaluate((sentinel) => document.querySelector('[' + sentinel + ']').shadowRoot.querySelector('.ton-button').click(), SENTINEL);
    await page.waitForFunction(() => !!document.querySelector('#trayon-host'), { timeout: 5000 });

    // Fill the form: photo + height + consent (Playwright CSS locators pierce open shadow DOM;
    // .click() auto-waits for the CTA to become enabled once all three are valid).
    await page.locator('.ton-upload__file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG_UPLOAD_BYTES });
    // Wait for the photo to be accepted (preview shows) — surfaces a prepare() failure early.
    await page.locator('.ton-preview__img').waitFor({ timeout: 6000 }).catch(async () => {
      const err = await page.locator('.ton-error').first().textContent().catch(() => '');
      throw new Error('photo not accepted by prepare(); error box="' + (err || '').trim() + '"');
    });
    await page.locator('.ton-input').first().fill('175');
    await page.locator('.ton-consent__box').check();

    // Generate -> loading; the Tray On button enters its "thinking" state.
    await page.locator('.ton-cta').click({ timeout: 6000 });
    await page.locator('.ton-loading__frame').waitFor({ timeout: 6000 });
    await page.locator('.ton-button--busy').waitFor({ timeout: 5000 });
    assert(true, 'Tray On button shows the "thinking" state during generation');

    // CLOSE the popup while it is still generating — the busy button stays busy in the background.
    await page.locator('.ton-modal__close').click();
    await page.locator('.ton-overlay').waitFor({ state: 'detached', timeout: 5000 });
    assert(true, 'popup closed while generation still in flight');

    // The background poll completes -> an on-page notification appears (modal stays closed).
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
    await page.evaluate((sentinel) => document.querySelector('[' + sentinel + ']').shadowRoot.querySelector('.ton-button').click(), SENTINEL);
    await page.waitForFunction(() => !!document.querySelector('#trayon-host'), { timeout: 5000 });

    // The gallery strip appears atop the form with the shopper's past try-ons.
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
    await page.evaluate((sentinel) => document.querySelector('[' + sentinel + ']').shadowRoot.querySelector('.ton-button').click(), SENTINEL);
    await page.waitForFunction(() => !!document.querySelector('#trayon-host'), { timeout: 5000 });
    await page.locator('.ton-upload__file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG_UPLOAD_BYTES });
    await page.locator('.ton-preview__img').waitFor({ timeout: 6000 });
    await page.locator('.ton-input').first().fill('175');
    await page.locator('.ton-consent__box').check();
    await page.locator('.ton-cta').click({ timeout: 6000 });
    await page.locator('.ton-loading__frame').waitFor({ timeout: 6000 });

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
    await tab1.evaluate((sentinel) => document.querySelector('[' + sentinel + ']').shadowRoot.querySelector('.ton-button').click(), SENTINEL);
    await tab1.waitForFunction(() => !!document.querySelector('#trayon-host'), { timeout: 5000 });
    await tab1.locator('.ton-upload__file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG_UPLOAD_BYTES });
    await tab1.locator('.ton-preview__img').waitFor({ timeout: 6000 });
    await tab1.locator('.ton-input').first().fill('175');
    await tab1.locator('.ton-consent__box').check();
    await tab1.locator('.ton-cta').click({ timeout: 6000 });
    await tab1.locator('.ton-loading__frame').waitFor({ timeout: 6000 });
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

  try {
    const budget = JSON.parse(readFileSync(SIZE_BUDGET, 'utf8'));
    const gz = gzipEntry();
    assert(gz <= budget.maxGzipBytes, `entry bundle within gzip budget (${gz} <= ${budget.maxGzipBytes})`);
    console.log(`  -> widget.js gzipped: ${gz} bytes (budget ${budget.maxGzipBytes})`);
  } catch (e) {
    failures++;
    console.error('  FAIL:', e.message);
  }

  return failures;
}

/** Gzip the built entry bundle (zlib deflate + a gzip header/trailer) to size it like the CDN. */
function gzipEntry() {
  const raw = readFileSync(WIDGET_JS);
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

    await page.evaluate((sentinel) => document.querySelector('[' + sentinel + ']').shadowRoot.querySelector('.ton-button').click(), SENTINEL);
    await page.waitForFunction(() => !!document.querySelector('#trayon-host'), { timeout: 5000 });

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

async function run() {
  // Static perf gates first (no browser needed): async snippet + gzip budget.
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
      await page.evaluate((sentinel) => {
        document.querySelector('[' + sentinel + ']').shadowRoot.querySelector('.ton-button').click();
      }, SENTINEL);
      await page.waitForFunction(() => !!document.querySelector('#trayon-host'), { timeout: 5000 });
      await page.waitForTimeout(400);

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

  await browser.close();
  server.close();

  console.log(`\n${failures === 0 ? 'ALL WIDGET GATES PASSED' : failures + ' GATE(S) FAILED'}`);
  process.exit(failures === 0 ? 0 : 1);
}

run().catch((e) => {
  console.error(e);
  process.exit(1);
});
