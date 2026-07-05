// === CONSTANTS ===
// The mount engine: a MutationObserver keeps the button injected under add-to-cart through
// SPA re-renders (route change, quick-view, swatch click) — re-injected if missing, NEVER
// duplicated (a sentinel guards every inject). On every DOM change we also re-read the
// selected variant so the modal/result/cart reference the SAME variant the shopper sees.
// Teardown disconnects the observer, removes the button + shell, and clears listeners.

import { MOUNT_SENTINEL_ATTR, APPEARANCE, PLACEMENT, OBSERVER_DEBOUNCE_MS, EVENTS } from './constants.js';
import { state } from './state.js';
import { debounce, safeQuery } from './dom.js';
import { findAddToCart, readSelectedVariant } from './pdp.js';
import * as button from './button.js';
import * as shell from './shell.js';

let observer = null;
let wrapper = null; // the host-DOM button wrapper (carries the sentinel)
let boundOpen = null; // the modal-open callback (lazy-imports the modal)
let hostListeners = []; // host variation-change listeners we must remove on teardown

/** Start the mount engine. `openModal` is called when the button is clicked. */
export function start(openModal) {
  boundOpen = openModal;
  button.onClick(() => boundOpen());

  inject();
  syncVariant();
  bindHostVariationSignals();

  observer = new MutationObserver(debounce(onMutate, OBSERVER_DEBOUNCE_MS));
  observer.observe(document.body, { childList: true, subtree: true });
}

function onMutate() {
  // The bootstrap already confirmed this is a PDP (product != null). The observer only
  // re-injects if the theme re-rendered the anchor away, and keeps the variant fresh.
  inject();
  syncVariant();
}

/** Idempotent injection: re-inject if missing, never duplicate (sentinel-guarded). */
function inject() {
  const selectors = state.config.selectors;
  const placement = state.config.appearance[APPEARANCE.placement];

  // Already mounted with a LIVE button? Do nothing. A theme that re-renders via
  // cloneNode() copies the sentinel attribute but NOT the shadow root (shadow roots are
  // not cloned), leaving a dead shell — detect + remove those so we re-inject a working
  // button (TS-WIDGET scar: a cloned sentinel must not block a real mount).
  const existing = document.querySelectorAll('[' + MOUNT_SENTINEL_ATTR + ']');
  let liveFound = false;
  for (const node of existing) {
    const alive = node.shadowRoot && node.shadowRoot.querySelector('.ton-button');
    if (alive && !liveFound) {
      liveFound = true; // keep the first live one
    } else if (node.parentNode) {
      node.parentNode.removeChild(node); // dead clone or a duplicate -> remove
    }
  }
  if (liveFound) return;

  const appearance = state.config.appearance;
  const anchor = findAddToCart(selectors);

  // Custom placement carries the merchant-picked anchor + position; the add-to-cart anchor is
  // its runtime fallback (place() uses it if the custom selector no longer resolves).
  const custom = placement === PLACEMENT.custom
    ? { selector: appearance[APPEARANCE.customAnchor], position: appearance[APPEARANCE.customPosition] }
    : null;
  const customAnchor = custom ? safeQuery(custom.selector) : null;

  // Fixed placements need no anchor. Inline placements need the add-to-cart anchor. Custom needs
  // EITHER its own anchor OR the add-to-cart fallback — else the theme isn't ready; retry later.
  const isFixed = placement === PLACEMENT.fixedBR || placement === PLACEMENT.fixedBL;
  if (! isFixed && ! anchor && ! customAnchor) return;

  wrapper = button.build(appearance);
  button.place(wrapper, anchor, placement, custom);
}

/** Re-read the selected variant; emit a change event when it differs. */
function syncVariant() {
  const variant = readSelectedVariant(state.config.selectors, state.product);
  if (!variant) return;

  if (!state.variant || state.variant.key !== variant.key) {
    state.variant = variant;
    dispatchVariantChanged(variant);
  }
}

function dispatchVariantChanged(variant) {
  const mount = shell.getOverlayMount();
  if (mount) {
    mount.dispatchEvent(new CustomEvent(EVENTS.variantChanged, { detail: variant }));
  }
}

/** Belt-and-suspenders: bind to the host's own variation change signals too. */
function bindHostVariationSignals() {
  const onChange = debounce(syncVariant, OBSERVER_DEBOUNCE_MS);
  document.addEventListener('change', onChange, true);
  hostListeners.push(['change', onChange, true]);

  const onPop = debounce(syncVariant, OBSERVER_DEBOUNCE_MS);
  window.addEventListener('popstate', onPop);
  hostListeners.push(['popstate', onPop, false, true]);
}

/** Tear everything down (SPA navigation away from the PDP). No orphans, no leaks. */
export function teardown() {
  if (observer) {
    observer.disconnect();
    observer = null;
  }
  for (const [event, handler, capture, onWindow] of hostListeners) {
    const target = onWindow ? window : document;
    target.removeEventListener(event, handler, capture);
  }
  hostListeners = [];

  if (wrapper && wrapper.parentNode) wrapper.parentNode.removeChild(wrapper);
  wrapper = null;

  shell.destroy();
}
