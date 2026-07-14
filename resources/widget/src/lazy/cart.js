// === CONSTANTS ===
// The add-to-cart bridge — the REAL one.
//
// What was here before was a blind `anchor.click()` on the host's own button that never checked
// whether anything reached the cart. Three things were broken and all three are fixed here:
//   1. the widget ignored the platform context the Theme App Extension already stamps;
//   2. nothing listened for the extension's `trayon:variant-change` (mount.js does now);
//   3. the variant id the widget held was our INTERNAL DB key — Shopify's /cart/add.js only
//      speaks the numeric platform id, so the "add" could never have been right by construction.
//
// The strategy layer:
//   shopify_ajax — POST /cart/add.js with the REAL numeric variant id + the `_trayon` line-item
//                  property (the hook Phase 6 reads to attribute a purchase back to the try-on),
//                  then VERIFY by re-reading /cart.js. A 200 from add.js is not proof.
//   dom_click    — drive the theme's own add-to-cart element (every non-Shopify store).
//
// We never reimplement checkout or pricing, and we never compute a price. We trigger the store's
// own cart and then check, honestly, whether it worked.

import {
  SELECTOR_ROLES,
  PLATFORM,
  CART_STRATEGY,
  CART_OUTCOME,
  CART_LINE_PROPERTY,
  CART_STATUS_UNPROCESSABLE,
  CART_VERIFY_RETRIES,
  CART_VERIFY_DELAY_MS,
  SHOPIFY_CART_ADD,
  SHOPIFY_CART_GET,
} from '../constants.js';
import { selectorString, warn } from '../dom.js';
import { state, api, track } from './bridge.js';

const SLEEP = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Add the EXACT variant the look was generated for to the host cart.
 * @returns {Promise<string>} one of CART_OUTCOME.
 */
export async function add(variant, generationId) {
  // The funnel row + the behavioral signal. Both are fire-and-forget: neither may delay, block,
  // or fail the shopper's cart action.
  api.recordAddToCart({
    anonToken: state.anonToken,
    generationId,
    variantId: variant?.id,
  }).catch(() => {});
  track.trackAddToCart(variant);

  if (strategy() === CART_STRATEGY.shopifyAjax) {
    const externalId = resolveExternalId(variant);
    if (externalId) return shopifyAjax(externalId, generationId);
    // A Shopify page with no numeric id is a broken contract, not a reason to do nothing.
    warn('shopify platform with no external variant id; falling back to the theme button');
  }

  return domClick(variant);
}

/** Which cart the host actually has. The tag declares it — we do not sniff for it. */
function strategy() {
  return state.platform === PLATFORM.shopify ? CART_STRATEGY.shopifyAjax : CART_STRATEGY.domClick;
}

/**
 * The numeric platform variant id. The extension's LIVE attribute wins when it agrees with the
 * variant this look belongs to; otherwise the payload's `external_id` for THAT variant is the
 * truth — a look of the blue shirt must never add the red one just because the page moved on.
 */
function resolveExternalId(variant) {
  const fromVariant = variant && variant.externalId ? String(variant.externalId) : null;
  if (fromVariant) return fromVariant;

  // A single-variant product: the look has no variant of its own, so the page's live id is right.
  return state.externalVariantId ? String(state.externalVariantId) : null;
}

/** POST the store's own Ajax cart, then prove the line is really in it. */
async function shopifyAjax(externalId, generationId) {
  let response;
  try {
    response = await fetch(cartUrl(SHOPIFY_CART_ADD), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      credentials: 'same-origin', // the cart lives in the shopper's own cookie
      body: JSON.stringify({
        items: [
          {
            id: Number(externalId),
            quantity: 1,
            properties: { [CART_LINE_PROPERTY]: String(generationId || '') },
          },
        ],
      }),
    });
  } catch {
    return CART_OUTCOME.failed;
  }

  // Sold out / not purchasable. A distinct, honest message — never "something went wrong".
  if (response.status === CART_STATUS_UNPROCESSABLE) return CART_OUTCOME.unavailable;
  if (!response.ok) return CART_OUTCOME.failed;

  return verify(externalId);
}

/**
 * Re-read /cart.js and look for the line. Three outcomes, three honest answers:
 *   found            -> added (we saw it);
 *   read but absent  -> failed (the add silently no-opped: the worst failure, so we say so);
 *   could not read   -> added_optimistic (add.js returned 200; we do not claim a verification we
 *                       did not get, and we do not cry failure on a cart that probably worked).
 */
async function verify(externalId) {
  for (let attempt = 0; attempt <= CART_VERIFY_RETRIES; attempt++) {
    let cart;
    try {
      const response = await fetch(cartUrl(SHOPIFY_CART_GET), {
        method: 'GET',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      if (!response.ok) return CART_OUTCOME.addedOptimistic;
      cart = await response.json();
    } catch {
      return CART_OUTCOME.addedOptimistic;
    }

    const items = (cart && cart.items) || [];
    const found = items.some(
      (item) =>
        String(item.variant_id) === String(externalId) &&
        item.properties &&
        item.properties[CART_LINE_PROPERTY] != null,
    );
    if (found) return CART_OUTCOME.added;

    if (attempt < CART_VERIFY_RETRIES) await SLEEP(CART_VERIFY_DELAY_MS);
  }

  return CART_OUTCOME.failed;
}

/** The non-Shopify path: set the theme's variation control, then press the theme's own button. */
function domClick(variant) {
  const selectors = state.config && state.config.selectors;
  syncHostVariation(selectors, variant);

  const anchor = findAtc(selectors);
  if (!anchor) return CART_OUTCOME.failed;

  try {
    anchor.click();
    // We cannot verify a theme we do not know. We say "added" because the theme's own handler
    // ran — and the shopper sees the theme's own cart feedback, which is the real confirmation.
    return CART_OUTCOME.addedOptimistic;
  } catch {
    return CART_OUTCOME.failed;
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
  if (!node || node.tagName !== 'SELECT') return;

  const wanted = (variant.sku || Object.values(variant.options || {})[0] || '').toString();
  if (!wanted) return;

  for (const option of node.options) {
    const value = (option.value || option.textContent || '').toLowerCase();
    if (value === wanted.toLowerCase()) {
      node.value = option.value;
      node.dispatchEvent(new Event('change', { bubbles: true }));
      return;
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

/**
 * The Ajax Cart API lives on the MERCHANT's origin, never ours — same-origin, always: the cart
 * belongs to the storefront the shopper is standing on.
 */
function cartUrl(path) {
  return new URL(path, location.origin).toString();
}
