// === CONSTANTS ===
// PDP detection + the live variant reader. The widget boots only on a scanned PDP: the
// bootstrap returns the confirmed `product` (or null). We treat product != null as the
// PDP signal — the server already matched this page URL to a confirmed product, so the
// widget never needs to guess a URL pattern in the browser. The add-to-cart selector
// existence is the secondary DOM gate (the button needs an anchor to mount under).

import { SELECTOR_ROLES } from './constants.js';
import { selectorString } from './dom.js';

/**
 * Is this a try-on PDP? True when the bootstrap returned a confirmed product. The page URL
 * was already matched server-side (source_url_hash), so a non-PDP simply has product=null
 * and the widget exits cleanly — no browser-side URL-pattern guessing.
 */
export function isProductPage(bootstrap) {
  return !!bootstrap?.product;
}

/** The confirmed add-to-cart anchor element on the host page (or null if not yet rendered). */
export function findAddToCart(selectorConfig) {
  const css = selectorString(selectorConfig, SELECTOR_ROLES.addToCart);
  if (!css) return null;
  try {
    return document.querySelector(css);
  } catch {
    return null; // a malformed selector from config never throws into the host page
  }
}

/**
 * Read the variant the shopper currently has selected, mapped onto a confirmed product
 * variant. Strategy (best-effort, never throws):
 *  1. read the host variations control (a <select> or a checked radio) for its value/label;
 *  2. match that label/value against the confirmed product.variants options;
 *  3. fall back to the first available confirmed variant so a try-on can always proceed.
 * The returned shape is stable: { id, key, label, options }.
 */
export function readSelectedVariant(selectorConfig, product) {
  const variants = product?.variants || [];
  if (variants.length === 0) return null;

  const hostValue = readHostVariationValue(selectorConfig);
  const matched = hostValue ? matchVariant(variants, hostValue) : null;
  const chosen = matched || firstAvailable(variants) || variants[0];

  return toVariant(chosen);
}

/** Read the selected value/text from the host's variations control, if present. */
function readHostVariationValue(selectorConfig) {
  const css = selectorString(selectorConfig, SELECTOR_ROLES.variations);
  if (!css) return null;

  let node;
  try {
    node = document.querySelector(css);
  } catch {
    return null;
  }
  if (!node) return null;

  if (node.tagName === 'SELECT') {
    const opt = node.options[node.selectedIndex];
    return (opt?.value || opt?.textContent || '').trim() || null;
  }

  // Radio/checkbox group: the checked input's value.
  if (node.type === 'radio' || node.type === 'checkbox') {
    return node.checked ? (node.value || '').trim() || null : null;
  }

  // A container of swatches: the value of a checked input within it.
  const checked = node.querySelector?.('input:checked');
  if (checked) return (checked.value || '').trim() || null;

  return (node.value || '').trim() || null;
}

/** Match a host-selected value against a confirmed variant's options or sku. */
function matchVariant(variants, hostValue) {
  const needle = hostValue.toLowerCase();
  return (
    variants.find((v) => String(v.sku || '').toLowerCase() === needle) ||
    variants.find((v) =>
      Object.values(v.options || {}).some((o) => String(o).toLowerCase() === needle),
    ) ||
    null
  );
}

function firstAvailable(variants) {
  return variants.find((v) => v.available) || null;
}

/** A stable, comparable variant shape. `key` drives change detection + the cart bridge. */
function toVariant(variant) {
  if (!variant) return null;
  const options = variant.options || {};
  const label = Object.values(options).join(' / ') || variant.sku || `#${variant.id}`;
  return {
    id: variant.id,
    key: String(variant.id),
    label,
    options,
    sku: variant.sku || null,
  };
}
