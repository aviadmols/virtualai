// =============================================================================
// Visual verification harness — the Banners redesign. Logs in as the demo
// merchant, then captures EN + HE shots of the banners list, the editor (the
// guided steps strip + the candidates gallery), and the Generate modal (the
// visual style cards + the product-tag toolbar/menu). Drives the system Chrome
// (channel: 'chrome') so no browser download is needed.
// Run: node tests/visual/banner-redesign-shots.mjs
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://127.0.0.1:8000';
const EMAIL = process.env.TO_MERCHANT_EMAIL ?? 'owner-a@trayon.test';
const PASSWORD = process.env.TO_MERCHANT_PASSWORD ?? 'password';
const SITE = process.env.TO_SITE_SLUG ?? 'store-a-9jbcqq';
const BANNER_ID = process.env.TO_BANNER_ID ?? '1';
const OUT = process.env.TO_SHOT_DIR ?? 'tests/screenshots/banners';

// The two locale-sensitive action labels the script clicks.
const GENERATE_LABEL = { en: 'Generate image', he: 'הפקת תמונה' };
const TAG_LABEL = { en: 'Tag a product', he: 'תיוג מוצר' };

mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
const page = await context.newPage();

await page.goto(`${BASE}/merchant/login`, { waitUntil: 'domcontentloaded' });
await page.fill('input[type="email"]', EMAIL);
await page.fill('input[type="password"]', PASSWORD);
await Promise.all([
  page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
  page.click('button[type="submit"]'),
]);
await page.waitForTimeout(1200);
console.error('post-login:', page.url());

for (const locale of ['en', 'he']) {
  await page.goto(`${BASE}/merchant/${SITE}/banners?locale=${locale}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  await page.screenshot({ path: `${OUT}/redesign-list.${locale}.png`, fullPage: true });

  await page.goto(`${BASE}/merchant/${SITE}/banners/${BANNER_ID}/edit?locale=${locale}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/redesign-editor.${locale}.png`, fullPage: true });

  try {
    await page.click(`button:has-text("${GENERATE_LABEL[locale]}")`);
    await page.waitForSelector('.fi-modal-window', { timeout: 10000 });
    await page.waitForTimeout(900);
    await page.screenshot({ path: `${OUT}/redesign-generate.${locale}.png` });

    await page.click(`button:has-text("${TAG_LABEL[locale]}")`);
    await page.waitForTimeout(600);
    await page.screenshot({ path: `${OUT}/redesign-generate-menu.${locale}.png` });
  } catch (e) {
    console.error(`generate modal (${locale}):`, e.message);
  }
}

await browser.close();
console.error('done');
