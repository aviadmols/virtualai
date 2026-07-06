// === CONSTANTS ===
// The Customer Club: a floating banner -> an email one-time-code login -> a verified member, then
// display-only member pricing (pricing.js). The BROWSER only ever holds the public anon_token +
// the shopper's own typed email; the OTP is issued/validated server-side (keyed on anon_token +
// email). No secret, no member PII beyond what's typed, and NO real discount code — the member
// price is a visual annotation only.
//
// Flow:
//   1. On boot (idle path, PDP or not), if club.enabled && !member.verified, show a small FLOATING
//      banner in the shared Shadow-DOM notification mount (no host CSS bleed; reuses widget styles).
//   2. Click -> a compact modal in the overlay mount: step 1 email -> request-code; step 2 the
//      6-digit code -> verify-code. The SAME anon_token + email are sent to both (the OTP is keyed
//      on both).
//   3. On verified: persist the member flag (site-scoped localStorage, like anon_token), hide the
//      banner, emit the 'club_join' track interaction, and turn on member pricing without a reload.
//
// Everything rides the loader's existing idle path — never a synchronous hook, never blocks the
// page. Typed, friendly states for throttled/invalid/expired/locked. Never throws into the host.

import {
  CLUB_CONFIG,
  CLUB_STEP,
  CLUB_CODE_LENGTH,
  CLUB_REASON,
  STORAGE_CLUB_MEMBER_PREFIX,
  TRACK_INTERACTION_CLUB_JOIN,
} from './constants.js';
import { state } from './state.js';
import { t } from './i18n.js';
import { el, warn } from './dom.js';
import { getNotificationMount, getOverlayMount } from './shell.js';
import * as api from './api.js';
import * as pricing from './pricing.js';
import * as track from './track.js';

let siteKey = '';
let clubConfig = null; // the bootstrap `club` block
let banner = null; // the floating banner element (in the notification mount)
let overlay = null; // the login modal overlay (in the overlay mount)
let draft = { email: '' }; // the working email across the two steps

/** Configure the site-scoped storage key (called before init, like pending.configure). */
export function configure(key) {
  siteKey = key || '';
}

/**
 * Initialise the club on the idle path. A no-op unless the bootstrap says the site is club-enabled.
 * If the shopper is already a verified member (bootstrap OR a persisted local flag), skip the
 * banner and go straight to member pricing. Otherwise show the floating join banner.
 */
export function init(config) {
  clubConfig = config || null;
  if (!clubConfig || !clubConfig[CLUB_CONFIG.enabled]) return; // club off => nothing at all

  if (isMember()) {
    // Already a member: no banner, just the display-only member prices.
    pricing.init(clubConfig, true);
    return;
  }

  showBanner();
}

/** Verified member = the bootstrap said so OR we persisted the flag after a prior verify. */
export function isMember() {
  const fromBootstrap = !!(clubConfig && clubConfig[CLUB_CONFIG.member] && clubConfig[CLUB_CONFIG.member].verified);
  return fromBootstrap || readMemberFlag();
}

// ---------------------------------------------------------------------------
// The floating banner (in the shared notification mount). It REUSES the existing
// `ton-notification` structural classes (the same fixed-corner floating card + accent border +
// click affordance as the "try-on ready" popup) so it inherits the premium widget skin with ZERO
// new CSS — nothing here declares a color/spacing value.
// ---------------------------------------------------------------------------
function showBanner() {
  if (banner) return; // never duplicate
  const mount = getNotificationMount();
  if (!mount) return; // no shell yet -> nothing to attach to (fail-soft)

  const percent = Number(clubConfig[CLUB_CONFIG.discountPercent]) || 0;

  const main = el(
    'button',
    {
      class: 'ton-notification__main',
      attrs: { type: 'button' },
      on: { click: openLogin },
    },
    [
      el('span', { class: 'ton-notification__icon', text: '★' }),
      el('span', { class: 'ton-notification__body' }, [
        el('span', { class: 'ton-notification__title', text: t('club.banner_title') }),
        el('span', { class: 'ton-notification__sub', text: t('club.banner_sub', { percent }) }),
      ]),
    ],
  );

  const close = el('button', {
    class: 'ton-notification__close',
    attrs: { type: 'button', 'aria-label': t('club.close') },
    text: '×',
    on: { click: hideBanner },
  });

  banner = el(
    'div',
    {
      class: 'ton-notification ton-notification--ready ton-club-banner',
      attrs: { role: 'region', 'aria-label': t('club.banner_title') },
    },
    [main, close],
  );
  mount.appendChild(banner);
}

