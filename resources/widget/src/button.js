// === CONSTANTS ===
// The injected trigger. It must sit in the HOST DOM (under add-to-cart, or ON the product
// image) so its placement honors the merchant config — but it must stay style-isolated. So it
// is rendered inside its OWN small shadow root attached to a host-DOM wrapper: host CSS can't
// reach the button, and the button's CSS can't leak onto the host. The wrapper carries the
// MOUNT_SENTINEL_ATTR so injection is idempotent (never duplicated).
//
// The label is locked to the SYSTEM font forever. It renders before Outfit exists and would
// otherwise visibly re-flow the instant the modal chunk loads the webfont — a flicker sitting
// on top of the merchant's product photo. The modal is the jewelry; the trigger is a 15px string.

import coreCss from '../styles/core.css';
import {
  MOUNT_SENTINEL_ATTR,
  APPEARANCE,
  PLACEMENT,
  POSITION,
  ON_IMAGE_WRAPPER_STYLE,
  HOST_POSITION_STATIC,
  HOST_POSITION_RELATIVE,
  SELECTOR_ROLES,
} from './constants.js';
import { t } from './i18n.js';
import { el, safeQuery, warn, selectorString, setStyles, restoreStyles } from './dom.js';
import { ICON_SPARKLE, gradientDefs } from './icons.js';
import { resolveCss } from './shell.js';

let onClickHandler = null;
let onHoverHandler = null; // the chunk prefetch hint (pointerenter / focus / touchstart)

// Busy = a generation is in flight (shown even after the popup is closed, re-applied if the
// theme re-injects the button). Loading = the modal chunk is being fetched.
let busy = false;
let loading = false;
let currentButton = null;
let currentGlyph = null;
let currentLabel = null;
let currentLabelText = '';

// The one host node we ever write a style onto (the product-image container), and what it had.
let positioned = null;
let positionedPrev = null;

/** Register the click handler (opens the modal) + the prefetch hint. Called once at mount.start. */
export function onClick(handler, hover) {
  onClickHandler = handler;
  onHoverHandler = hover;
}

/** A try-on is generating. Safe before the button exists; re-applied when it is rebuilt. */
export function setBusy(on) {
  busy = !!on;
  apply();
}

/** The modal chunk is in flight: the trigger IS the progress indicator (§6.3). */
export function setLoading(on) {
  loading = !!on;
  apply();
}

function apply() {
  if (!currentButton) return;
  const spinning = busy || loading;

  currentButton.classList.toggle('ton-button--busy', busy);
  currentButton.classList.toggle('ton-button--loading', loading);
  currentButton.setAttribute('aria-busy', spinning ? 'true' : 'false');

  if (currentGlyph) currentGlyph.classList.toggle('ton-button__glyph--busy', spinning);
  if (currentLabel) {
    currentLabel.textContent = loading
      ? t('button.loading')
      : busy
        ? t('button.busy')
        : currentLabelText;
  }
}

/**
 * Build the host-DOM wrapper (a span carrying the sentinel) with its own shadow root holding
 * the styled button. The appearance config drives label + colors (classic placement only — the
 * on-image trigger is glass by definition and ignores button_bg / button_text_color).
 */
export function build(appearance) {
  const wrapper = el('span', { attrs: { [MOUNT_SENTINEL_ATTR]: '1' } });
  // Neutralize host inheritance on the wrapper itself.
  wrapper.style.setProperty('all', 'initial');
  wrapper.style.setProperty('display', 'block');

  const shadow = wrapper.attachShadow({ mode: 'open' });

  const style = document.createElement('style');
  style.textContent = resolveCss(coreCss);
  shadow.appendChild(style);

  const root = el('div', { class: 'ton-root' });
  applyButtonVars(root, appearance);
  shadow.appendChild(root);

  // Every shadow root that renders the sparkle needs its OWN gradient def — the id is scoped.
  root.appendChild(gradientDefs());

  const placement = appearance[APPEARANCE.placement];
  const label = appearance[APPEARANCE.label] || t('button.label');

  const glyph = el('span', {
    class: 'ton-button__glyph',
    html: ICON_SPARKLE,
    attrs: { 'aria-hidden': 'true' },
  });
  const labelNode = el('span', { class: 'ton-button__label', text: label });

  const button = el(
    'button',
    {
      class: 'ton-button' + modifierClass(placement),
      attrs: { type: 'button', 'aria-label': label },
      on: {
        click: (e) => {
          e.preventDefault();
          e.stopPropagation();
          if (onClickHandler) onClickHandler();
        },
        pointerenter: hint,
        focus: hint,
        touchstart: hint,
      },
    },
    [glyph, labelNode],
  );

  root.appendChild(button);

  currentButton = button;
  currentGlyph = glyph;
  currentLabel = labelNode;
  currentLabelText = label;
  apply();

  return wrapper;
}

