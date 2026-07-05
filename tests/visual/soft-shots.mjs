// =============================================================================
// Soft-redesign before/after shots — merchant + platform panels, EN + HE.
// Logs into each seeded panel, captures a representative screen in both locales,
// and (for cross-checking) captures both login screens too. The PHASE env var
// ('before' | 'after') is used purely for the output filename suffix.
// Run:  PHASE=before node soft-shots.mjs   (then rebuild tokens)  PHASE=after ...
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://127.0.0.1:8199';
const PHASE = process.env.PHASE ?? 'before';
const OUT = process.env.OUT_DIR ?? 'docs/ux/screens/redesign-soft';
const MERCHANT_EMAIL = 'owner-a@trayon.test';
const PLATFORM_EMAIL = 'admin@trayon.test';
const PASSWORD = 'password';

// {t} = merchant tenant slug (captured from the post-login redirect).
const MERCHANT_SCREENS = [
  { name: 'merchant-dashboard', path: '/merchant/{t}' },
  { name: 'merchant-buy-credits', path: '/merchant/{t}/buy-credits' },
];
const PLATFORM_SCREENS = [
  { name: 'platform-dashboard', path: '/platform' },
  { name: 'platform-accounts', path: '/platform/accounts' },
];

mkdirSync(OUT, { recursive: true });

async function login(page, email) {
  const panel = email === PLATFORM_EMAIL ? 'platform' : 'merchant';
  await page.goto(`${BASE}/${panel}/login`, { waitUntil: 'domcontentloaded' });
  // Login screen record (before/after) — proves the palette on an unauthenticated page too.
  await page.waitForTimeout(700);
  await page.screenshot({ path: `${OUT}/${panel}-login.en.${PHASE}.png`, fullPage: false });
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(1500);
  const url = page.url();
  console.error(`[${email}] post-login url:`, url);
  const m = url.match(/\/merchant\/([^/?#]+)/);
  return m ? m[1] : '';
}

async function accent(page) {
  return page.evaluate(() => {
    const cs = getComputedStyle(document.documentElement);
    return {
      accent: cs.getPropertyValue('--toa-accent').trim(),
      ink: cs.getPropertyValue('--to-ink').trim(),
      bg: cs.getPropertyValue('--toa-bg').trim(),
      btnRadius: cs.getPropertyValue('--toa-radius-button').trim(),
    };
  });
}

async function shoot(browser, screens, email) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1024 } });
  const page = await context.newPage();
  const tenant = await login(page, email);
  for (const locale of ['en', 'he']) {
    for (const s of screens) {
      const path = s.path.replace('{t}', tenant);
      await page.goto(`${BASE}${path}?locale=${locale}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1200);
      const file = `${OUT}/${s.name}.${locale}.${PHASE}.png`;
      await page.screenshot({ path: file, fullPage: false });
      const a = await accent(page);
      console.error(`  shot ${file}  dir_accent=${a.accent} ink=${a.ink} bg=${a.bg} btnR=${a.btnRadius}`);
    }
  }
  await context.close();
}

(async () => {
  const browser = await chromium.launch().catch(() => chromium.launch({ channel: 'chrome' }));
  await shoot(browser, MERCHANT_SCREENS, MERCHANT_EMAIL);
  await shoot(browser, PLATFORM_SCREENS, PLATFORM_EMAIL);
  await browser.close();
  console.error(`DONE phase=${PHASE}`);
})().catch((e) => { console.error(e); process.exit(1); });
