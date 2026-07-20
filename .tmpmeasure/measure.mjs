import { build } from 'esbuild';
import { gzipSync } from 'node:zlib';
import { writeFileSync, readFileSync } from 'node:fs';

const R = 'C:/Users/user/Desktop/Projects/virtualAi/resources/widget';

async function measure(name, contents) {
  writeFileSync(R + '/src/__measure.js', contents);
  const r = await build({
    entryPoints: [R + '/src/__measure.js'],
    bundle: true, minify: true, format: 'iife', write: false,
    target: ['es2019'], loader: { '.css': 'text' }, legalComments: 'none',
  });
  const out = r.outputFiles[0].contents;
  console.log(name.padEnd(28), gzipSync(Buffer.from(out)).length, 'B gz /', out.length, 'B raw');
}

await measure('core (no club/modal)', `
import * as a from './constants.js';
import * as b from './dom.js';
import * as c from './state.js';
import * as d from './i18n.js';
import * as e from './api.js';
import * as f from './pdp.js';
import * as g from './shell.js';
import * as h from './mount.js';
import * as i from './button.js';
import * as j from './pending.js';
import * as k from './generation.js';
import * as l from './track.js';
window.__m = [a,b,c,d,e,f,g,h,i,j,k,l];
`);

await measure('club+banners+pricing', `
import * as a from './club.js';
import * as b from './banners.js';
import * as c from './pricing.js';
window.__m = [a,b,c];
`);

await measure('modal+cart+image', `
import * as a from './modal.js';
import * as b from './cart.js';
import * as c from './image.js';
window.__m = [a,b,c];
`);

await measure('css only', `
import css from '../styles/widget.css';
window.__m = css;
`);

await measure('i18n only', `
import * as a from './i18n.js';
window.__m = a;
`);

await measure('constants only', `
import * as a from './constants.js';
window.__m = a;
`);
