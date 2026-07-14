// === CONSTANTS ===
// The lazy-chunk loader. The embed snippet is a CLASSIC <script async> (TS-BUILD-005), so the
// bundle is an IIFE and there is no dynamic import() to lean on. Each chunk is therefore its
// own self-contained IIFE fetched with a <script src> and registered back onto the one
// namespaced global: window.__TrayOn.__ready(name, exports).
//
// The CORE never blocks on a chunk. A chunk that never arrives degrades to a typed, retryable
// state (§6.3) — never a half-drawn modal, never an exception thrown into the merchant's page.

import { NAMESPACE, CHUNK_FILES, CHUNK_READY_FN, CHUNK_TIMEOUT_MS } from './constants.js';
import { warn } from './dom.js';

const loaded = {}; // name -> the chunk's exported API (once it has registered)
const inflight = {}; // name -> the Promise of its load
let base = ''; // the absolute directory that served widget.js

/** Wire the registration hook the chunks call. Cheap + synchronous — no work, just a function. */
export function configure(assetBase) {
  base = assetBase || '';
  const ns = (window[NAMESPACE] = window[NAMESPACE] || {});
  ns[CHUNK_READY_FN] = onReady;
}

/** A chunk finished evaluating and handed us its API. */
function onReady(name, exports) {
  loaded[name] = exports || {};
  const waiter = inflight[name];
  if (waiter && waiter.resolve) waiter.resolve(loaded[name]);
}

/** The chunk's API if it is already here (a warm click opens the modal with zero latency). */
export function ready(name) {
  return loaded[name] || null;
}

/**
 * Fetch a chunk (idempotent). Resolves with its API, rejects on a network error or after
 * CHUNK_TIMEOUT_MS. A rejected load is forgotten so a retry genuinely retries.
 */
export function load(name) {
  if (loaded[name]) return Promise.resolve(loaded[name]);
  if (inflight[name]) return inflight[name].promise;

  const entry = {};
  entry.promise = new Promise((resolve, reject) => {
    entry.resolve = (api) => {
      clearTimeout(entry.timer);
      resolve(api);
    };

    const fail = (reason) => {
      clearTimeout(entry.timer);
      if (loaded[name]) return; // it landed in the same tick — not a failure
      delete inflight[name];
      if (script.parentNode) script.parentNode.removeChild(script);
      warn('chunk failed: ' + name + ' (' + reason + ')');
      reject(new Error(reason));
    };

    const script = document.createElement('script');
    script.src = base + CHUNK_FILES[name];
    script.async = true;
    script.onerror = () => fail('network');
    entry.timer = setTimeout(() => fail('timeout'), CHUNK_TIMEOUT_MS);

    document.head.appendChild(script);
  });

  inflight[name] = entry;
  return entry.promise;
}

/** Warm a chunk on a HINT (pointerenter / focus / touchstart), never on a promise. */
export function prefetch(name) {
  if (loaded[name] || inflight[name]) return;
  load(name).catch(() => {
    /* a prefetch that fails is a no-op; the real click retries and shows the typed state */
  });
}
