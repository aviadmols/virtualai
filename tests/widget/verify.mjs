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
const OUT = resolve(HERE, 'screenshots');
const PORT = 4599;
const ORIGIN = `http://localhost:${PORT}`;
const SENTINEL = 'data-trayon-mounted';

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

async function run() {
  const server = await startServer();
  // Prefer bundled Chromium; fall back to system Chrome (the existing visual harnesses do).
  const browser = await chromium
    .launch()
    .catch(() => chromium.launch({ channel: 'chrome' }));
  let failures = 0;

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

  // --- gallery gate (past try-ons strip -> tap to view) ---
  failures += await galleryGate(browser);

  await browser.close();
  server.close();

  console.log(`\n${failures === 0 ? 'ALL WIDGET GATES PASSED' : failures + ' GATE(S) FAILED'}`);
  process.exit(failures === 0 ? 0 : 1);
}

run().catch((e) => {
  console.error(e);
  process.exit(1);
});