function hideBanner() {
  if (banner && banner.parentNode) banner.parentNode.removeChild(banner);
  banner = null;
}

// ---------------------------------------------------------------------------
// The login modal (email -> code), rendered in the shared overlay mount. Reuses the modal /
// lead-form structural classes so it inherits the widget's premium skin with no new CSS.
// ---------------------------------------------------------------------------
function openLogin() {
  draft = { email: '' };
  renderStep(CLUB_STEP.email);
}

function closeLogin() {
  if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
  overlay = null;
}

/** Mount a login panel into the overlay mount (backdrop click closes it). */
function mountPanel(panelEl) {
  closeLogin();
  overlay = el(
    'div',
    {
      class: 'ton-overlay',
      on: {
        click: (e) => {
          if (e.target === overlay) closeLogin();
        },
      },
    },
    [panelEl],
  );
  const host = getOverlayMount();
  if (!host) return; // fail-soft
  host.appendChild(overlay);
}

/** A modal scaffold with a title + close button (mirrors modal.js panel()). */
function panel(titleKey, body) {
  return el('div', { class: 'ton-modal', attrs: { role: 'dialog', 'aria-modal': 'true' } }, [
    el('div', { class: 'ton-modal__head' }, [
      el('h2', { class: 'ton-modal__title', text: t(titleKey) }),
      el('button', {
        class: 'ton-modal__close',
        attrs: { type: 'button', 'aria-label': t('club.close') },
        text: '×',
        on: { click: closeLogin },
      }),
    ]),
    body,
  ]);
}

function renderStep(step) {
  if (step === CLUB_STEP.code) return renderCodeStep();
  return renderEmailStep();
}

// --- Step 1: email -> request-code ---
function renderEmailStep() {
  const errorBox = el('div', { class: 'ton-error', attrs: { hidden: true } });

  const field = el('div', { class: 'ton-field' });
  field.appendChild(el('label', { class: 'ton-label', text: t('club.email_label') }));
  const input = el('input', {
    class: 'ton-input',
    attrs: { type: 'email', inputmode: 'email', autocomplete: 'email', value: draft.email },
  });
  field.appendChild(input);

  const cta = el('button', {
    class: 'ton-cta',
    attrs: { type: 'button' },
    text: t('club.email_submit'),
    on: { click: () => onRequestCode(input, cta, errorBox) },
  });

  const body = el('div', {}, [
    el('div', { class: 'ton-upload__hint', text: t('club.email_sub') }),
    field,
    cta,
    errorBox,
  ]);
  mountPanel(panel('club.email_title', body));
}

async function onRequestCode(input, cta, errorBox) {
  hideError(errorBox);
  const email = String(input.value || '').trim();
  if (!isValidEmail(email)) {
    showError(errorBox, t('club.errors.email'));
    return;
  }

  draft.email = email;
  cta.disabled = true; // UI guard; the server throttles the real send

  let res;
  try {
    res = await api.clubRequestCode(state.anonToken, email);
  } catch {
    res = null;
  }

  cta.disabled = false;

  if (!res || !res.ok || !res.data || res.data.ok !== true) {
    showError(errorBox, t('club.errors.network'));
    return;
  }

  // Throttled: the server declined to re-send so soon. Still advance to the code step (a code from
  // an earlier request may already be in the shopper's inbox) but surface the throttle notice.
  if (res.data.code_sent === false && res.data.reason === CLUB_REASON.throttled) {
    renderCodeStep(t('club.errors.throttled'));
    return;
  }

  renderCodeStep();
}

