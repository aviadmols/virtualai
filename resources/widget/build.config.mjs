// === CONSTANTS ===
// The widget build. esbuild bundles resources/widget/src/loader.js to the STABLE static
// path public/widget/v1/widget.js (NOT hashed) so Caddy/FrankenPHP serves it directly and
// it BYPASSES the /widget/v1 API auth middleware (a <script src> can't send the site_key
// header). The embed snippet is a CLASSIC `<script async>` (no type=module), so the bundle
// is IIFE: a single self-contained file with no import/export. The widget is small enough
// that bundling the modal in keeps it well under budget while staying reliable on every
// host (dynamic-import + ESM would require type=module, which the locked snippet isn't).
// CSS is imported as text and inlined into the Shadow root. After build, the gzipped entry
// size is checked against size-budget.json — over budget exits non-zero.
//
// Run: node resources/widget/build.config.mjs   (wired into `npm run build`)

import { build } from 'esbuild';
import { readFileSync, mkdirSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');

const ENTRY = resolve(HERE, 'src', 'loader.js');
const OUT_DIR = resolve(ROOT, 'public', 'widget', 'v1');
const OUT_FILE = join(OUT_DIR, 'widget.js');
const BUDGET = JSON.parse(readFileSync(resolve(HERE, 'size-budget.json'), 'utf8'));

mkdirSync(OUT_DIR, { recursive: true });

await build({
  entryPoints: { widget: ENTRY },
  bundle: true,
  minify: true,
  format: 'iife', // classic <script async> — one self-contained file, no import/export
  outdir: OUT_DIR,
  target: ['es2019'],
  loader: { '.css': 'text' }, // CSS imported as a string, inlined into the Shadow root
  legalComments: 'none',
  sourcemap: false,
}).catch((err) => {
  console.error('widget build failed:', err);
  process.exit(1);
});

// --- Size-budget gate (mechanical): gzip the entry bundle ---
const entryGz = gzipSync(readFileSync(OUT_FILE)).length;
console.log(`widget.js gzipped: ${entryGz} bytes (budget ${BUDGET.maxGzipBytes})`);

void statSync(OUT_FILE); // assert the artifact exists

if (entryGz > BUDGET.maxGzipBytes) {
  console.error(`WIDGET ENTRY OVER BUDGET: ${entryGz} > ${BUDGET.maxGzipBytes} bytes`);
  process.exit(1);
}
process.exit(0);
