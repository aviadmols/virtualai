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
  CLUB_TRIGGER,
  CLUB_POSITIONS,
  CLUB_POSITION_DEFAULT,
  STORAGE_CLUB_MEMBER_PREFIX,
  STORAGE_CLUB_DISMISS_PREFIX,
  TRACK_INTERACTION_CLUB_JOIN,
} from './constants.js';
import { state } from './state.js';
import { t } from './i18n.js';
import { el, warn } from './dom.js';
import { getNotificationMount, getOverlayMount } from './shell.js';
import * as api from './api.js';
import * as pricing from './pricing.js';
import * as track from './track.js';

// Time multipliers (dismissal is stored as an absolute epoch-ms expiry; the delay trigger is
// configured in whole seconds). Named so no bare magic number appears below.
const MS_PER_SECOND = 1000;
const MS_PER_DAY = 86400000; // 24 * 60 * 60 * 1000

let siteKey = '';
let clubConfig = null; // the bootstrap `club` block
let banner = null; // the floating banner element (in the notification mount)
let overlay = null; // the login modal overlay (in the overlay mount)
let draft = { email: '' }; // the working email across the two steps
let showTimer = null; // the pending delay-trigger timeout id (cleared on show/teardown)
let scrollHandler = null; // the pending scroll-trigger listener (removed on show/teardown)

/** Configure the site-scoped storage key (called before init, like pending.configure). */
export function configure(key) {
  siteKey = key || '';
}

/**
 * Initialise the club on the idle path. A no-op unless the bootstrap says the site is club-enabled.
 * If the shopper is already a verified member (bootstrap OR a persisted local flag), skip the
 * banner and go straight to member pricing. If the shopper dismissed the banner recently (and the
 * merchant asked to remember it), stay quiet. Otherwise SCHEDULE the join banner per the merchant's
 * trigger (immediately, after a delay, or once the shopper scrolls far enough).
 */
export function init(config) {
  clubConfig = config || null;
  if (!clubConfig || !clubConfig[CLUB_CONFIG.enabled]) return; // club off => nothing at all

  if (isMember()) {
    // Already a member: no banner, just the display-only member prices.
    pricing.init(clubConfig, true);
    return;
  }

  if (isDismissed()) return; // the shopper closed it recently — respect that, do not nag

  scheduleBanner();
}

/**
 * Show the banner per the merchant's configured trigger. `immediate` shows now; `delay` waits N
 * seconds; `scroll` shows once the shopper has scrolled past the configured page depth. Every path
 * is idempotent + fully torn down (no dangling timer/listener) via clearScheduled().
 */
function scheduleBanner() {
  const trigger = clubConfig[CLUB_CONFIG.bannerTrigger];

  if (trigger === CLUB_TRIGGER.delay) {
    const seconds = clampedInt(clubConfig[CLUB_CONFIG.bannerDelaySeconds]);
    showTimer = setTimeout(showBanner, seconds * MS_PER_SECOND);
    return;
  }

  if (trigger === CLUB_TRIGGER.scroll) {
    armScrollTrigger();
    return;
  }

  showBanner(); // 'immediate' (and any unknown value) => show right away
}

/** Attach a passive scroll listener that shows the banner once past the configured depth. */
function armScrollTrigger() {
  const threshold = clampedInt(clubConfig[CLUB_CONFIG.bannerScrollPercent]);

  scrollHandler = () => {
    if (scrolledPast(threshold)) showBanner(); // showBanner() clears the listener
  };

  try {
    window.addEventListener('scroll', scrollHandler, { passive: true });
  } catch {
    // addEventListener should never throw, but if it does, fall back to showing now.
    scrollHandler = null;
    showBanner();
    return;
  }

  scrollHandler(); // the page may already be scrolled past the threshold on load
}

/** True once the page is scrolled at least `percent`% of its scrollable height. */
function scrolledPast(percent) {
  const doc = document.documentElement;
  const scrollable = doc.scrollHeight - window.innerHeight;
  if (scrollable <= 0) return false; // nothing to scroll -> never force the banner open
  const scrolled = window.scrollY || doc.scrollTop || 0;
  return (scrolled / scrollable) * 100 >= percent;
}

/** Remove any pending delay timer + scroll listener (on show, on teardown). */
function clearScheduled() {
  if (showTimer) {
    clearTimeout(showTimer);
    showTimer = null;
  }
  if (scrollHandler) {
    window.removeEventListener('scroll', scrollHandler);
    scrollHandler = null;
  }
}

/** A non-negative integer from a config value (guards NaN/negative/float). */
function clampedInt(value) {
  const n = Math.floor(Number(value));
  return Number.isFinite(n) && n > 0 ? n : 0;
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
  clearScheduled(); // a delay/scroll trigger that fired -> stop any remaining timer/listener
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
    on: { click: dismissBanner },
  });

  banner = el(
    'div',
    {
      // The position value doubles as the CSS modifier suffix (bottom-end / top-start / …); the
      // modifier resolves to LOGICAL insets so the chosen corner mirrors correctly in RTL.
      class: 'ton-notification ton-notification--ready ton-club-banner ton-club-banner--' + bannerPosition(),
      attrs: { role: 'region', 'aria-label': t('club.banner_title') },
    },
    [main, close],
  );
  mount.appendChild(banner);
}

/** The merchant-chosen corner, validated against the known set (defaults to bottom-end). */
function bannerPosition() {
  const value = clubConfig[CLUB_CONFIG.bannerPosition];
  return CLUB_POSITIONS.includes(value) ? value : CLUB_POSITION_DEFAULT;
}

/** Remove the banner from the DOM (no persistence — used by verify + teardown). */
function hideBanner() {
  if (banner && banner.parentNode) banner.parentNode.removeChild(banner);
  banner = null;
}

/** The shopper closed the banner: hide it AND remember the dismissal (per the merchant's window). */
function dismissBanner() {
  hideBanner();
  persistDismissal();
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

function dismissKey() {
  return STORAGE_CLUB_DISMISS_PREFIX + siteKey;
}

/** A live (unexpired) dismissal keeps the banner hidden across reloads. Expired entries self-clean. */
function isDismissed() {
  try {
    const raw = localStorage.getItem(dismissKey());
    if (!raw) return false;
    const until = Number(raw);
    if (!Number.isFinite(until) || until <= 0) return false;
    if (Date.now() >= until) {
      localStorage.removeItem(dismissKey()); // window passed -> allow the banner again
      return false;
    }
    return true;
  } catch {
    return false;
  }
}

/**
 * Remember the dismissal for the merchant's window (banner_dismiss_days). A 0-day window is
 * session-only: nothing is persisted, so the banner reappears on the next page load.
 */
function persistDismissal() {
  const days = clampedInt(clubConfig && clubConfig[CLUB_CONFIG.bannerDismissDays]);
  if (days <= 0) return; // session-only
  try {
    localStorage.setItem(dismissKey(), String(Date.now() + days * MS_PER_DAY));
  } catch {
    /* private mode / storage disabled — dismissal holds for this session only */
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

/** Teardown (SPA navigation away): cancel any pending trigger, remove the banner + any open login
 *  modal, and drop pricing. A pending delay/scroll trigger must NOT count as a dismissal. */
export function teardown() {
  clearScheduled();
  hideBanner();
  closeLogin();
  pricing.teardown();
}
