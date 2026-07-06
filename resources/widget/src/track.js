// === CONSTANTS ===
// Lean behavioral tracking: ONE page_view per page load + MEANINGFUL interactions only
// (product view, variant change, Tray-On open, add-to-cart) — never arbitrary DOM clicks.
// Everything here rides the loader's EXISTING idle path (init is called from run(), which is
// already scheduled via requestIdleCallback) so it adds ZERO synchronous work on load and
// never blocks LCP/interaction. Events are batched into a tiny in-memory queue and flushed
// fire-and-forget on idle / pagehide (sendBeacon or fetch keepalive). Nothing here mutates
// host DOM, so it can never cause a layout shift (CLS). It never throws into the host page.

import {
  TRACK_KIND,
  TRACK_INTERACTION,
  TRACK_MAX_QUEUE,
  TRACK_FLUSH_IDLE_TIMEOUT_MS,
  TRACK_LABEL_MAX,
  EVENTS,
} from './constants.js';
import { state } from './state.js';
import { onIdle, warn } from './dom.js';
import * as api from './api.js';
import * as shell from './shell.js';

// Module state: the queue, an idempotency guard (never double-count a page_view / product_view
// if the loader ever re-runs), whether this site opted out, and the bound teardown listeners.
let queue = [];
let enabled = false;
let flushScheduled = false;
let sawPageView = false;
let sawProductView = false;
let boundVariantHandler = null;
let boundPageHide = null;

/**
 * Initialise tracking on the idle path. `options.trackingEnabled === false` (a per-site
 * bootstrap/privacy flag) disables it entirely; anything else (including absent) => on, per
 * the backend contract. `hasProduct` records the product-view interaction on a PDP.
 */
export function init({ trackingEnabled, hasProduct } = {}) {
  if (trackingEnabled === false) {
    enabled = false;
    return;
  }
  enabled = true;

  recordPageView();
  if (hasProduct) recordProductView();

  bindSignals();
  bindPageHide();
}

/** ONE page_view per load (guarded so a re-boot can't double-count). */
function recordPageView() {
  if (sawPageView) return;
  sawPageView = true;
  enqueue({ kind: TRACK_KIND.pageView });
}

/** The shopper landed on a confirmed product page — a meaningful "viewed this product" signal. */
function recordProductView() {
  if (sawProductView) return;
  sawProductView = true;
  interaction(TRACK_INTERACTION.productView, state.product?.name);
}

/** Public: the Tray-On button/flow was opened (called from the modal-open hook). */
export function trackOpen() {
  interaction(TRACK_INTERACTION.tryonOpen, state.product?.name);
}

/** Public: the shopper added the tried-on variant to cart (called from the cart funnel hook). */
export function trackAddToCart(variant) {
  interaction(TRACK_INTERACTION.addToCart, variant?.label || variant?.sku);
}

/** Subscribe to the EXISTING variant-changed custom event (dispatched on the overlay mount). */
function bindSignals() {
  const mount = shell.getOverlayMount();
  if (!mount) return;
  boundVariantHandler = (e) => {
    const variant = e && e.detail;
    interaction(TRACK_INTERACTION.variantChange, variant?.label || variant?.sku);
  };
  mount.addEventListener(EVENTS.variantChanged, boundVariantHandler);
}

/** Flush on pagehide so nothing queued is lost when the shopper navigates away. */
function bindPageHide() {
  boundPageHide = () => flush(true);
  // pagehide fires on bfcache navigations too; visibilitychange->hidden covers mobile tab-switch.
  window.addEventListener('pagehide', boundPageHide);
  document.addEventListener('visibilitychange', onVisibility, false);
}

function onVisibility() {
  if (document.visibilityState === 'hidden') flush(true);
}

/** Push a curated event onto the queue and schedule an idle flush. Non-meaningful => never here. */
function interaction(type, label) {
  enqueue({
    kind: TRACK_KIND.interaction,
    interaction: cleanInteraction(type, label),
  });
}

function enqueue(partial) {
  if (!enabled) return;

  queue.push({
    kind: partial.kind,
    at: new Date().toISOString(),
    path: safePath(),
    referrer_host: referrerHost(),
    ...(partial.interaction ? { interaction: partial.interaction } : {}),
  });

  // A long browsing session can't grow the queue unbounded — flush immediately when it fills.
  if (queue.length >= TRACK_MAX_QUEUE) {
    flush(false);
    return;
  }
  scheduleFlush();
}

/** Batch: coalesce rapid signals into one request on the next idle tick. */
function scheduleFlush() {
  if (flushScheduled) return;
  flushScheduled = true;
  onIdle(() => {
    flushScheduled = false;
    flush(false);
  }, TRACK_FLUSH_IDLE_TIMEOUT_MS);
}

/**
 * Send the queued batch and clear it. Fire-and-forget: the response is ignored and this NEVER
 * throws into the host page. `unloading` prefers a beacon so the batch survives navigation.
 */
function flush(unloading) {
  if (!enabled || queue.length === 0) return;
  const batch = queue;
  queue = [];
  try {
    api.recordEvents({ anonToken: state.anonToken, events: batch }, unloading === true);
  } catch {
    warn('failed to record events');
  }
}

/** The current path only (never query/hash — no accidental PII in a URL param). */
function safePath() {
  try {
    return location.pathname || '/';
  } catch {
    return '/';
  }
}

/** The referrer HOST only (not the full URL) — a coarse, low-PII signal. */
function referrerHost() {
  try {
    if (!document.referrer) return undefined;
    const host = new URL(document.referrer).host;
    return host || undefined;
  } catch {
    return undefined;
  }
}

/** Build the interaction payload with a trimmed, optional label. */
function cleanInteraction(type, label) {
  const out = { type };
  if (label != null && label !== '') {
    out.label = String(label).slice(0, TRACK_LABEL_MAX);
  }
  return out;
}

/** Teardown (SPA navigation away): flush anything pending, drop listeners. No leaks. */
export function teardown() {
  flush(false);
  const mount = shell.getOverlayMount();
  if (mount && boundVariantHandler) mount.removeEventListener(EVENTS.variantChanged, boundVariantHandler);
  if (boundPageHide) window.removeEventListener('pagehide', boundPageHide);
  document.removeEventListener('visibilitychange', onVisibility, false);
  boundVariantHandler = null;
  boundPageHide = null;
  enabled = false;
}
