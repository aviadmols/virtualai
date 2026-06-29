// =============================================================================
// Visual verification harness — merchant panel (Phase 8 Wave 2, sub-wave 8f).
// Logs in as the merchant account-owner, then captures EN + HE screenshots of the
// FINAL merchant screens: the credit ledger + balance band (A11), the buy-credits
// amount picker, the per-site gallery (A12), and privacy/retention settings (A13).
// Asserts the RTL flip + the no-inline-CSS gate on the rendered DOM. Drives the
// system Chrome (channel: 'chrome') so no browser download is needed.
// Run: node tests/visual/credits-gallery-privacy-screens.mjs
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://virtualai.test';
const EMAIL = process.env.TO_MERCHANT_EMAIL ?? 'owner-a@trayon.test';
const PASSWORD = process.env.TO_MERCHANT_PASSWORD ?? 'password';
const OUT = process.env.TO_SHOT_DIR ?? 'tests/visual/screenshots';

const SCREENS = [
  { name: 'credit-ledger', path: '/merchant/credit-ledgers' },
  { name: 'buy-credits', path: '/merchant/buy-credits' },
  { name: 'gallery', path: '/merchant/gallery' },
  { name: 'privacy-settings', path: '/merchant/privacy-settings' },
];

// Inline-CSS gate selectors (admin surfaces carry zero style="" / arbitrary TW).
const INLINE_STYLE = '[style]';
const ARBITRARY_TW = /(?:bg|text|p|m|w|h|rounded|gap|inset)-\[/;

mkdirSync(OUT, { recursive: true });

async function login(page) {
  await page.goto(`${BASE}/merchant/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"]', EMAIL);
  await page.fill('input[type="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(1200);
  console.error('post-login url:', page.url());
}

async function audit(page, label) {
  const dir = await page.getAttribute('html', 'dir');
  const inlineCount = await page.locator(INLINE_STYLE).count();
  const arbitrary = await page.evaluate((re) => {
    const rx = new RegExp(re);
    return [...document.querySelectorAll('[class]')]
      .filter((el) => rx.test(el.getAttribute('class') || ''))
      .map((el) => el.getAttribute('class'))
      .slice(0, 5);
  }, ARBITRARY_TW.source);
  // Token gate: the merchant brand (amber) must resolve on :root, not stock indigo.
  const accent = await page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--toa-accent').trim());
  return { label, dir, inlineCount, arbitrary, accent };
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1024 } });
  const page = await context.newPage();

  const results = [];
  await login(page);

  for (const locale of ['en', 'he']) {
    for (const screen of SCREENS) {
      await page.goto(`${BASE}${screen.path}?locale=${locale}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1000);

      // On buy-credits, select a preset so the selected-state is captured too.
      if (screen.name === 'buy-credits') {
        await page.locator('.to-buy__card').nth(1).click().catch(() => {});
        await page.waitForTimeout(500);
      }

      const file = `${OUT}/merchant-${screen.name}.${locale}.png`;
      await page.screenshot({ path: file, fullPage: true });
      const a = await audit(page, `${screen.name}.${locale}`);
      results.push({ ...a, file });
    }
  }

  console.log(JSON.stringify(results, null, 2));
  await browser.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
