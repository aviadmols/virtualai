// === CONSTANTS ===
// The Shadow-DOM shell. One open shadow root hosts every widget surface so host CSS can't
// bleed in and the widget's CSS can't bleed out. The per-site appearance config (button
// bg/text, popup accent, theme) is applied as CSS CUSTOM PROPERTIES on the root — these
// are legitimately dynamic per-site values, NOT hardcoded literals; the styles read them
// via var(--ton-*). The document `dir` is inherited so HE renders RTL.

import widgetCss from '../styles/widget.css';
import { APPEARANCE, POPUP_THEME } from './constants.js';
import { dir } from './i18n.js';
import { el } from './dom.js';

const HOST_ID = 'trayon-host';
const ROOT_CLASS = 'ton-root';

let hostEl = null;
let shadow = null;
let rootEl = null; // the themed root inside the shadow (holds [data-theme] + [dir])
let overlayMount = null; // where the modal/result overlay lives
let buttonMount = null; // where the injected button lives (moved into the host DOM)

/** Create the shadow host + root once. Idempotent (returns the existing shell). */
export function create(appearance) {
  if (shadow) return { shadow, rootEl, overlayMount };

  hostEl = el('div', { attrs: { id: HOST_ID } });
  // The host wrapper itself must not participate in host layout (zero CLS).
  hostEl.style.setProperty('all', 'initial');
  document.body.appendChild(hostEl);

  shadow = hostEl.attachShadow({ mode: 'open' });

  const style = document.createElement('style');
  style.textContent = widgetCss;
  shadow.appendChild(style);

  rootEl = el('div', { class: ROOT_CLASS });
  applyAppearance(appearance);
  shadow.appendChild(rootEl);

  // The overlay mount: modals render here, on top of everything.
  overlayMount = el('div', { class: 'ton-overlay-mount' });
  rootEl.appendChild(overlayMount);

  // The button mount: created in the shadow, then RELOCATED into the host DOM by button.js
  // so it sits under add-to-cart. Its styles still resolve because button.js gives it a
  // self-contained class + the CSS vars are inlined onto it at relocation time.
  buttonMount = el('span', { class: 'ton-button-host' });

  return { shadow, rootEl, overlayMount };
}

/** Apply the per-site appearance config as CSS custom properties + theme/dir attributes. */
export function applyAppearance(appearance = {}) {
  if (!rootEl) return;

  rootEl.setAttribute('dir', dir());

  const theme = appearance[APPEARANCE.popupTheme] === POPUP_THEME.dark
    ? POPUP_THEME.dark
    : POPUP_THEME.light;
  rootEl.setAttribute('data-theme', theme);

  // Dynamic, per-site values -> CSS vars (not hardcoded literals).
  setVar('--ton-button-bg', appearance[APPEARANCE.buttonBg]);
  setVar('--ton-button-text', appearance[APPEARANCE.buttonText]);
  setVar('--ton-accent', appearance[APPEARANCE.popupAccent]);
}

function setVar(name, value) {
  if (value) rootEl.style.setProperty(name, value);
}

export function getOverlayMount() {
  return overlayMount;
}

export function getButtonMount() {
  return buttonMount;
}

/** Tear the whole shell down (SPA navigation away from a PDP). Leaves no orphan nodes. */
export function destroy() {
  if (hostEl && hostEl.parentNode) hostEl.parentNode.removeChild(hostEl);
  hostEl = null;
  shadow = null;
  rootEl = null;
  overlayMount = null;
  buttonMount = null;
}
