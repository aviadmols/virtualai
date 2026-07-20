// One-off: prove the result modal keeps Add to Cart fully on-screen on short viewports,
// and that the preview shrinks instead of scrolling the modal. Run after build:widget.
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { deflateSync, crc32 } from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..');
const DIST = resolve(ROOT, 'public', 'widget', 'v1');
const MOCK = resolve(ROOT, 'tests', 'widget', 'mock-pdp.html');
const OUT = resolve(ROOT, 'tests', 'widget', 'screenshots');
const PORT = 4601;
const ORIGIN = `http://localhost:${PORT}`;
const SENTINEL = 'data-trayon-mounted';

const RESULT_PNG =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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
  ihdr[8] = 8;
  ihdr[9] = 2;
  const row = Buffer.alloc(1 + w * 3);
  for (let x = 0; x < w; x++) {
    row[1 + x * 3] = 90;
    row[1 + x * 3 + 1] = 125;
    row[1 + x * 3 + 2] = 82;
  }
  const raw = Buffer.concat(Array.from({ length: h }, () => row));
  return Buffer.concat([sig, pngChunk('IHDR', ihdr), pngChunk('IDAT', deflateSync(raw)), pngChunk('IEND', Buffer.alloc(0))]);
}
const PNG_UPLOAD_BYTES = makePng(64, 64);

mkdirSync(OUT, { recursive: true });

function bootstrapBody() {
  return {
    ok: true,
    site: {
      locale: 'en',
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
        button_label: 'Tray On',
        button_bg: '#0a7d52',
        button_text_color: '#ffffff',
        popup_theme: 'light',
        popup_accent: '#0a7d52',
      },
    },
    lead: { registered: false, free_remaining: 3, signup_required: false },
    club: { enabled: false, discount_percent: 0, price_zones: { pdp: [], catalog: [], cart: [] }, member: { verified: false } },
    banners: [],
    product: {
      id: 42,
      name: 'Aviad Linen Tee',
      description: 'A relaxed-fit linen tee.',
      price: 89,
      currency: 'USD',
      product_type: 'apparel',
      images: [],
      variants: [
        { id: 1, key: 'TEE-S', label: 'S', available: true, price: 89 },
        { id: 2, key: 'TEE-M', label: 'M', available: true, price: 89 },
      ],
      confirmed: true,
    },
  };
}

function startServer() {
  return new Promise((resolveServer) => {
    const server = createServer((req, res) => {
      const path = req.url.split('?')[0];
      if (path.startsWith('/widget/v1/') && path.endsWith('.js')) {
        const file = join(DIST, path.slice('/widget/v1/'.length));
        if (!existsSync(file)) {
          res.writeHead(404).end('missing');
          return;
        }
        res.writeHead(200, { 'Content-Type': 'application/javascript' });
        res.end(readFileSync(file));
        return;
      }
      if (path.startsWith('/widget/v1/fonts/')) {
        const file = join(DIST, path.slice('/widget/v1/'.length));
        res.writeHead(existsSync(file) ? 200 : 404, { 'Content-Type': 'font/woff2' });
        res.end(existsSync(file) ? readFileSync(file) : '');
        return;
      }
      let html = readFileSync(MOCK, 'utf8');
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(html);
    });
    server.listen(PORT, () => resolveServer(server));
  });
}

async function stubApi(page) {
  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(bootstrapBody()) }),
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
      body: JSON.stringify({
        ok: true,
        generation: { id: 7, status: 'succeeded', failure_code: null, result_url: RESULT_PNG, created_at: null },
      }),
    }),
  );
  await page.route('**/widget/v1/events/**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) }),
  );
  await page.route('**/widget/v1/events', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, recorded: 0 }) }),
  );
  await page.route('**/widget/v1/gallery*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, items: [] }) }),
  );
}

const VIEWPORTS = [
  { name: 'desktop-short', width: 1280, height: 600 },
  { name: 'iphone-se', width: 375, height: 667 },
];

