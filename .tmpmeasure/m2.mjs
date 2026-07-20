import { build } from 'esbuild';
import { gzipSync } from 'node:zlib';
import { writeFileSync } from 'node:fs';
const R = 'C:/Users/user/Desktop/Projects/virtualAi/resources/widget';
async function measure(name, contents) {
  writeFileSync(R + '/src/__measure.js', contents);
  const r = await build({ entryPoints: [R + '/src/__measure.js'], bundle: true, minify: true, format: 'iife', write: false, target: ['es2019'], loader: { '.css': 'text' }, legalComments: 'none' });
  const out = r.outputFiles[0].contents;
  console.log(name.padEnd(34), gzipSync(Buffer.from(out)).length, 'B gz');
}
const CORE = `
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
`;
await measure('core + club/banners/pricing', CORE + `
import * as m from './club.js';
import * as n from './banners.js';
import * as o from './pricing.js';
window.__m=[a,b,c,d,e,f,g,h,i,j,k,l,m,n,o];`);
await measure('core + modal/cart/image', CORE + `
import * as m from './modal.js';
import * as n from './cart.js';
import * as o from './image.js';
window.__m=[a,b,c,d,e,f,g,h,i,j,k,l,m,n,o];`);
