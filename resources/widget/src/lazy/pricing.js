// === CONSTANTS ===
// Display-ONLY member pricing. For a VERIFIED club member on a club-enabled site, each configured
// price-zone selector that resolves on THIS page has its shown price parsed, discounted by the
// site's discount_percent, and rewritten IN PLACE with a small "club price" affordance. Nothing
// here touches checkout or a real discount code — it is a visual annotation only.
//
// Guarantees:
//   - IDLE-scheduled (called from the loader's existing requestIdleCallback path) — zero sync work.
//   - ZERO host layout shift (CLS): we ONLY rewrite the text of the existing price node and append
//     ONE inline badge as its child. We never insert/move a host block node, never change the
//     node's box model, and the rewritten money keeps the SAME currency symbol + separators
//     (only the digits change), so the node's measured width barely moves. The perf gate asserts
//     CLS < 0.02 with pricing applied.
//   - SILENT fallback: an unresolvable selector, an unparsable price, or an already-processed node
//     is skipped without a throw or a console error into the host page.
//   - Re-applies on the existing `trayon:variant-changed` event (a swatch/dropdown can swap the
//     price). Re-apply recomputes from the STASHED original text, so it never double-discounts.
//   - Non-members / disabled sites / no zones => a complete no-op (nothing is read or written).
//
// Money parsing follows the pdp-scan money scar (TS-PDPSCAN-001): the decimal separator is
// locale-dependent (`.` OR `,`), so we detect the layout — the LAST-appearing separator with a
// short trailing run is the decimal — rather than a naive str-replace. We preserve the node's
// exact surrounding format (symbol placement, grouping, decimal char, trailing text) and only
// substitute the recomputed number, so the rewrite reads native to the store.

import {
  CLUB_CONFIG,
  CLUB_ZONES,
  PRICING_MARK_ATTR,
  PRICING_ORIGINAL_ATTR,
  PRICING_BADGE_CLASS,
  EVENTS,
} from '../constants.js';
import { warn, onIdle } from '../dom.js';
import { t, shell } from './bridge.js';


// Grouping/decimal separators a store may use: dot, comma, ASCII space, NBSP, narrow NBSP.
const SEP_CLASS = '[.,\\u0020\\u00a0\\u202f]';
// A number token: a run of digits interleaved with the separators above. Captured so we can
// splice the recomputed number back into the exact surrounding text (currency symbol, %, etc.).
const NUMBER_RE = new RegExp('\\d(?:' + SEP_CLASS + '*\\d)*');
// A single space-family grouping separator, matched between two digits (never a decimal point).
const SPACE_GROUP_RE = /\d([   ])\d/;

// Module state: the resolved config + the bound variant-changed handler (for clean teardown).
let discountFraction = 0; // e.g. 0.15 for 15% off
let zoneSelectors = []; // the union of pdp+catalog+cart selectors to try on this page
let boundVariantHandler = null;
let variantMount = null;

/**
 * Initialise member pricing on the idle path. A no-op unless the site is club-enabled AND the
 * shopper is a verified member AND there is at least one configured zone selector. `isMember`
 * comes from club.js (bootstrap `club.member.verified` OR a freshly-verified session).
 */
export function init(clubConfig, isMember) {
  if (!isEligible(clubConfig, isMember)) return;

  const percent = Number(clubConfig[CLUB_CONFIG.discountPercent]) || 0;
  if (percent <= 0 || percent > 100) return; // nothing to show at 0% (or a bad value)
  discountFraction = percent / 100;

  zoneSelectors = collectZoneSelectors(clubConfig[CLUB_CONFIG.priceZones]);
  if (zoneSelectors.length === 0) return;

  // Ride the loader's idle path: the first sweep runs off the main thread's critical work.
  onIdle(apply, 2000);

  // Re-apply when the shopper changes a variant (the host may swap the shown price).
  bindVariantChange();
}

/** Eligible = club enabled + a verified member. Anything else => a complete no-op. */
function isEligible(clubConfig, isMember) {
  return !!(clubConfig && clubConfig[CLUB_CONFIG.enabled] && isMember);
}

/** The union of the configured pdp/catalog/cart selectors (all are tried on every page; only the
 *  ones that resolve here take effect — a PDP has pdp selectors, a cart page has cart selectors). */
function collectZoneSelectors(priceZones) {
  const out = [];
  if (!priceZones || typeof priceZones !== 'object') return out;
  for (const zone of CLUB_ZONES) {
    const list = priceZones[zone];
    if (Array.isArray(list)) {
      for (const sel of list) {
        if (typeof sel === 'string' && sel.trim()) out.push(sel.trim());
      }
    }
  }
  return out;
}

