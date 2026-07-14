// === CONSTANTS ===
// PDP detection + the live variant reader. The widget boots only on a scanned/synced PDP: the
// bootstrap returns the confirmed `product` (or null). We treat product != null as the PDP
// signal — the server already matched this page URL to a confirmed product, so the widget never
// guesses a URL pattern in the browser.
//
// The variant the shopper SEES is the one that must be tried on and the one that must land in
// the cart. Three sources, in order of trust:
//   1. the host platform's LIVE variant id, stamped on our <script> tag by the Theme App
//      Extension and kept truthful across swatch clicks / ?variant= / popstate / DOM mutation;
//   2. the merchant-confirmed variations selector (pdp-scanner) read off the page;
//   3. the first available confirmed variant, so a try-on can always proceed.

import { SELECTOR_ROLES, SITE_KEY_ATTR, VARIANT_ID_ATTR } from './constants.js';
import { selectorString } from './dom.js';

/**
 * Is this a try-on PDP? True when the bootstrap returned a confirmed product. The page URL was
 * already matched server-side (source_url_hash), so a non-PDP simply has product=null and the
 * widget exits cleanly — no browser-side URL-pattern guessing.
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
 * Re-read the platform's variant id straight off our own <script> tag. The Theme App Extension
 * rewrites this attribute on every variant switch, so it is the freshest signal on the page —
 * fresher than anything we cached at boot.
 */
export function readExternalVariantId() {
  try {
    const tag = document.querySelector('script[' + SITE_KEY_ATTR + ']');
    const id = tag && tag.getAttribute(VARIANT_ID_ATTR);
    return id ? String(id).trim() || null : null;
  } catch {
    return null;
  }
}

/**
 * The variant the shopper currently has selected, mapped onto a confirmed product variant.
 * Never throws. Stable shape: { id, key, label, options, sku, externalId }.
 */
export function readSelectedVariant(selectorConfig, product, externalId) {
  const variants = product?.variants || [];
  if (variants.length === 0) return null;

  // (1) The platform's own id wins: it is what the shopper actually clicked.
  const byExternal = externalId ? matchExternal(variants, externalId) : null;

  // (2) Otherwise fall back to reading the merchant-confirmed variations control.
  const hostValue = byExternal ? null : readHostVariationValue(selectorConfig);
  const matched = byExternal || (hostValue ? matchVariant(variants, hostValue) : null);

  const chosen = matched || firstAvailable(variants) || variants[0];

  return toVariant(chosen);
}

/** Match on the numeric platform variant id (ProductPayload exposes it as `external_id`). */
function matchExternal(variants, externalId) {
  const needle = String(externalId);
  return variants.find((v) => v.external_id != null && String(v.external_id) === needle) || null;
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

/** A stable, comparable variant shape. `key` drives change detection; `externalId` drives the cart. */
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
    externalId: variant.external_id != null ? String(variant.external_id) : null,
  };
}
