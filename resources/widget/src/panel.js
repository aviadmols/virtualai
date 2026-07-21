// === CONSTANTS ===
// The modal SHELL: the scrim, the glass box, the close affordance and the brand header. It is
// CORE — not because the modal is core, but because the skeleton that answers a tap while the
// modal chunk is still in flight has to be able to draw it (§6.3). The modal chunk reuses these
// exact builders through the kernel, so the box the shopper sees at t=250ms is the very same box
// the real content lands in: no second modal, no flash, no re-layout.

import { OVERLAY_CLOSE_MS } from './constants.js';
import { state } from './state.js';
import { t } from './i18n.js';
import { el } from './dom.js';
import { getOverlayMount, clearOverlay, logoUrl } from './shell.js';
import { ICON_SPARKLE, ICON_CLOSE } from './icons.js';

let closingTimer = null;

/**
 * The brand header: the wordmark + the product title with its LAST whitespace-delimited token
 * filled by the brand gradient. A one-word title is entirely gradient. The title is inserted as
 * TEXT, never as HTML — it is merchant data. A missing title renders the wordmark alone, never
 * an empty heading or a dangling gradient span.
 */
export function brandHeader() {
  // The Vsio wordmark logo when the origin is known; otherwise the sparkle + text fallback (so a
  // pre-boot skeleton, or an unknown base, never renders a broken image).
  const logo = logoUrl();
  const mark = logo
    ? el('img', { class: 'ton-brand__logo', attrs: { src: logo, alt: t('brand.wordmark') } })
    : el('div', { class: 'ton-brand__mark' }, [
      el('span', { class: 'ton-brand__icon', html: ICON_SPARKLE, attrs: { 'aria-hidden': 'true' } }),
      t('brand.wordmark'),
    ]);

  const children = [mark];

  const title = (state.product && state.product.name ? String(state.product.name) : '').trim();
  if (title) {
    const parts = title.split(/\s+/);
    const last = parts.pop();
    const heading = el('h2', { class: 'ton-brand__title' });
    if (parts.length) heading.appendChild(document.createTextNode(parts.join(' ') + ' '));
    heading.appendChild(el('span', { class: 'ton-brand__accent', text: last }));
    children.push(heading);
  }

  return el('div', { class: 'ton-brand' }, children);
}

/** The glass box: close (top-inline-end), an optional chip (top-inline-start), the brand header. */
export function panel(body, { onClose, chip, skeleton } = {}) {
  // The body is the ONE flex-growing column inside the modal. Without this class the preview
  // cannot shrink and Add to Cart scrolls off-screen on short viewports.
  if (body && body.classList) body.classList.add('ton-modal__body');

  return el(
    'div',
    {
      class: 'ton-modal' + (skeleton ? ' ton-modal--skeleton' : ''),
      attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': t('brand.wordmark') },
    },
    [
      el('button', {
        class: 'ton-modal__close',
        attrs: { type: 'button', 'aria-label': t('hud.close') },
        html: ICON_CLOSE,
        on: { click: () => onClose && onClose() },
      }),
      chip || null,
      brandHeader(),
      body,
    ],
  );
}

function cancelClosing() {
  if (closingTimer == null) return;
  clearTimeout(closingTimer);
  closingTimer = null;
}

/** Mount a panel in the shared overlay. Replaces whatever was there (skeleton or prior screen). */
export function mount(panelEl, onClose) {
  cancelClosing();
  clearOverlay();
  const overlay = el(
    'div',
    {
      class: 'ton-overlay',
      on: {
        click: (e) => {
          if (e.target === overlay && onClose) onClose();
        },
      },
    },
    [panelEl],
  );
  const host = getOverlayMount();
  if (host) host.appendChild(overlay);
  return overlay;
}

/**
 * Fade the overlay out (reference: 250ms), then remove it. Instant under prefers-reduced-motion.
 * A subsequent mount() cancels a pending close so screen swaps stay snappy.
 */
export function dismiss() {
  cancelClosing();

  const host = getOverlayMount();
  const overlay = host && host.querySelector('.ton-overlay');
  if (!overlay) {
    clearOverlay();
    return;
  }

  const reduce =
    typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (reduce) {
    clearOverlay();
    return;
  }

  overlay.classList.add('ton-overlay--closing');
  closingTimer = setTimeout(() => {
    closingTimer = null;
    clearOverlay();
  }, OVERLAY_CLOSE_MS);
}
