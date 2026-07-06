// === CONSTANTS ===
// Cross-page / cross-tab "your try-on is ready" persistence. The shopper starts a try-on,
// closes the popup, and keeps browsing (other pages, other tabs); when the image finishes
// they get the on-page popup on WHATEVER page/tab they're on, and clicking it opens the
// result. We persist ONLY handles (generationId, anonToken, productId, startedAt) — plus, on
// completion, the EXPIRING signed result_url — under a SITE-SCOPED localStorage key, with a
// TTL. A BroadcastChannel (also site-scoped) syncs done/viewed/dismissed across open tabs so
// there are no duplicate/zombie notifications and no redundant polling; the `storage` event
// is the fallback when BroadcastChannel is unavailable. Never blocks page render (lazy/async)
// and never throws on a page with no product.
//
// Auth/tenancy unchanged: the resume poll re-uses the same signed status endpoint with the
// public site_key + Origin + the persisted anonToken. No secret, no PII beyond client-side.

import {
  STORAGE_PENDING_PREFIX,
  BROADCAST_PREFIX,
  PENDING_TTL_MS,
  PENDING_PHASE,
  PENDING_MSG,
} from './constants.js';
import { warn } from './dom.js';

let siteKey = '';
let storageKey = '';
let channel = null; // BroadcastChannel | null
let storageListener = null; // the `storage`-event fallback listener
let onRemoteMessage = null; // (type) => void — set by the resumer to react to other tabs

/** Configure the persistence layer with the site_key (scopes the key + the channel). */
export function configure(key) {
  siteKey = key || '';
  storageKey = STORAGE_PENDING_PREFIX + siteKey;
}

// ---------------------------------------------------------------------------
// localStorage — read/write the single site-scoped pending entry (fail-soft).
// ---------------------------------------------------------------------------

/** The persisted pending entry, or null (also null when expired past its TTL). */
export function read() {
  try {
    const raw = localStorage.getItem(storageKey);
    if (!raw) return null;
    const entry = JSON.parse(raw);
    if (!entry || !entry.generationId) return null;
    if (isExpired(entry)) {
      clear();
      return null;
    }
    return entry;
  } catch {
    return null;
  }
}

/** A pending entry is FRESH to resume: it exists, is un-expired, and has not been viewed. */
export function readFresh() {
  const entry = read();
  if (!entry) return null;
  if (entry.phase === PENDING_PHASE.viewed) return null;
  return entry;
}

function isExpired(entry) {
  const started = Number(entry.startedAt) || 0;
  return started > 0 && Date.now() - started > PENDING_TTL_MS;
}

function write(entry) {
  try {
    localStorage.setItem(storageKey, JSON.stringify(entry));
  } catch {
    // Private mode / storage disabled — the in-memory flow still works on this page.
  }
}

/** Persist a freshly-created generation (called from generation.js once the id is known). */
export function start({ generationId, anonToken, productId }) {
  if (!generationId) return;
  write({
    generationId,
    anonToken: anonToken || null,
    productId: productId || null,
    startedAt: Date.now(),
    phase: PENDING_PHASE.active,
  });
}

/** Mark completion + persist the (expiring) signed result_url; broadcast so other tabs notify. */
export function markDone(generationId, resultUrl) {
  const entry = read() || {};
  if (entry.generationId && String(entry.generationId) !== String(generationId)) return;
  write({
    ...entry,
    generationId,
    resultUrl: resultUrl || null,
    phase: PENDING_PHASE.done,
    finishedAt: Date.now(),
  });
  broadcast(PENDING_MSG.done);
}

/** Mark a failed completion; broadcast so other tabs surface the "didn't finish" popup. */
export function markFailed(generationId) {
  const entry = read() || {};
  if (entry.generationId && String(entry.generationId) !== String(generationId)) return;
  write({ ...entry, generationId, phase: PENDING_PHASE.failed, finishedAt: Date.now() });
  broadcast(PENDING_MSG.failed);
}

/** The shopper viewed the result (clicked the popup): keep the entry but mark it viewed so a
 *  fresh page load does NOT re-notify. Broadcast so other tabs clear their popup. */
export function markViewed() {
  const entry = read();
  if (entry) write({ ...entry, phase: PENDING_PHASE.viewed });
  broadcast(PENDING_MSG.viewed);
}

/** Clear the entry entirely (explicit dismiss / TTL expiry) and tell other tabs to clear too. */
export function dismiss() {
  clear();
  broadcast(PENDING_MSG.dismissed);
}

/** Remove the persisted entry (no broadcast). */
export function clear() {
  try {
    localStorage.removeItem(storageKey);
  } catch {
    /* fail-soft */
  }
}

// ---------------------------------------------------------------------------
// Cross-tab channel — BroadcastChannel with a `storage`-event fallback.
// ---------------------------------------------------------------------------

/** Open the cross-tab channel. `handler(type)` is called for messages from OTHER tabs. */
export function connect(handler) {
  onRemoteMessage = typeof handler === 'function' ? handler : null;

  if (typeof BroadcastChannel === 'function') {
    try {
      channel = new BroadcastChannel(BROADCAST_PREFIX + siteKey);
      channel.onmessage = (e) => {
        const type = e && e.data && e.data.type;
        if (type && onRemoteMessage) onRemoteMessage(type);
      };
      return;
    } catch {
      channel = null; // fall through to the storage-event fallback
    }
  }

  // Fallback: another tab's write() fires a `storage` event here. We reflect it into the same
  // handler; the tab that wrote also fires broadcast() which sets a short-lived signal key.
  storageListener = (e) => {
    if (e.key !== signalKey()) return;
    const type = e.newValue ? parseSignal(e.newValue) : null;
    if (type && onRemoteMessage) onRemoteMessage(type);
  };
  try {
    window.addEventListener('storage', storageListener);
  } catch {
    /* fail-soft */
  }
}

/** Send a message to other tabs (BroadcastChannel, else a storage-event signal). */
function broadcast(type) {
  if (channel) {
    try {
      channel.postMessage({ type, at: Date.now() });
      return;
    } catch {
      /* fall through to the storage signal */
    }
  }
  // Storage-event fallback: writing a value with a nonce fires `storage` in OTHER tabs.
  try {
    localStorage.setItem(signalKey(), type + '|' + Date.now());
  } catch {
    /* fail-soft */
  }
}

function signalKey() {
  return storageKey + '.signal';
}

function parseSignal(value) {
  const type = String(value).split('|')[0];
  return Object.values(PENDING_MSG).includes(type) ? type : null;
}

/** Close the channel + remove the fallback listener (teardown). */
export function disconnect() {
  if (channel) {
    try {
      channel.close();
    } catch {
      warn('failed to close the pending channel');
    }
    channel = null;
  }
  if (storageListener) {
    try {
      window.removeEventListener('storage', storageListener);
    } catch {
      /* fail-soft */
    }
    storageListener = null;
  }
  onRemoteMessage = null;
}