/** Sweep every configured selector and rewrite the resolved price nodes. Fully fail-soft. */
function apply() {
  for (const selector of zoneSelectors) {
    let nodes;
    try {
      nodes = document.querySelectorAll(selector);
    } catch {
      continue; // a malformed merchant selector must never break the host page
    }
    nodes.forEach(rewriteNode);
  }
}

/**
 * Rewrite ONE price node in place. Idempotent: the original formatted text is stashed on first
 * touch and every rewrite recomputes from it, so a re-apply (variant change) can never stack the
 * discount. Skips silently when the text has no parsable number.
 */
function rewriteNode(node) {
  if (!node) return;

  // The source text = the stashed original (re-apply) or the current text (first touch).
  const original = node.getAttribute(PRICING_ORIGINAL_ATTR) ?? textOf(node);
  if (original == null || original === '') return;

  const parsed = parseMoney(original);
  if (!parsed) return; // unparsable -> leave the host price exactly as-is

  const discounted = original.slice(0, parsed.start)
    + formatLikeSource(parsed, parsed.value * (1 - discountFraction))
    + original.slice(parsed.end);

  // Zero-CLS write: replace ONLY the text (same symbol/separators, only digits change) and append
  // ONE inline badge child. No host block node is inserted/moved; the box model is untouched.
  writePrice(node, original, discounted);
}

/** The node's own price text, ignoring an already-appended badge child (so re-reads stay clean). */
function textOf(node) {
  let text = '';
  let n = node.firstChild;
  while (n) {
    if (n.nodeType === 1 && n.classList && n.classList.contains(PRICING_BADGE_CLASS)) break;
    if (n.nodeType === 3) text += n.textContent;
    n = n.nextSibling;
  }
  return text || node.textContent;
}

/** Set the price text (leaving any non-text children in place) + ensure a single inline badge. */
function writePrice(node, original, discounted) {
  // Stash the source once so a re-apply recomputes from it (never double-discounts).
  if (!node.hasAttribute(PRICING_ORIGINAL_ATTR)) {
    node.setAttribute(PRICING_ORIGINAL_ATTR, original);
  }
  node.setAttribute(PRICING_MARK_ATTR, '');

  // Rewrite only the leading text node(s) that hold the price; keep the badge child intact.
  let wrote = false;
  let n = node.firstChild;
  while (n) {
    const next = n.nextSibling;
    if (n.nodeType === 1 && n.classList && n.classList.contains(PRICING_BADGE_CLASS)) {
      n = next;
      continue; // don't touch our own badge
    }
    if (n.nodeType === 3) {
      if (!wrote) {
        n.textContent = discounted; // the recomputed price replaces the first text node
        wrote = true;
      } else {
        n.textContent = ''; // fold trailing text nodes into the first (keeps width tight)
      }
    }
    n = next;
  }
  if (!wrote) {
    node.insertBefore(document.createTextNode(discounted), node.firstChild);
  }

  ensureBadge(node);
}

/** Append exactly one small "club price" affordance (inline, so it never shifts a block). */
function ensureBadge(node) {
  if (node.querySelector && node.querySelector('.' + PRICING_BADGE_CLASS)) return;
  const badge = document.createElement('span');
  badge.className = PRICING_BADGE_CLASS;
  badge.textContent = ' ' + t('club.member_price');
  // Inline, muted, and unobtrusive. Structural inline hint only (no host token dependency, since
  // this lives in the HOST light DOM outside our shadow root — it must not rely on --ton-* vars).
  badge.style.setProperty('font-size', '0.75em');
  badge.style.setProperty('opacity', '0.7');
  badge.style.setProperty('white-space', 'nowrap');
  node.appendChild(badge);
}

// ---------------------------------------------------------------------------
// Money parsing — locale-aware (TS-PDPSCAN-001). Detect the number token, decide which of
// `.`/`,` is the decimal by LAYOUT (the last separator with a short trailing run), parse to a
// numeric value, and remember the exact format so we can re-emit it with the discounted number.
// ---------------------------------------------------------------------------
function parseMoney(text) {
  const match = NUMBER_RE.exec(text);
  if (!match) return null;

  const raw = match[0];
  const start = match.index;
  const end = start + raw.length;

  const layout = detectLayout(raw);
  if (!layout) return null;

  const value = Number(layout.normalized);
  if (!isFinite(value)) return null;

  return { value, start, end, decimalChar: layout.decimalChar, groupChar: layout.groupChar, decimals: layout.decimals };
}

