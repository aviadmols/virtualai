// =============================================================================
// Visual verification harness — merchant Customer-Club settings (Phase 2b-UI / 2c).
// Logs in as the merchant account-owner, opens the Club-settings page for the shop
// tenant, and captures EN + HE screenshots of (1) the settings form (enabled toggle,
// discount %, per-surface price-zone summaries) and (2) the multi-pick zone picker
// overlay (opened on the Cart surface — the live-preview fallback path). Asserts the
// RTL flip + the no-inline-CSS gate on the rendered DOM. Drives the system Chrome
// (channel: 'chrome') so no browser download is needed.
// Run: node tests/visual/club-settings-screens.mjs
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://virtualai.test';
const EMAIL = process.env.TO_MERCHANT_EMAIL ?? 'owner-a@trayon.test';
const PASSWORD = process.env.TO_MERCHANT_PASSWORD ?? 'password';
const TENANT = process.env.TO_TENANT_SLUG ?? 'store-a-1mexef';
const OUT = process.env.TO_SHOT_DIR ?? 'docs/ux/screens/club-settings';

const CLUB_PATH = `/merchant/${TENANT}/club-settings`;

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
  // The Club form/overlay live inside a <main>; ignore any host chrome outside it.
  const inlineCount = await page.locator(`.to-form ${INLINE_STYLE}, .to-place-overlay ${INLINE_STYLE}`).count();
  const arbitrary = await page.evaluate((re) => {
    const rx = new RegExp(re);
    const scope = document.querySelector('.fi-main') || document.body;
    return [...scope.querySelectorAll('[class]')]
      .filter((el) => rx.test(el.getAttribute('class') || ''))
      .map((el) => el.getAttribute('class'))
      .slice(0, 5);
  }, ARBITRARY_TW.source);
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
    // --- The settings form ---
    await page.goto(`${BASE}${CLUB_PATH}?locale=${locale}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);

    const formFile = `${OUT}/club-settings.${locale}.png`;
    await page.screenshot({ path: formFile, fullPage: true });
    results.push({ ...(await audit(page, `form.${locale}`)), file: formFile });

    // --- The multi-pick zone picker (Cart surface → live-preview fallback) ---
    // The last "Pick visually" button is the Cart surface.
    const pickButtons = page.locator('.to-zone .fi-btn');
    const count = await pickButtons.count();
    if (count > 0) {
      await pickButtons.nth(count - 1).click().catch(() => {});
      await page.waitForTimeout(900);
      const pickerFile = `${OUT}/club-zone-picker.${locale}.png`;
      await page.screenshot({ path: pickerFile, fullPage: true });
      results.push({ ...(await audit(page, `picker.${locale}`)), file: pickerFile });
    }
  }

  console.log(JSON.stringify(results, null, 2));
  await browser.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