async function checkViewport(browser, vp) {
  const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
  await stubApi(page);
  await page.goto(ORIGIN + '/', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => window.__TrayOn && window.__TrayOn.booted === true, { timeout: 8000 });
  await page.waitForFunction(
    (sentinel) => !!document.querySelector('[' + sentinel + ']'),
    SENTINEL,
    { timeout: 8000 },
  );

  await page.evaluate(
    (sentinel) => document.querySelector('[' + sentinel + ']').shadowRoot.querySelector('.ton-button').click(),
    SENTINEL,
  );
  await page.locator('.ton-cta').waitFor({ timeout: 8000 });
  await page.locator('.ton-upload__file').setInputFiles({
    name: 'me.png',
    mimeType: 'image/png',
    buffer: PNG_UPLOAD_BYTES,
  });
  await page.locator('.ton-preview__img').waitFor({ timeout: 6000 });
  await page.locator('.ton-input').first().fill('175');
  await page.locator('.ton-consent__box').check();
  await page.locator('.ton-cta').click();
  await page.locator('.ton-result__img').waitFor({ timeout: 10000 });
  await page.locator('.ton-action--primary').waitFor({ timeout: 5000 });

  const metrics = await page.evaluate(() => {
    const host = document.querySelector('#trayon-host');
    const root = host.shadowRoot;
    const modal = root.querySelector('.ton-modal');
    const preview = root.querySelector('.ton-preview');
    const cta = root.querySelector('.ton-action--primary');
    const body = root.querySelector('.ton-modal__body');
    const mark = root.querySelector('.ton-brand__mark');
    const vh = window.innerHeight;
    const m = modal.getBoundingClientRect();
    const p = preview.getBoundingClientRect();
    const c = cta.getBoundingClientRect();
    const cs = getComputedStyle(modal);
    return {
      wordmark: (mark && mark.textContent || '').replace(/\s+/g, ' ').trim(),
      hasBodyClass: !!(body && body.classList.contains('ton-modal__body')),
      overflowY: cs.overflowY,
      modalTop: m.top,
      modalBottom: m.bottom,
      modalHeight: m.height,
      previewHeight: p.height,
      ctaTop: c.top,
      ctaBottom: c.bottom,
      ctaFullyVisible: c.top >= 0 && c.bottom <= vh + 0.5,
      modalFitsViewport: m.top >= -0.5 && m.bottom <= vh + 0.5,
      viewportHeight: vh,
      modalScrollHeight: modal.scrollHeight,
      modalClientHeight: modal.clientHeight,
      noInternalScroll: modal.scrollHeight <= modal.clientHeight + 2,
    };
  });

  const shot = join(OUT, `fit-result-${vp.name}.png`);
  await page.screenshot({ path: shot, fullPage: false });
  await page.close();

  const ok =
    metrics.hasBodyClass &&
    metrics.wordmark === 'Vsio' &&
    metrics.overflowY === 'hidden' &&
    metrics.ctaFullyVisible &&
    metrics.modalFitsViewport &&
    metrics.noInternalScroll;

  console.log(`\n[${vp.name} ${vp.width}x${vp.height}]`);
  console.log(JSON.stringify(metrics, null, 2));
  console.log(ok ? '  PASS — Add to Cart fully on-screen, no modal scroll' : '  FAIL');
  console.log('  shot:', shot);
  return ok;
}

const server = await startServer();
const browser = await chromium.launch().catch(() => chromium.launch({ channel: 'chrome' }));
let allOk = true;
for (const vp of VIEWPORTS) {
  try {
    const ok = await checkViewport(browser, vp);
    if (!ok) allOk = false;
  } catch (e) {
    console.error(`[${vp.name}] ERROR:`, e.message);
    allOk = false;
  }
}
await browser.close();
server.close();
process.exit(allOk ? 0 : 1);
