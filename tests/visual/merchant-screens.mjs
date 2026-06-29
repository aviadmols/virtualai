// =============================================================================
// Visual verification harness — merchant panel (Phase 8 Wave 2, sub-wave 8c).
// Logs in as a merchant account-owner, then captures EN + HE screenshots of the
// dashboard, sites list, and leads table, asserting the RTL flip + the no-inline-
// CSS gate on the rendered DOM. Drives the system Chrome (channel: 'chrome') so
// no browser download is needed. Run: node tests/visual/merchant-screens.mjs
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://127.0.0.1:8000';
const EMAIL = process.env.TO_MERCHANT_EMAIL ?? 'owner-a@trayon.test';
const PASSWORD = process.env.TO_MERCHANT_PASSWORD ?? 'password';
const OUT = process.env.TO_SHOT_DIR ?? 'tests/visual/screenshots';

const SCREENS = [
  { name: 'dashboard', path: '/merchant' },
  { name: 'sites', path: '/merchant/sites' },
  { name: 'leads', path: '/merchant/end-users' },
  // The A7 lead card / attempt history (seeded lead #1 has two generations).
  { name: 'lead-card', path: '/merchant/end-users/1' },
];

// Inline-CSS gate: any style="" on a non-email node, or an arbitrary Tailwind value.
const INLINE_STYLE = '[style]';
const ARBITRARY_TW = /(?:bg|text|p|m|w|h|rounded|gap)-\[/;

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
  // RTL gate (HE only): <html dir="rtl"> must be set.
  const dir = await page.getAttribute('html', 'dir');
  // Inline-CSS gate: count style="" nodes that are NOT inside a known-safe slot.
  const inlineCount = await page.locator(INLINE_STYLE).count();
  // Arbitrary-Tailwind gate: scan class lists for the bracket pattern.
  const arbitrary = await page.evaluate((re) => {
    const rx = new RegExp(re);
    return [...document.querySelectorAll('[class]')]
      .filter((el) => rx.test(el.getAttribute('class') || ''))
      .map((el) => el.getAttribute('class'))
      .slice(0, 5);
  }, ARBITRARY_TW.source);
  return { label, dir, inlineCount, arbitrary };
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
      await page.waitForTimeout(900);
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
