// =============================================================================
// Visual proof harness for the OpenRouter-look retune. Captures EN + HE shots of
// a merchant panel screen, a platform panel screen, and the storefront widget
// (button + opened modal), into a target dir. Run twice (before/after the token
// retune) to produce the before/after comparison. Drives system Chrome.
//
//   node tests/visual/openrouter-look.mjs <outDir>
//
// The widget is rendered by serving the mock PDP inline and intercepting the
// signed /widget/v1/bootstrap call with a minimal config so the widget paints
// with its OWN default tokens (the retuned indigo look), no backend needed.
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://127.0.0.1:8123';
const OUT = process.argv[2] ?? 'docs/ux/screens/openrouter-look/after';
const MERCHANT = { email: 'owner-a@trayon.test', password: 'password' };
const PLATFORM = { email: 'admin@trayon.test', password: 'password' };
const WIDGET_JS = resolve('public/widget/v1/widget.js');
const MOCK_PDP = readFileSync(resolve('tests/widget/mock-pdp.html'), 'utf8');

const MERCHANT_SCREEN = { name: 'merchant-dashboard', path: '/merchant' };
const PLATFORM_SCREEN = { name: 'platform-dashboard', path: '/platform' };

const LOCALES = ['en', 'he'];

mkdirSync(OUT, { recursive: true });

async function login(page, panel, creds) {
  await page.goto(`${BASE}/${panel}/login`, { waitUntil: 'networkidle' });
  await page.locator('input[id="data.email"]').waitFor({ timeout: 15000 });
  await page.fill('input[id="data.email"]', creds.email);
  await page.fill('input[id="data.password"]', creds.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(1500);
}

async function shootPanel(page, screen, locale) {
  await page.goto(`${BASE}${screen.path}?locale=${locale}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1400); // let the webfont settle
  const dir = await page.getAttribute('html', 'dir');
  const inlineCount = await page.locator('[style]').count();
  const accent = await page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--toa-accent').trim());
  const font = await page.evaluate(() =>
    getComputedStyle(document.querySelector('.fi-body') || document.body).fontFamily);
  const file = `${OUT}/${screen.name}.${locale}.png`;
  await page.screenshot({ path: file, fullPage: false });
  return { screen: screen.name, locale, dir, inlineCount, accent, font, file };
}

function bootstrapBody(locale) {
  return JSON.stringify({
    ok: true,
    site: {
      appearance: {}, // empty -> widget uses its own retuned default tokens
      selectors: {
        add_to_cart: '#add-to-cart',
        product_image: '.product__image',
        title: '.product__title',
        price: '.product__price',
        variations: '#variant',
      },
      locale,
      privacy: { show_source_photo: false },
      gallery: { enabled: true },
    },
    product: {
      id: 1,
      title: 'Aviad Linen Tee',
      price: '$89.00',
      image: null,
      variants: [
        { id: 'TEE-S', label: 'S' },
        { id: 'TEE-M', label: 'M' },
      ],
    },
    lead: null,
  });
}

async function shootWidget(context, locale) {
  const page = await context.newPage();
  // Serve the widget bundle + intercept the signed bootstrap with a minimal config.
  await page.route('**/widget/v1/widget.js', (route) =>
    route.fulfill({ contentType: 'application/javascript', body: readFileSync(WIDGET_JS, 'utf8') }));
  await page.route('**/widget/v1/bootstrap*', (route) =>
    route.fulfill({ contentType: 'application/json', body: bootstrapBody(locale) }));

  const html = MOCK_PDP.replace('<html lang="en">',
    `<html lang="${locale}" dir="${locale === 'he' ? 'rtl' : 'ltr'}">`);
  await page.route('https://mock.trayon.test/pdp', (route) =>
    route.fulfill({ contentType: 'text/html', body: html }));
  await page.goto('https://mock.trayon.test/pdp', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1800);

  // Button screenshot.
  const btnFile = `${OUT}/widget-button.${locale}.png`;
  await page.screenshot({ path: btnFile });

  // Open the modal by clicking the injected Tray On button inside the shadow root.
  const opened = await page.evaluate(() => {
    const host = document.querySelector('[data-trayon-mounted], .ton-host, div');
    const roots = [...document.querySelectorAll('*')].map((e) => e.shadowRoot).filter(Boolean);
    for (const r of roots) {
      const b = r.querySelector('.ton-button');
      if (b) { b.click(); return true; }
    }
    return false;
  });
  await page.waitForTimeout(1200);
  const modalFile = `${OUT}/widget-modal.${locale}.png`;
  await page.screenshot({ path: modalFile });

  const accent = await page.evaluate(() => {
    const roots = [...document.querySelectorAll('*')].map((e) => e.shadowRoot).filter(Boolean);
    for (const r of roots) {
      const el = r.querySelector('.ton-root, .ton-button');
      if (el) return getComputedStyle(el).getPropertyValue('--ton-accent').trim();
    }
    return '(no shadow root)';
  });
  await page.close();
  return { screen: 'widget', locale, opened, accent, btnFile, modalFile };
}

const VIEWPORT = { viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 };

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const results = [];

  // Merchant panel — its own isolated context (no shared auth cookie).
  const mctx = await browser.newContext(VIEWPORT);
  const mp = await mctx.newPage();
  await login(mp, 'merchant', MERCHANT);
  for (const locale of LOCALES) results.push(await shootPanel(mp, MERCHANT_SCREEN, locale));
  await mctx.close();

  // Platform panel — its own isolated context (super-admin only).
  const pctx = await browser.newContext(VIEWPORT);
  const pp = await pctx.newPage();
  await login(pp, 'platform', PLATFORM);
  for (const locale of LOCALES) results.push(await shootPanel(pp, PLATFORM_SCREEN, locale));
  await pctx.close();

  // Widget — a clean context (no panel auth needed; the bootstrap is mocked).
  const wctx = await browser.newContext(VIEWPORT);
  for (const locale of LOCALES) results.push(await shootWidget(wctx, locale));
  await wctx.close();

  console.log(JSON.stringify(results, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