/** Warm the modal chunk on a hint. Most clicks then find it already here. */
function hint() {
  if (onHoverHandler) onHoverHandler();
}

function modifierClass(placement) {
  if (placement === PLACEMENT.fixedBR) return ' ton-button--fixed ton-button--fixed-end';
  if (placement === PLACEMENT.fixedBL) return ' ton-button--fixed ton-button--fixed-start';
  if (placement === PLACEMENT.onImage) return ' ton-button--on-image';
  return '';
}

function applyButtonVars(root, appearance) {
  setVar(root, '--ton-button-bg', appearance[APPEARANCE.buttonBg]);
  setVar(root, '--ton-button-text', appearance[APPEARANCE.buttonText]);
}

function setVar(root, name, value) {
  if (value) root.style.setProperty(name, value);
}

/**
 * Place the wrapper per the placement config.
 *  - fixed            -> a screen corner (appended to <body>);
 *  - on_product_image -> absolutely, inside the merchant's product-image container;
 *  - custom           -> relative to the merchant-picked host anchor (visual picker);
 *  - inline           -> after/before the add-to-cart anchor (default: after — below it).
 *
 * Every path FALLS BACK to the add-to-cart anchor when its own target does not resolve: the
 * button must never vanish from a live PDP.
 */
export function place(wrapper, anchor, placement, custom, selectors) {
  if (placement === PLACEMENT.fixedBR || placement === PLACEMENT.fixedBL) {
    document.body.appendChild(wrapper);
    return;
  }

  if (placement === PLACEMENT.onImage && placeOnImage(wrapper, selectors)) return;

  if (placement === PLACEMENT.custom && custom && placeCustom(wrapper, custom)) return;

  const parent = anchor && anchor.parentNode;
  if (!parent) return;

  if (placement === PLACEMENT.beforeAtc) {
    parent.insertBefore(wrapper, anchor);
  } else {
    // Default after_add_to_cart (and every fallback): insert AFTER the anchor (below it).
    parent.insertBefore(wrapper, anchor.nextSibling);
  }
}

/**
 * The glass trigger, laid over the product photo. It anchors to the element the existing
 * `product_image` selector role resolves (no new config, no new scan field). A bare <img> is
 * not a positioning context, so we climb to its parent block.
 *
 * Vsio makes EXACTLY ONE style write on a node it does not own: `position: relative` on that
 * container, and only when its computed position is `static`. Reverted on teardown. Absolute
 * positioning inside an existing box shifts nothing — the CLS gate proves it.
 */
function placeOnImage(wrapper, selectors) {
  const target = safeQuery(selectorString(selectors, SELECTOR_ROLES.productImage));
  if (!target) return false;

  const container = target.tagName === 'IMG' || target.tagName === 'PICTURE'
    ? target.parentElement
    : target;
  if (!container || container === document.body) return false;

  try {
    if (getComputedStyle(container).position === HOST_POSITION_STATIC) {
      positioned = container;
      positionedPrev = setStyles(container, { position: HOST_POSITION_RELATIVE });
    }
    setStyles(wrapper, ON_IMAGE_WRAPPER_STYLE);
    container.appendChild(wrapper);
    return true;
  } catch {
    warn('on-image placement failed; falling back to add-to-cart');
    return false;
  }
}

/**
 * Place the wrapper at the merchant's custom anchor + position. Returns false (→ caller falls
 * back to add-to-cart) when the anchor is missing/malformed or the position needs a parent it
 * doesn't have. before/after insert as siblings; prepend/append go inside the anchor.
 */
function placeCustom(wrapper, custom) {
  const target = safeQuery(custom && custom.selector);
  if (!target) return false;

  const position = custom.position;

  if (position === POSITION.prepend) {
    target.insertBefore(wrapper, target.firstChild);
    return true;
  }
  if (position === POSITION.append) {
    target.appendChild(wrapper);
    return true;
  }

  const parent = target.parentNode;
  if (!parent) return false; // before/after need a parent (e.g. the anchor is <html>)

  if (position === POSITION.before) {
    parent.insertBefore(wrapper, target);
  } else {
    parent.insertBefore(wrapper, target.nextSibling); // after (default)
  }
  return true;
}

/** Give the merchant's DOM back exactly as we found it (the one style write we ever made). */
export function releaseHostStyles() {
  if (positioned) restoreStyles(positioned, positionedPrev);
  positioned = null;
  positionedPrev = null;
  currentButton = null;
  currentGlyph = null;
  currentLabel = null;
}
