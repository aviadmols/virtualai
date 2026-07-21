// === CONSTANTS ===
// The Shadow-DOM shell. One open shadow root hosts every widget surface so host CSS can't
// bleed in and the widget's CSS can't bleed out. The CORE stylesheet (tokens + trigger + HUD
// + modal shell + skeleton) is inlined at create; a lazy chunk APPENDS its own sheet to the
// same root when it lands — it declares no token, so nothing is ever declared twice.
//
// The per-site appearance config (button bg/text, popup theme) is applied as CSS CUSTOM
// PROPERTIES on the root — legitimately dynamic per-site values, NOT hardcoded literals.
// The active locale's `dir` is set on the root so HE mirrors through logical properties.

import coreCss from '../styles/core.css';
import { APPEARANCE, POPUP_THEME, ASSET_BASE_TOKEN, CSS_KEY } from './constants.js';
import { dir } from './i18n.js';
import { el } from './dom.js';
import { gradientDefs } from './icons.js';

const HOST_ID = 'trayon-host';
const ROOT_CLASS = 'ton-root';

let hostEl = null;
let shadow = null;
let rootEl = null; // the themed root inside the shadow (holds [data-theme] + [dir])
let overlayMount = null; // where the modal/skeleton overlay lives
let notificationMount = null; // where the floating status HUD lives
let assetBase = ''; // the absolute directory that served widget.js (fonts resolve against it)
const injected = new Set(); // stylesheet keys already inlined into this root

// The Vsio wordmark, served from the widget's OWN origin root (public/vsio-logo.svg). Resolved
// against the asset base so it points at go.vsio.app regardless of the merchant's origin.
const LOGO_PATH = '/vsio-logo.svg';

/** The absolute base URL the widget's own assets (chunks, fonts) live under. Set at boot. */
export function setAssetBase(base) {
  assetBase = base || '';
}

/** The absolute URL of the Vsio wordmark logo (widget origin). '' when the base is unknown. */
export function logoUrl() {
  if (!assetBase) return '';
  try {
    return new URL(LOGO_PATH, assetBase).href;
  } catch {
    return '';
  }
}

/**
 * A <style> inside a shadow root resolves relative url()s against the HOST DOCUMENT, not
 * against the stylesheet — so the self-hosted webfont would 404 on the merchant's origin.
 * Every sheet is rewritten to absolute asset URLs before it is inlined.
 */
export function resolveCss(css) {
  return css.split(ASSET_BASE_TOKEN).join(assetBase);
}

/** The CORE stylesheet text (the trigger root + the merchant-banner roots reuse it). */
export function getCoreCss() {
  return resolveCss(coreCss);
}

/** Create the shadow host + root once. Idempotent (returns the existing shell). */
export function create(appearance) {
  if (shadow) return { shadow, rootEl, overlayMount };

  hostEl = el('div', { attrs: { id: HOST_ID } });
  // The host wrapper itself must not participate in host layout (zero CLS).
  hostEl.style.setProperty('all', 'initial');
  document.body.appendChild(hostEl);

  shadow = hostEl.attachShadow({ mode: 'open' });

  rootEl = el('div', { class: ROOT_CLASS });
  applyAppearance(appearance);

  appendCss(CSS_KEY.core, coreCss);
  shadow.appendChild(rootEl);

  // The sparkle's gradient def is scoped to THIS root — without it the icon renders invisible.
  rootEl.appendChild(gradientDefs());

  // The overlay mount: the modal (and the pre-chunk skeleton) render here, above everything.
  overlayMount = el('div', { class: 'ton-overlay-mount' });
  rootEl.appendChild(overlayMount);

  // The HUD mount: a SIBLING of the overlay, so the floating status survives the modal being
  // torn down mid-generation. This is what carries a look the shopper started and walked away from.
  notificationMount = el('div', { class: 'ton-notification-mount' });
  rootEl.appendChild(notificationMount);

  return { shadow, rootEl, overlayMount };
}

/** Inline a stylesheet into the shell root exactly once (a lazy chunk calls this on load). */
export function appendCss(key, css) {
  if (!shadow || injected.has(key)) return;
  injected.add(key);
  const style = document.createElement('style');
  style.textContent = resolveCss(css);
  shadow.insertBefore(style, shadow.firstChild);
}

/** Apply the per-site appearance config as CSS custom properties + theme/dir attributes. */
export function applyAppearance(appearance = {}) {
  if (!rootEl) return;

  rootEl.setAttribute('dir', dir());

  const theme = appearance[APPEARANCE.popupTheme] === POPUP_THEME.dark
    ? POPUP_THEME.dark
    : POPUP_THEME.light;
  rootEl.setAttribute('data-theme', theme);

  // Dynamic, per-site values -> CSS vars (not hardcoded literals). They paint the CLASSIC
  // button only; the modal's identity (gradient, glass, radii, motion) is fixed by design.
  setVar('--ton-button-bg', appearance[APPEARANCE.buttonBg]);
  setVar('--ton-button-text', appearance[APPEARANCE.buttonText]);
}

function setVar(name, value) {
  if (value) rootEl.style.setProperty(name, value);
}

export function getOverlayMount() {
  return overlayMount;
}

export function getNotificationMount() {
  return notificationMount;
}

/** Drop whatever is currently in the overlay (the skeleton, or a previous modal screen). */
export function clearOverlay() {
  if (overlayMount) overlayMount.innerHTML = '';
}

/** Tear the whole shell down (SPA navigation away from a PDP). Leaves no orphan nodes. */
export function destroy() {
  if (hostEl && hostEl.parentNode) hostEl.parentNode.removeChild(hostEl);
  hostEl = null;
  shadow = null;
  rootEl = null;
  overlayMount = null;
  notificationMount = null;
  injected.clear();
}
