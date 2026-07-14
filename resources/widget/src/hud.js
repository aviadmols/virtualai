// === CONSTANTS ===
// The floating status HUD — the restyled `.ton-notification`. Same element, same mount, same
// cross-tab plumbing (pending.js / resume.js); a new look and three new variants.
//
// It is the shopper's ONLY tether to a generation that is running while the modal is closed —
// and on a page with no trigger at all (they navigated away mid-generation), it is the only
// Vsio surface on screen. That is exactly why it lives in the CORE bundle and is never lazy.
//
// Two rules the design earns its keep with:
//  - it is HIDDEN while the modal is open (two spinners in two places is noise);
//  - a shopper who has never generated anything NEVER sees an idle HUD (a permanent floating
//    badge with nothing to say is pollution on the merchant's page, and a support ticket).

import { HUD, HUD_CLASS } from './constants.js';
import { t } from './i18n.js';
import { el } from './dom.js';
import { getNotificationMount } from './shell.js';
import * as pending from './pending.js';

let node = null; // the live HUD element, when shown
let current = null; // { variant, opts } — what the HUD is currently saying (or would say)
let suppressed = false; // true while the modal is open

/** Show (or replace) the HUD. `opts`: { count, onClick, onDismiss }. */
export function show(variant, opts = {}) {
  current = { variant, opts };
  render();
}

/** What the HUD is currently saying (null when it has nothing to say). */
export function variant() {
  return current ? current.variant : null;
}

/** Hide the HUD while the modal is open; restore()ing brings back whatever it was saying. */
export function suppress() {
  suppressed = true;
  detach();
}

export function restore() {
  suppressed = false;
  render();
}

/** Clear the HUD entirely (it has nothing to say any more). No cross-tab broadcast. */
export function clear() {
  current = null;
  detach();
}

function detach() {
  if (node && node.parentNode) node.parentNode.removeChild(node);
  node = null;
}

function render() {
  detach();
  if (!current || suppressed) return;

  const mount = getNotificationMount();
  if (!mount) return;

  const { variant: v, opts } = current;
  const count = opts.count;

  const main = el(
    'button',
    {
      class: 'ton-notification__main',
      attrs: { type: 'button' },
      on: { click: () => opts.onClick && opts.onClick() },
    },
    [
      el('span', { class: 'ton-notification__icon', attrs: { 'aria-hidden': 'true' } }),
      el('span', { class: 'ton-notification__body' }, [
        el('span', { class: 'ton-notification__title', text: t('hud.' + v + '_title') }),
        el('span', {
          class: 'ton-notification__sub',
          text: t('hud.' + v + '_sub', count != null ? { count } : {}),
        }),
      ]),
    ],
  );

  const close = el('button', {
    class: 'ton-notification__close',
    attrs: { type: 'button', 'aria-label': t('hud.close') },
    text: '×',
    on: { click: () => onDismiss(opts) },
  });

  node = el(
    'div',
    {
      class: 'ton-notification ' + HUD_CLASS[v],
      attrs: { role: 'status', 'aria-live': 'polite' },
    },
    [main, close],
  );

  mount.appendChild(node);
}

/**
 * The shopper dismissed the HUD. A dismissal of a REAL generation state is broadcast so no
 * zombie HUD survives in another tab; a dismissed idle/unavailable HUD is local-only (there is
 * no pending entry to collapse).
 */
function onDismiss(opts) {
  const v = current && current.variant;
  clear();
  if (v !== HUD.idle && v !== HUD.unavailable) pending.dismiss();
  if (opts && opts.onDismiss) opts.onDismiss();
}

export { HUD };
