// === CONSTANTS ===
// The widget build. esbuild bundles THREE self-contained IIFEs to the STABLE static directory
// public/widget/v1/ (never hashed), so Caddy/FrankenPHP serves them straight from public/ and they
// BYPASS the /widget/v1 API auth middleware (a <script src> cannot send the site_key header):
//
//   widget.js        the CORE, loaded on every page view by the merchant's embed snippet.
//                    Loader, PDP detect, trigger, the floating HUD, the modal skeleton, resume.
//   widget.modal.js  the MODAL chunk, fetched on the first real interaction (a tap on the trigger
//                    or on the HUD): the whole flow, the cart, share, and the self-hosted webfont.
//   widget.club.js   the CLUB chunk, fetched on IDLE and only on a site that has a club/banners.
//
// Why three IIFEs and not one ESM build with splitting: the embed snippet is a CLASSIC
// <script async> with no type=module (TS-BUILD-005), so an esbuild `splitting` chunk — which is
// loaded with a dynamic import() — would simply never execute on the host page. Each chunk is
// therefore fetched with a plain <script src> and registers itself on window.__TrayOn.__ready().
//
// CSS is imported as text and inlined into the shadow roots. Fonts are COPIED, never inlined:
// base64 in the JS would put ~45 KB of woff2 inside the byte budget for a shopper who may never
// open the modal.
//
// The size gate is mechanical and fails the BUILD, not the review: the core is checked against
// maxGzipBytes and EVERY chunk against maxLazyGzipBytes. Widget weight is a feature — it runs on
// a merchant's PDP and their LCP/CLS/SEO pay for it.
//
// Run: node resources/widget/build.config.mjs   (wired into `npm run build`)

import { build } from 'esbuild';
import { readFileSync, mkdirSync, cpSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');

const OUT_DIR = resolve(ROOT, 'public', 'widget', 'v1');
const FONT_SRC = resolve(HERE, 'fonts');
const FONT_OUT = join(OUT_DIR, 'fonts');
const BUDGET = JSON.parse(readFileSync(resolve(HERE, 'size-budget.json'), 'utf8'));

// entry name -> [ source, the budget key it is measured against ]
const ENTRIES = {
  widget: [resolve(HERE, 'src', 'loader.js'), 'maxGzipBytes'],
  'widget.modal': [resolve(HERE, 'src', 'lazy', 'modal-entry.js'), 'maxLazyGzipBytes'],
  'widget.club': [resolve(HERE, 'src', 'lazy', 'club-entry.js'), 'maxLazyGzipBytes'],
};

mkdirSync(OUT_DIR, { recursive: true });

await build({
  entryPoints: Object.fromEntries(Object.entries(ENTRIES).map(([name, [entry]]) => [name, entry])),
  bundle: true,
  minify: true,
  format: 'iife', // classic <script async>/<script src> — self-contained, no import/export
  outdir: OUT_DIR,
  target: ['es2019'],
  loader: { '.css': 'text' }, // CSS imported as a string, inlined into the shadow roots
  legalComments: 'none',
  sourcemap: false,
}).catch((err) => {
  console.error('widget build failed:', err);
  process.exit(1);
});

// The self-hosted, subset webfont. Never Google Fonts: a third-party request on the merchant's
// page is not ours to make. It sits beside the bundles and is resolved at runtime against the
// script's own origin (a <style> in a shadow root would otherwise resolve it against the host doc).
cpSync(FONT_SRC, FONT_OUT, { recursive: true });

// --- The size-budget gate (mechanical) ---
let over = false;

for (const [name, [, budgetKey]] of Object.entries(ENTRIES)) {
  const file = join(OUT_DIR, name + '.js');
  void statSync(file); // assert the artifact exists
  const gz = gzipSync(readFileSync(file)).length;
  const max = BUDGET[budgetKey];
  const label = budgetKey === 'maxGzipBytes' ? 'core' : 'lazy';

  if (gz > max) {
    console.error(`WIDGET OVER BUDGET (${label}): ${name}.js ${gz} > ${max} bytes gzipped`);
    over = true;
  } else {
    console.log(`${name}.js gzipped: ${gz} bytes (${label} budget ${max})`);
  }
}

process.exit(over ? 1 : 0);
