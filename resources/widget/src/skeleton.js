// === CONSTANTS ===
// What the shopper sees when the modal chunk is genuinely slow (§6.3).
//
// Under CHUNK_SHELL_DELAY_MS nothing appears at all: the TRIGGER itself is the spinner, the
// feedback is exactly where the finger already is, and it costs zero CLS. We deliberately do
// NOT flash an empty modal for 200ms — a skeleton that appears and is replaced within a quarter
// of a second reads as a glitch, not as polish.
//
// Past the threshold the wait is real, so we acknowledge it: the same glass box the modal will
// land in opens with a shimmering block where the preview goes, and no action row.

import { el } from './dom.js';
import { panel, mount } from './panel.js';
import { clearOverlay } from './shell.js';

let open = false;

export function show(onClose) {
  if (open) return;
  open = true;
  mount(panel(el('div', { class: 'ton-skeleton' }), { onClose, skeleton: true }), onClose);
}

/** Hide the skeleton. The modal chunk replaces the overlay itself, so this is only the escape. */
export function hide() {
  if (!open) return;
  open = false;
  clearOverlay();
}

export function isOpen() {
  return open;
}

/** The modal took over the overlay — forget the skeleton without touching what is now mounted. */
export function release() {
  open = false;
}
