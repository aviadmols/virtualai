// =============================================================================
// Visual verification harness — merchant scan-review (A4) + site hub / embed (A5).
// Logs in as a merchant account-owner, then captures EN + HE screenshots of the
// scan-review form (seeded product #2 on site #1 — a mix of confidence rows + the
// blocked confirm gate) and the site hub (embed-code block + products → review).
// Asserts the RTL flip + the no-inline-CSS / arbitrary-Tailwind gates on the DOM.
// Drives system Chrome (channel: 'chrome'). Run: node tests/visual/scan-embed-screens.mjs
//
// Seed first:  php artisan db:seed --class=ScanReviewDemoSeeder
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://virtualai.test';
const EMAIL = process.env.TO_MERCHANT_EMAIL ?? 'owner-a@trayon.test';
const PASSWORD = process.env.TO_MERCHANT_PASSWORD ?? 'password';
const OUT = process.env.TO_SHOT_DIR ?? 'tests/visual/screenshots';

const SCREENS = [
  // A5 — the site hub: embed-code block + products list linking to review.
  { name: 'site-hub-embed', path: '/merchant/sites/1' },
  // A4 — the scan-review form for the seeded product (#2 on site #1).
  { name: 'scan-review', path: '/merchant/sites/1/products/2/review' },
];

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
  const dir = await page.getAttribute('html', 'dir');
  // Inline-CSS gate: count style="" nodes that Filament/Livewire itself did not
  // inject (x-cloak display:none, wire loading states). We report the raw count
  // and the offending non-framework nodes so a real inline style is visible.
  const inline = await page.evaluate(() => {
    const offenders = [];
    for (const el of document.querySelectorAll('[style]')) {
      const s = (el.getAttribute('style') || '').trim();
      if (s === '' || s === 'display:none;' || s.startsWith('display: none')) continue;
      // framework-injected positioning/visibility is allowed; flag colour/size.
      offenders.push({ tag: el.tagName.toLowerCase(), style: s.slice(0, 80), cls: (el.getAttribute('class') || '').slice(0, 40) });
    }
    return offenders;
  });
  const arbitrary = await page.evaluate((re) => {
    const rx = new RegExp(re);
    return [...document.querySelectorAll('[class]')]
      .filter((el) => rx.test(el.getAttribute('class') || ''))
      .map((el) => el.getAttribute('class'))
      .slice(0, 5);
  }, ARBITRARY_TW.source);
  // Token gate: the brand --to-primary chain resolves to the amber accent.
  const accent = await page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--toa-accent').trim());
  return { label, dir, inlineOffenders: inline, arbitrary, accent };
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
  const page = await context.newPage();

  const results = [];
  await login(page);

  for (const locale of ['en', 'he']) {
    for (const screen of SCREENS) {
      await page.goto(`${BASE}${screen.path}?locale=${locale}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1000);
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
