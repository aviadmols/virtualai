// =============================================================================
// Tabuzzco redesign verification shots — merchant + platform, EN + HE.
// One-off harness for the visual-quality pass: logs in to each panel, captures
// the required screens in both locales, and runs the inline-CSS gate on each.
// Run:  node tests/visual/redesign-shots.mjs
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://127.0.0.1:8199';
const MERCHANT_EMAIL = 'owner-a@trayon.test';
const PLATFORM_EMAIL = 'admin@trayon.test';
const PASSWORD = 'password';
const OUT = 'tests/visual/screenshots';

// Merchant panel is shop-tenant-scoped: sub-paths need the {tenant} slug prefix,
// captured from the post-login redirect. {t} is substituted at runtime.
const MERCHANT_SCREENS = [
  { name: 'merchant-dashboard', path: '/merchant/{t}' },
  { name: 'merchant-sites', path: '/merchant/{t}/sites' },
  { name: 'merchant-buy-credits', path: '/merchant/{t}/buy-credits' },
  { name: 'merchant-privacy', path: '/merchant/{t}/privacy-settings' },
  { name: 'merchant-leads', path: '/merchant/{t}/end-users' },
];

const PLATFORM_SCREENS = [
  { name: 'platform-dashboard', path: '/platform' },
  { name: 'platform-accounts', path: '/platform/accounts' },
  { name: 'platform-ai-models', path: '/platform/ai-models' },
];

const INLINE_STYLE = '[style]';
const ARBITRARY_TW = /(?:bg|text|p|m|w|h|rounded|gap)-\[/;

mkdirSync(OUT, { recursive: true });

async function login(page, email) {
  const panel = email === PLATFORM_EMAIL ? 'platform' : 'merchant';
  await page.goto(`${BASE}/${panel}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(1400);
  const url = page.url();
  console.error(`[${email}] post-login url:`, url);
  // Merchant tenant slug lives in the redirect: /merchant/<slug>/...
  const m = url.match(/\/merchant\/([^/?#]+)/);
  return m ? m[1] : '';
}

async function audit(page) {
  const dir = await page.getAttribute('html', 'dir');
  // Inline style="" nodes NOT emitted by our own product templates. Filament core
  // sets a handful of style vars on its layout; we only flag ones inside our to-* comps.
  const ourInline = await page.evaluate(() =>
    [...document.querySelectorAll('[class*="to-"] [style], [class*="to-"][style]')].length);
  const arbitrary = await page.evaluate((re) => {
    const rx = new RegExp(re);
    return [...document.querySelectorAll('[class]')]
      .filter((el) => rx.test(el.getAttribute('class') || ''))
      .map((el) => el.getAttribute('class')).slice(0, 4);
  }, ARBITRARY_TW.source);
  const primary = await page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--toa-accent').trim());
  return { dir, ourInline, arbitrary, primary };
}

async function shoot(browser, screens, email, results) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1024 } });
  const page = await context.newPage();
  const tenant = await login(page, email);
  for (const locale of ['en', 'he']) {
    for (const s of screens) {
      const path = s.path.replace('{t}', tenant);
      await page.goto(`${BASE}${path}?locale=${locale}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1100);
      const file = `${OUT}/${s.name}.${locale}.png`;
      await page.screenshot({ path: file, fullPage: true });
      const a = await audit(page);
      results.push({ screen: `${s.name}.${locale}`, ...a, file });
      console.error(`  shot ${file}  dir=${a.dir} ourInline=${a.ourInline} accent=${a.primary}`);
    }
  }
  await context.close();
}

(async () => {
  const browser = await chromium.launch().catch(() => chromium.launch({ channel: 'chrome' }));
  const results = [];

  await shoot(browser, MERCHANT_SCREENS, MERCHANT_EMAIL, results);
  await shoot(browser, PLATFORM_SCREENS, PLATFORM_EMAIL, results);

  console.log(JSON.stringify(results, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
