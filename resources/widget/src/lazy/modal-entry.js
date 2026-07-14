// === CONSTANTS ===
// The MODAL chunk's entry (widget.modal.js). Fetched on the first real interaction — a tap on the
// trigger, or a tap on the HUD. It brings its own stylesheet (and the self-hosted Outfit webfont),
// its own half of the i18n catalogue, and the whole shopper flow, then registers itself with the
// core through the ONE namespaced global.
//
// It is a self-contained IIFE, not an ESM chunk: the embed snippet is a classic <script async>
// (TS-BUILD-005), so there is no dynamic import to lean on.

import modalCss from '../../styles/modal.css';
import { NAMESPACE, CHUNK, CHUNK_READY_FN, CSS_KEY } from '../constants.js';
import { shell, extendMessages } from './bridge.js';
import { MODAL_MESSAGES } from './i18n-modal.js';
import { open, close } from './modal.js';

// The lazy half of the catalogue joins the core's table; the stylesheet joins the shell's root
// (it declares no token — every value it uses was already declared by the core sheet).
extendMessages(MODAL_MESSAGES);
shell.appendCss(CSS_KEY.modal, modalCss);

window[NAMESPACE][CHUNK_READY_FN](CHUNK.modal, { open, close });
