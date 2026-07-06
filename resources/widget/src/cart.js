// === CONSTANTS ===
// The add-to-cart bridge. The MVP strategy: drive the HOST store's own add-to-cart for the
// selected variant by (1) setting the host variations control to the chosen variant, then
// (2) clicking the host add-to-cart element. We never reimplement checkout/pricing — we
// trigger the theme's own cart logic. We also record the funnel event server-side. A
// platform-specific strategy layer (Shopify ajax/form, Woo, custom override) + verify-in-
// cart is the documented follow-up (see the final report).

import { SELECTOR_ROLES } from './constants.js';
import { selectorString } from './dom.js';
import * as api from './api.js';
import * as track from './track.js';

/**
 * Add the EXACT captured variant to the host cart.
 * @returns {Promise<boolean>} true if the host add-to-cart element was triggered.
 */
export async function add(variant, selectorConfig, anonToken, generationId) {
  // Record the funnel event (best-effort; never blocks the host cart action).
  api
    .recordAddToCart({ anonToken, generationId, variantId: variant?.id })
    .catch(() => {});

  // The meaningful behavioral interaction (batched, fire-and-forget; separate from the funnel row).
  track.trackAddToCart(variant);

  syncHostVariation(selectorConfig, variant);

  const anchor = findAtc(selectorConfig);
  if (!anchor) return false;

  try {
    anchor.click();
    return true;
  } catch {
    return false;
  }
}

/** Set the host variations control to the chosen variant before clicking add-to-cart. */
function syncHostVariation(selectorConfig, variant) {
  const css = selectorString(selectorConfig, SELECTOR_ROLES.variations);
  if (!css || !variant) return;

  let node;
  try {
    node = document.querySelector(css);
  } catch {
    return;
  }
  if (!node) return;

  const wanted = (variant.sku || Object.values(variant.options || {})[0] || '').toString();
  if (!wanted) return;

  if (node.tagName === 'SELECT') {
    for (const opt of node.options) {
      const v = (opt.value || opt.textContent || '').toLowerCase();
      if (v === wanted.toLowerCase()) {
        node.value = opt.value;
        node.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }
    }
  }
}

function findAtc(selectorConfig) {
  const css = selectorString(selectorConfig, SELECTOR_ROLES.addToCart);
  if (!css) return null;
  try {
    return document.querySelector(css);
  } catch {
    return null;
  }
}
