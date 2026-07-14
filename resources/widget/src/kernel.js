// === CONSTANTS ===
// The kernel: the ONE object a lazy chunk reads the core's live singletons from.
//
// Each chunk is its own IIFE bundle (a classic <script src> — see TS-BUILD-005), so anything it
// `import`ed for itself would be a SECOND copy: a second `state`, a second i18n table, a second
// api client with no site_key. That is a whole class of impossible-to-see bugs. So the chunks
// import only STATELESS modules (constants, dom) directly and take every stateful singleton
// from here, by reference.
//
// This lives under the single namespaced global the widget already owns (window.__TrayOn) — it
// adds no new host-window pollution. It holds no secret: the site_key it closes over is public,
// and no cost/credit/model value ever passes through it.

import { NAMESPACE, KERNEL_KEY } from './constants.js';
import { state, newIntent } from './state.js';
import * as i18n from './i18n.js';
import * as api from './api.js';
import * as shell from './shell.js';
import * as panel from './panel.js';
import * as button from './button.js';
import * as hud from './hud.js';
import * as pending from './pending.js';
import * as track from './track.js';
import * as gen from './generation.js';
import * as chunks from './chunks.js';

/** Publish the kernel. Called once at boot, BEFORE any chunk can be fetched. */
export function publish(extra = {}) {
  const ns = (window[NAMESPACE] = window[NAMESPACE] || {});
  ns[KERNEL_KEY] = {
    state,
    newIntent,
    i18n,
    api,
    shell,
    panel,
    button,
    hud,
    pending,
    track,
    gen,
    chunks,
    ...extra,
  };
}