/**
 * Decide the number layout from its punctuation. `.`/`,`/space/NBSP/narrow-NBSP are grouping OR
 * decimal; the separator that appears LAST and is followed by a short (1-2) digit run is the
 * decimal. Everything else is grouping and stripped. Returns a normalized `1234.56` string plus
 * the store's decimal/group chars and decimal-count so the rewrite matches the source exactly.
 */
function detectLayout(raw) {
  const digitsOnly = raw.replace(/[^\d]/g, '');
  if (digitsOnly === '') return null;

  const dotIdx = raw.lastIndexOf('.');
  const commaIdx = raw.lastIndexOf(',');
  const lastIdx = Math.max(dotIdx, commaIdx);

  // The last `.`/`,` is the decimal ONLY if it splits off a real fractional run of 1-2 digits.
  // (Space-family separators are NEVER a decimal point — always grouping.)
  const trailing = lastIdx >= 0 ? raw.slice(lastIdx + 1).replace(/[^\d]/g, '') : '';
  const isDecimal = lastIdx >= 0 && trailing.length >= 1 && trailing.length <= 2
    && raw.slice(lastIdx + 1).replace(/[\d]/g, '') === ''; // nothing but digits after the decimal

  if (!isDecimal) {
    // No decimal part: every separator is grouping.
    return { normalized: digitsOnly, decimalChar: '', groupChar: detectGroupChar(raw, null), decimals: 0 };
  }

  const decimalChar = raw[lastIdx];
  const intPart = raw.slice(0, lastIdx).replace(/[^\d]/g, '');
  const fracPart = trailing;

  return {
    normalized: (intPart || '0') + '.' + fracPart,
    decimalChar,
    groupChar: detectGroupChar(raw, decimalChar),
    decimals: fracPart.length,
  };
}

/** The grouping char (the OTHER of `.`/`,`, or a space-family char) — '' when the number is small. */
function detectGroupChar(raw, decimalChar) {
  const other = decimalChar === '.' ? ',' : decimalChar === ',' ? '.' : null;
  if (other && raw.indexOf(other) >= 0) return other;
  const spaceGroup = raw.match(SPACE_GROUP_RE);
  if (spaceGroup) return spaceGroup[1];
  // No explicit grouping seen but a `.`/`,` exists that ISN'T the decimal -> it's grouping.
  if (decimalChar !== '.' && raw.indexOf('.') >= 0) return '.';
  if (decimalChar !== ',' && raw.indexOf(',') >= 0) return ',';
  return '';
}

/**
 * Re-emit the discounted number in the SAME format as the source (same decimal char, same
 * grouping char + grouping, same number of decimals) so the rewritten price reads native and the
 * node's width barely changes (protects CLS).
 */
function formatLikeSource(parsed, value) {
  const fixed = value.toFixed(parsed.decimals);
  const [intRaw, fracRaw = ''] = fixed.split('.');

  let intOut = intRaw;
  if (parsed.groupChar) intOut = group(intRaw, parsed.groupChar);

  if (parsed.decimals > 0) return intOut + parsed.decimalChar + fracRaw;
  return intOut;
}

/** Group an integer string in 3s using the store's grouping char. */
function group(intStr, groupChar) {
  return intStr.replace(/\B(?=(\d{3})+(?!\d))/g, groupChar);
}

// ---------------------------------------------------------------------------
// Variant-change re-apply — the host may swap the shown price on a swatch/dropdown change.
// ---------------------------------------------------------------------------
function bindVariantChange() {
  variantMount = shell.getOverlayMount();
  if (!variantMount) return; // no overlay mount (non-PDP page) -> no variant swaps to react to
  boundVariantHandler = () => {
    // A tiny idle deferral lets the host finish swapping the DOM before we recompute.
    onIdle(reapply, 500);
  };
  variantMount.addEventListener(EVENTS.variantChanged, boundVariantHandler);
}

/** Re-run the sweep. Nodes recompute from their STASHED original, so this never double-discounts. */
function reapply() {
  apply();
}

/** Teardown (SPA navigation away): drop the variant listener. No leaks. */
export function teardown() {
  if (variantMount && boundVariantHandler) {
    try {
      variantMount.removeEventListener(EVENTS.variantChanged, boundVariantHandler);
    } catch {
      warn('failed to unbind club-pricing variant listener');
    }
  }
  boundVariantHandler = null;
  variantMount = null;
}
