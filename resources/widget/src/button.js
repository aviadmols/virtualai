// === CONSTANTS ===
// The injected "Tray On" button. It must sit in the HOST DOM (under add-to-cart) so its
// placement honors the merchant config — but it must stay style-isolated. So the button is
// rendered inside its OWN small shadow root attached to a host-DOM wrapper: host CSS can't
// reach the button, and the button's CSS can't leak onto the host. The wrapper carries the
// MOUNT_SENTINEL_ATTR so injection is idempotent (never duplicated).

import widgetCss from '../styles/widget.css';
import { MOUNT_SENTINEL_ATTR, APPEARANCE, PLACEMENT, POSITION } from './constants.js';
import { t } from './i18n.js';
import { el, safeQuery } from './dom.js';

const GLYPH = '✦';

let onClickHandler = null;

// Busy state: a try-on is generating. Shown on the LIVE button even after the popup is closed
// (background polling), and re-applied if the theme re-injects the button mid-generation.
let busy = false;
let currentButton = null;
let currentGlyph = null;
let currentLabelNode = null;
let currentLabelText = '';

/** Register the click handler (opens the modal). Called once at mount.start. */
export function onClick(handler) {
  onClickHandler = handler;
}

/** Toggle the button's "thinking" indicator. Safe before the button exists; re-applied on build. */
export function setBusy(on) {
  busy = !!on;
  applyBusy(busy);
}

function applyBusy(on) {
  if (!currentButton) return;
  currentButton.classList.toggle('ton-button--busy', on);
  currentButton.setAttribute('aria-busy', on ? 'true' : 'false');
  if (currentGlyph) {
    currentGlyph.classList.toggle('ton-button__glyph--busy', on);
    currentGlyph.textContent = on ? '' : GLYPH; // spinner (via CSS) while busy, else the mark
  }
  if (currentLabelNode) {
    currentLabelNode.nodeValue = on ? t('button.busy') : currentLabelText;
  }
}

/**
 * Build the host-DOM button wrapper (a span carrying the sentinel) with its own shadow root
 * holding the styled button. The appearance config drives label + colors via CSS vars.
 */
export function build(appearance) {
  const wrapper = el('span', { attrs: { [MOUNT_SENTINEL_ATTR]: '1' } });
  // Neutralize host inheritance on the wrapper itself.
  wrapper.style.setProperty('all', 'initial');
  wrapper.style.setProperty('display', 'block');

  const shadow = wrapper.attachShadow({ mode: 'open' });

  const style = document.createElement('style');
  style.textContent = widgetCss;
  shadow.appendChild(style);

  const root = el('div', { class: 'ton-root' });
  applyButtonVars(root, appearance);
  shadow.appendChild(root);

  const placement = appearance[APPEARANCE.placement];
  const fixedClass = fixedPlacementClass(placement);
  const label = appearance[APPEARANCE.label] || t('button.label');

  const glyph = el('span', { class: 'ton-button__glyph', text: GLYPH });
  const labelNode = document.createTextNode(label);

  const button = el(
    'button',
    {
      class: 'ton-button' + fixedClass,
      attrs: { type: 'button', 'aria-label': label },
      on: {
        click: (e) => {
          e.preventDefault();
          e.stopPropagation();
          if (onClickHandler) onClickHandler();
        },
      },
    },
    [glyph, labelNode],
  );

  root.appendChild(button);

  // Track the live button so setBusy reflects an in-flight generation on it — and re-apply the
  // busy state here in case the theme re-injected the button mid-generation.
  currentButton = button;
  currentGlyph = glyph;
  currentLabelNode = labelNode;
  currentLabelText = label;
  applyBusy(busy);

  return wrapper;
}

/** The fixed-corner modifier classes, or '' for the inline (after/before ATC) placements. */
function fixedPlacementClass(placement) {
  if (placement === PLACEMENT.fixedBR) return ' ton-button--fixed ton-button--fixed-end';
  if (placement === PLACEMENT.fixedBL) return ' ton-button--fixed ton-button--fixed-start';
  return '';
}

function applyButtonVars(root, appearance) {
  setVar(root, '--ton-button-bg', appearance[APPEARANCE.buttonBg]);
  setVar(root, '--ton-button-text', appearance[APPEARANCE.buttonText]);
  setVar(root, '--ton-accent', appearance[APPEARANCE.popupAccent]);
}

function setVar(root, name, value) {
  if (value) root.style.setProperty(name, value);
}

/**
 * Place the wrapper per the placement config. Fixed placements append to <body> (a screen
 * corner); CUSTOM places relative to the merchant-picked host anchor (visual picker); inline
 * placements insert after/before the add-to-cart anchor (default: after — below add-to-cart).
 *
 * `custom` = { selector, position } (only for PLACEMENT.custom). If the custom anchor no longer
 * resolves on the live page, we FALL BACK to the add-to-cart anchor so the button never vanishes.
 */
export function place(wrapper, anchor, placement, custom) {
  if (placement === PLACEMENT.fixedBR || placement === PLACEMENT.fixedBL) {
    document.body.appendChild(wrapper);
    return;
  }

  // Custom anchor wins when it resolves; otherwise fall through to the add-to-cart placement.
  if (placement === PLACEMENT.custom && custom && placeCustom(wrapper, custom)) {
    return;
  }

  const parent = anchor && anchor.parentNode;
  if (!parent) return;

  if (placement === PLACEMENT.beforeAtc) {
    parent.insertBefore(wrapper, anchor);
  } else {
    // Default after_add_to_cart (and the custom fallback): insert AFTER the anchor (below it).
    parent.insertBefore(wrapper, anchor.nextSibling);
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
  if (!parent) return false; // before/after need a parent (e.g. anchor is <html>)

  if (position === POSITION.before) {
    parent.insertBefore(wrapper, target);
  } else {
    parent.insertBefore(wrapper, target.nextSibling); // after (default)
  }
  return true;
}
