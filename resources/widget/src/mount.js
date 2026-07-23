// === CONSTANTS ===
// The mount engine: a MutationObserver keeps the trigger injected through SPA re-renders (route
// change, quick-view, swatch click) — re-injected if missing, NEVER duplicated (a sentinel guards
// every inject). On every DOM change we also re-read the selected variant so the modal, the
// generation and the cart all reference the SAME variant the shopper sees.
//
// Variant signals, all wired: the Theme App Extension's `trayon:variant-change` (the authoritative
// one on Shopify — it carries the real numeric variant id), the host's own `change` events, the
// observer, and popstate. Whichever fires first wins; they are all debounced into one sync.
//
// Teardown disconnects the observer, removes the button, gives the merchant back the one style we
// ever wrote on their DOM, and clears every listener.

import {
  MOUNT_SENTINEL_ATTR,
  THEME_SLOT_ATTR,
  APPEARANCE,
  PLACEMENT,
  SELECTOR_ROLES,
  OBSERVER_DEBOUNCE_MS,
  EVENTS,
  HOST_VARIANT_EVENT,
} from './constants.js';
import { state } from './state.js';
import { debounce, safeQuery, selectorString } from './dom.js';
import { findAddToCart, readSelectedVariant, readExternalVariantId } from './pdp.js';
import * as button from './button.js';
import * as shell from './shell.js';

let observer = null;
let wrapper = null; // the host-DOM button wrapper (carries the sentinel)
let hostListeners = []; // host listeners we must remove on teardown

/** Start the mount engine. `openModal` runs on click; `prefetch` warms the modal chunk on hover. */
export function start(openModal, prefetch) {
  button.onClick(openModal, prefetch);

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
  const appearance = state.config.appearance;
  // The vyra design forces the glass button onto the product image (bottom-left) EVERYWHERE,
  // overriding the site's placement config. A merchant theme-slot block below still wins, and
  // the on-image path falls back to the add-to-cart anchor when the image can't be found, so
  // the button never vanishes from a live PDP.
  const placement = PLACEMENT.onImage;

  // Already mounted with a LIVE button? Do nothing. A theme that re-renders via cloneNode()
  // copies the sentinel attribute but NOT the shadow root (shadow roots are not cloned), leaving
  // a dead shell — detect + remove those so we re-inject a working button (TS-WIDGET-004).
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

  // A merchant-placed theme block is authoritative: if the "Tray it on" app block was dropped
  // into the product template, render the trigger INSIDE its slot — wherever the merchant put it,
  // ignoring the site's configured placement. Force the inline skin so a site set to a fixed /
  // on-image placement doesn't fixed-position a button that must live in the block's flow.
  const slot = safeQuery('[' + THEME_SLOT_ATTR + ']');
  if (slot) {
    wrapper = button.build({ ...appearance, [APPEARANCE.placement]: PLACEMENT.afterAtc });
    slot.appendChild(wrapper);
    return;
  }

  const anchor = findAddToCart(selectors, state.platform);

  // On-image needs EITHER the product-image container OR the add-to-cart fallback to resolve —
  // else the theme isn't ready yet and the observer will call us again.
  const imageTarget = safeQuery(selectorString(selectors, SELECTOR_ROLES.productImage));
  if (!anchor && !imageTarget) return;

  wrapper = button.build({ ...appearance, [APPEARANCE.placement]: placement });
  button.place(wrapper, anchor, placement, null, selectors);
}

/** Re-read the selected variant; emit a change event when it differs. */
function syncVariant() {
  // Always re-read the platform id off the tag: the extension keeps it truthful, and it is the
  // freshest signal on the page.
  const externalId = readExternalVariantId();
  if (externalId) state.externalVariantId = externalId;

  const variant = readSelectedVariant(state.config.selectors, state.product, state.externalVariantId);
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

/** Bind every host signal that can mean "the shopper picked a different variant". */
function bindHostVariationSignals() {
  const sync = debounce(syncVariant, OBSERVER_DEBOUNCE_MS);

  // The Theme App Extension's own event — the authoritative Shopify signal. It carries the real
  // numeric variant id, so we take it straight off the detail instead of re-deriving it.
  const onHostVariant = (e) => {
    const id = e && e.detail && e.detail.variantId;
    if (id) state.externalVariantId = String(id);
    sync();
  };
  document.addEventListener(HOST_VARIANT_EVENT, onHostVariant);
  hostListeners.push([document, HOST_VARIANT_EVENT, onHostVariant, false]);

  document.addEventListener('change', sync, true);
  hostListeners.push([document, 'change', sync, true]);

  window.addEventListener('popstate', sync);
  hostListeners.push([window, 'popstate', sync, false]);
}

/** Tear everything down (SPA navigation away from the PDP). No orphans, no leaks. */
export function teardown() {
  if (observer) {
    observer.disconnect();
    observer = null;
  }
  for (const [target, event, handler, capture] of hostListeners) {
    target.removeEventListener(event, handler, capture);
  }
  hostListeners = [];

  if (wrapper && wrapper.parentNode) wrapper.parentNode.removeChild(wrapper);
  wrapper = null;

  button.releaseHostStyles();
  shell.destroy();
}
