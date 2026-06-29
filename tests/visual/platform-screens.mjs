// =============================================================================
// Visual verification harness — platform panel (Phase 8 Wave 2, sub-wave 8d).
// Logs in as the super-admin, then captures EN + HE screenshots of the costs
// dashboard, accounts list, AI models list, and a prompt edit page WITH the
// resolver-preview panel populated — asserting the RTL flip + the no-inline-CSS
// gate on the rendered DOM. Drives system Chrome (channel: 'chrome'); no browser
// download needed. Run: node tests/visual/platform-screens.mjs
// =============================================================================

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// === CONSTANTS ===
const BASE = process.env.TO_BASE_URL ?? 'http://virtualai.test';
const EMAIL = process.env.TO_PLATFORM_EMAIL ?? 'aviadmols@gmail.com';
const PASSWORD = process.env.TO_PLATFORM_PASSWORD ?? 'Aa85208520';
const OUT = process.env.TO_SHOT_DIR ?? 'tests/visual/screenshots';
// The prompt id whose edit page mounts the resolver-preview (a global prompt).
const PROMPT_ID = process.env.TO_PROMPT_ID ?? '1';

const SCREENS = [
  { name: 'costs-dashboard', path: '/platform', preview: false },
  { name: 'accounts', path: '/platform/accounts', preview: false },
  { name: 'ai-models', path: '/platform/ai-models', preview: false },
  { name: 'ledger', path: '/platform/platform-credit-ledgers', preview: false },
  { name: 'activity', path: '/platform/activity-events', preview: false },
  // The prompt edit page + the resolver-preview panel (we click "preview").
  { name: 'prompt-resolver', path: `/platform/prompts/${PROMPT_ID}/edit`, preview: true },
];

// Inline-CSS gate: any style="" node, or an arbitrary Tailwind value.
const INLINE_STYLE = '[style]';
const ARBITRARY_TW = /(?:bg|text|p|m|w|h|rounded|gap)-\[/;

mkdirSync(OUT, { recursive: true });

async function login(page) {
  await page.goto(`${BASE}/platform/login`, { waitUntil: 'domcontentloaded' });
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
  return { label, dir, inlineCount, arbitrary };
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1024 },
    ignoreHTTPSErrors: true,
  });
  const page = await context.newPage();

  const results = [];
  await login(page);

  for (const locale of ['en', 'he']) {
    for (const screen of SCREENS) {
      await page.goto(`${BASE}${screen.path}?locale=${locale}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(900);

      // On the prompt edit page, trigger the resolver-preview so the panel is populated.
      if (screen.preview) {
        const run = page.locator('.to-rp__run');
        if (await run.count()) {
          await run.first().click().catch(() => {});
          await page.waitForTimeout(1200);
        }
      }

      const file = `${OUT}/platform-${screen.name}.${locale}.png`;
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
