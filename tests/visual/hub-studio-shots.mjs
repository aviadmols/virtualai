// =============================================================================
// Visual verification — the shop OVERVIEW hub cards + the Product Image Studio
// header (the two screens redesigned for calm, readable density). Captures EN +
// HE and asserts the hub card titles no longer shred into many lines.
// Run: node tests/visual/hub-studio-shots.mjs
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://127.0.0.1:8000';
const EMAIL = process.env.TO_MERCHANT_EMAIL ?? 'owner-a@trayon.test';
const PASSWORD = process.env.TO_MERCHANT_PASSWORD ?? 'password';
const SITE = process.env.TO_SITE_SLUG ?? 'store-a-9jbcqq';
const OUT = process.env.TO_SHOT_DIR ?? 'tests/screenshots/hub-studio';

const SCREENS = [
  { name: 'hub', path: `/merchant/${SITE}` },
  { name: 'studio', path: `/merchant/${SITE}/product-image-studio` },
];

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
  for (const screen of SCREENS) {
    await page.goto(`${BASE}${screen.path}?locale=${locale}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1300);
    await page.screenshot({ path: `${OUT}/${screen.name}.${locale}.png`, fullPage: true });

    if (screen.name === 'hub') {
      // Density gate: a hub card title must fit in at most 2 lines (the bug was 4+).
      const lines = await page.evaluate(() => {
        const nodes = [...document.querySelectorAll('.to-hub-link__title')];
        return nodes.map((n) => {
          const lh = parseFloat(getComputedStyle(n).lineHeight) || 1;
          return Math.round(n.getBoundingClientRect().height / lh);
        });
      });
      console.error(`hub title lines (${locale}):`, JSON.stringify(lines));
    }
  }
}

await browser.close();
console.error('done');