// --- Step 2: code -> verify-code ---
function renderCodeStep(noticeMessage) {
  const errorBox = el('div', { class: 'ton-error', attrs: { hidden: true } });

  const field = el('div', { class: 'ton-field' });
  field.appendChild(el('label', { class: 'ton-label', text: t('club.code_label') }));
  const input = el('input', {
    class: 'ton-input',
    attrs: {
      type: 'text',
      inputmode: 'numeric',
      autocomplete: 'one-time-code',
      maxlength: CLUB_CODE_LENGTH,
      pattern: '\\d*',
    },
  });
  field.appendChild(input);

  const cta = el('button', {
    class: 'ton-cta',
    attrs: { type: 'button' },
    text: t('club.code_submit'),
    on: { click: () => onVerifyCode(input, cta, errorBox) },
  });

  const back = el('button', {
    class: 'ton-preview__replace',
    attrs: { type: 'button' },
    text: t('club.back'),
    on: { click: () => renderStep(CLUB_STEP.email) },
  });

  const body = el('div', {}, [
    el('div', { class: 'ton-upload__hint', text: t('club.code_sub', { email: draft.email }) }),
    field,
    cta,
    back,
    errorBox,
  ]);
  mountPanel(panel('club.code_title', body));

  if (noticeMessage) showError(errorBox, noticeMessage);
}

async function onVerifyCode(input, cta, errorBox) {
  hideError(errorBox);
  const code = String(input.value || '').replace(/\D/g, '');
  if (code.length !== CLUB_CODE_LENGTH) {
    showError(errorBox, t('club.errors.code'));
    return;
  }

  cta.disabled = true;

  let res;
  try {
    res = await api.clubVerifyCode(state.anonToken, draft.email, code);
  } catch {
    res = null;
  }

  if (!res || !res.ok || !res.data || res.data.ok !== true) {
    cta.disabled = false;
    showError(errorBox, t('club.errors.network'));
    return;
  }

  if (res.data.verified === true) {
    onVerified();
    return;
  }

  // Typed failure: invalid / expired / locked -> a friendly, specific message.
  cta.disabled = false;
  showError(errorBox, reasonMessage(res.data.reason));
}

/** Map a typed verify reason to its i18n error (defaults to the generic invalid message). */
function reasonMessage(reason) {
  if (reason === CLUB_REASON.expired) return t('club.errors.expired');
  if (reason === CLUB_REASON.locked) return t('club.errors.locked');
  return t('club.errors.invalid');
}

// ---------------------------------------------------------------------------
// Verified: persist the member flag, flip the UI, turn on member pricing — no reload.
// ---------------------------------------------------------------------------
function onVerified() {
  writeMemberFlag();
  hideBanner();

  // A meaningful behavioral signal (reuses the existing tracking queue; fire-and-forget).
  try {
    track.trackInteraction(TRACK_INTERACTION_CLUB_JOIN, draft.email ? maskEmail(draft.email) : null);
  } catch {
    /* tracking never gates anything */
  }

  // Show a brief welcome, then close and apply member pricing live.
  renderWelcome();
  try {
    pricing.init(clubConfig, true);
  } catch {
    warn('failed to apply member pricing after verify');
  }
}

function renderWelcome() {
  const body = el('div', { class: 'ton-center' }, [
    el('div', { class: 'ton-modal__title', text: t('club.welcome') }),
    el('button', {
      class: 'ton-action ton-action--primary',
      attrs: { type: 'button' },
      text: t('club.close'),
      on: { click: closeLogin },
    }),
  ]);
  mountPanel(panel('club.email_title', body));
}

// ---------------------------------------------------------------------------
// Persistence (site-scoped, like anon_token) + small helpers.
// ---------------------------------------------------------------------------
function memberKey() {
  return STORAGE_CLUB_MEMBER_PREFIX + siteKey;
}

function readMemberFlag() {
  try {
    return localStorage.getItem(memberKey()) === '1';
  } catch {
    return false;
  }
}

function writeMemberFlag() {
  try {
    localStorage.setItem(memberKey(), '1');
  } catch {
    /* private mode / storage disabled — the in-session member state still holds */
  }
}

/** A light email check (the server is the real validator). */
function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

/** Mask an email for the tracking label (no raw PII in the behavioral payload). */
function maskEmail(email) {
  const at = email.indexOf('@');
  if (at <= 1) return '***';
  return email[0] + '***' + email.slice(at);
}

function showError(box, message) {
  box.textContent = message;
  box.removeAttribute('hidden');
}

function hideError(box) {
  box.textContent = '';
  box.setAttribute('hidden', '');
}

/** Teardown (SPA navigation away): remove the banner + any open login modal, drop pricing. */
export function teardown() {
  hideBanner();
  closeLogin();
  pricing.teardown();
}
