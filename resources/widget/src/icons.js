// === CONSTANTS ===
// The CORE icon set (the trigger + the modal shell). Every icon is an inline SVG string —
// no icon font, no sprite fetch, no third-party request on the merchant's page.
//
// The sparkle is filled with url(#ton-grad), and an SVG id is scoped to the SHADOW ROOT it
// lives in. So EVERY root that renders a sparkle must also carry its own <linearGradient>
// def — the trigger has one root, the shell has another. A missing def is an invisible icon:
// the single most likely silent bug in this rebuild, hence one helper that always ships both.

import { GRAD_ID } from './constants.js';
import { el } from './dom.js';

const STROKE = 'fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';

export const SPARKLE_PATHS =
  '<path d="M10 1L12.5 7.5L19 10L12.5 12.5L10 19L7.5 12.5L1 10L7.5 7.5L10 1Z" />' +
  '<path d="M19 15L20 18L23 19L20 20L19 23L18 20L15 19L18 18L19 15Z" />';

export const ICON_SPARKLE =
  `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="url(#${GRAD_ID})">${SPARKLE_PATHS}</svg>`;

export const ICON_CLOSE =
  `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" ${STROKE}>` +
  '<line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>';

/**
 * The per-root gradient definition. Append this ONCE to every shadow root that renders a
 * gradient-filled icon; the stops read the root's own tokens, so the dark theme and any future
 * skin change flow through for free.
 */
export function gradientDefs() {
  return el('div', {
    class: 'ton-grad-defs',
    attrs: { 'aria-hidden': 'true' },
    html:
      '<svg width="0" height="0" focusable="false">' +
      `<linearGradient id="${GRAD_ID}" x1="0%" y1="0%" x2="100%" y2="100%">` +
      '<stop offset="0%" stop-color="var(--ton-grad-1)" />' +
      '<stop offset="50%" stop-color="var(--ton-grad-2)" />' +
      '<stop offset="100%" stop-color="var(--ton-grad-3)" />' +
      '</linearGradient></svg>',
  });
}
