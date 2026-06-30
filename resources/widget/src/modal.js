// === CONSTANTS ===
// The modal: photo upload (validate + downscale) -> height + optional details -> explicit
// consent (submit disabled until checked) -> submit -> loading -> result. Also renders the
// signup, out-of-credits, and rate-limited typed states. Lazy-loaded on first button click
// so it stays out of the entry bundle. Rendered inside the shared Shadow overlay mount;
// structural classes only, dynamic per-site values via CSS vars. Object URLs are revoked.

import { STEP, HEIGHT_MIN_CM, HEIGHT_MAX_CM, APPEARANCE } from './constants.js';
import { state, newIntent } from './state.js';
import { t, tries } from './i18n.js';
import { el, warn } from './dom.js';
import { getOverlayMount } from './shell.js';
import { prepare, ImageError } from './image.js';
import { submit, cancelPoll, OUTCOME } from './generation.js';
import * as cart from './cart.js';
import * as api from './api.js';

let overlay = null;
let objectUrl = null;

// The working draft for the current intent (kept across step changes / retries).
let draft = { photo: null, height: '', extra: {}, consent: false };

/** Does this site's popup ask for the shopper's height? (Off for jewelry/furniture/etc.) */
function asksHeight() {
  return state.config?.appearance?.[APPEARANCE.askHeight] !== false;
}

/** Open the modal. Captures the variant the shopper has RIGHT NOW (carried through the flow). */
export function open() {
  newIntent();
  draft = { photo: null, height: '', extra: {}, consent: false };
  renderForm();
}

export function close() {
  cancelPoll();
  revokePreview();
  if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
  overlay = null;
}

function mount(panel) {
  if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
  overlay = el(
    'div',
    {
      class: 'ton-overlay',
      on: {
        click: (e) => {
          if (e.target === overlay) close();
        },
      },
    },
    [panel],
  );
  getOverlayMount().appendChild(overlay);
}

/** A modal panel scaffold with a title + close button. */
function panel(titleKey, body, chip) {
  return el('div', { class: 'ton-modal', attrs: { role: 'dialog', 'aria-modal': 'true' } }, [
    el('div', { class: 'ton-modal__head' }, [
      el('h2', { class: 'ton-modal__title', text: t(titleKey) }),
      el('button', {
        class: 'ton-modal__close',
        attrs: { type: 'button', 'aria-label': t('modal.close') },
        text: '×',
        on: { click: close },
      }),
    ]),
    chip || null,
    body,
  ]);
}

// ---------------------------------------------------------------------------
// Step 1-3 — the single-screen form (photo + height + details + consent).
// ---------------------------------------------------------------------------
function renderForm() {
  const errorBox = el('div', { class: 'ton-error', attrs: { hidden: true } });

  const chip = triesChip();

  const upload = buildUpload(errorBox);
  const height = asksHeight() ? buildHeight() : null;
  const details = buildDetails();
  const consent = buildConsent();

  const cta = el('button', {
    class: 'ton-cta',
    attrs: { type: 'button', disabled: true },
    text: t('cta.generate'),
    on: { click: () => onSubmit(cta, errorBox) },
  });

  // Re-evaluate the CTA enabled/label whenever an input changes.
  const refresh = () => updateCta(cta);
  upload.addEventListener('ton:change', refresh);
  if (height) {
    height.querySelector('.ton-input').addEventListener('input', () => {
      draft.height = height.querySelector('.ton-input').value;
      refresh();
    });
  }
  consent.querySelector('.ton-consent__box').addEventListener('change', (e) => {
    draft.consent = e.target.checked;
    refresh();
  });

  const body = el('div', {}, [upload, height, details, consent, cta, errorBox].filter(Boolean));
  mount(panel('modal.title', body, chip));
  updateCta(cta);
}

function triesChip() {
  const lead = state.lead;
  if (!lead || lead.registered) return null;
  const remaining = lead.freeRemaining;
  if (remaining == null) return null;
  return el('span', { class: 'ton-modal__chip', text: tries(remaining) });
}

function buildUpload(errorBox) {
  const container = el('div', { class: 'ton-field' });
  const input = el('input', {
    class: 'ton-upload__file',
    attrs: { type: 'file', accept: 'image/*', capture: 'user' },
  });

  const dropzone = el(
    'label',
    { class: 'ton-upload' },
    [
      el('span', { class: 'ton-upload__prompt', text: t('upload.prompt') }),
      el('span', { class: 'ton-upload__hint', text: t('upload.hint') }),
      input,
    ],
  );

  const preview = el('div', { class: 'ton-preview', attrs: { hidden: true } });

  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    if (!file) return;
    hideError(errorBox);
    try {
      draft.photo = await prepare(file);
      revokePreview();
      showPreview(preview, dropzone, draft.photo);
      container.dispatchEvent(new CustomEvent('ton:change'));
    } catch (e) {
      const code = e instanceof ImageError ? e.code : 'failed';
      showError(errorBox, t('upload.errors.' + code));
    }
  });

  container.appendChild(dropzone);
  container.appendChild(preview);
  return container;
}

function showPreview(preview, dropzone, dataUrl) {
  preview.innerHTML = '';
  const img = el('img', { class: 'ton-preview__img', attrs: { src: dataUrl, alt: '' } });
  const replace = el('button', {
    class: 'ton-preview__replace',
    attrs: { type: 'button' },
    text: t('upload.replace'),
    on: {
      click: () => {
        preview.setAttribute('hidden', '');
        dropzone.removeAttribute('hidden');
        draft.photo = null;
      },
    },
  });
  preview.appendChild(img);
  preview.appendChild(replace);
  preview.removeAttribute('hidden');
  dropzone.setAttribute('hidden', '');
}

function buildHeight() {
  const field = el('div', { class: 'ton-field' });
  field.appendChild(el('label', { class: 'ton-label', text: t('height.label') }));
  const input = el('input', {
    class: 'ton-input',
    attrs: {
      type: 'number',
      inputmode: 'numeric',
      min: HEIGHT_MIN_CM,
      max: HEIGHT_MAX_CM,
      placeholder: t('height.unit_cm'),
      value: draft.height,
    },
  });
  field.appendChild(input);
  return field;
}

function buildDetails() {
  const wrap = el('div', { class: 'ton-field' });
  const select = (key, opts) => {
    const f = el('div', { class: 'ton-field' });
    f.appendChild(el('label', { class: 'ton-label', text: t('details.' + key) }));
    const s = el('select', { class: 'ton-select' }, [
      el('option', { attrs: { value: '' }, text: '—' }),
      ...opts.map((o) => el('option', { attrs: { value: o }, text: o })),
    ]);
    s.addEventListener('change', () => {
      if (s.value) draft.extra[key] = s.value;
      else delete draft.extra[key];
    });
    f.appendChild(s);
    return f;
  };

  const details = el('div', { attrs: { hidden: true } }, [
    select('body', ['slim', 'average', 'athletic', 'curvy', 'plus']),
    select('age', ['18-24', '25-34', '35-44', '45-54', '55+']),
    select('gender', ['female', 'male', 'unspecified']),
    select('angle', ['front', 'three-quarter', 'side']),
  ]);

  const toggle = el('button', {
    class: 'ton-preview__replace',
    attrs: { type: 'button' },
    text: t('details.toggle'),
    on: {
      click: () => {
        if (details.hasAttribute('hidden')) details.removeAttribute('hidden');
        else details.setAttribute('hidden', '');
      },
    },
  });

  wrap.appendChild(toggle);
  wrap.appendChild(details);
  return wrap;
}

function buildConsent() {
  const wrap = el('div', {});
  const row = el('label', { class: 'ton-consent' }, [
    el('input', { class: 'ton-consent__box', attrs: { type: 'checkbox' } }),
    el('span', { text: t('consent.photo') }),
  ]);
  wrap.appendChild(row);

  const privacyUrl = state.config?.privacy?.url || state.config?.privacy?.privacy_url;
  if (privacyUrl) {
    wrap.appendChild(
      el('a', {
        class: 'ton-consent__link',
        attrs: { href: privacyUrl, target: '_blank', rel: 'noopener' },
        text: t('consent.privacy_link'),
      }),
    );
  }
  return wrap;
}

/** Enable submit only when photo + valid height (if asked) + consent are all present. */
function updateCta(cta) {
  const heightOk =
    !asksHeight() ||
    (Number(draft.height) >= HEIGHT_MIN_CM && Number(draft.height) <= HEIGHT_MAX_CM);

  if (!draft.photo) {
    cta.disabled = true;
    cta.textContent = t('cta.need_photo');
  } else if (!heightOk) {
    cta.disabled = true;
    cta.textContent = t('cta.need_height');
  } else if (!draft.consent) {
    cta.disabled = true;
    cta.textContent = t('cta.need_consent');
  } else {
    cta.disabled = false;
    cta.textContent = t('cta.generate');
  }
}

// ---------------------------------------------------------------------------
// Submit -> loading -> typed outcome.
// ---------------------------------------------------------------------------
async function onSubmit(cta, errorBox) {
  hideError(errorBox);
  cta.disabled = true; // UI double-submit guard (server is the real guard)
  renderLoading();

  const out = await submit({ photo: draft.photo, height: asksHeight() ? Number(draft.height) : null, extra: draft.extra });

  switch (out.outcome) {
    case OUTCOME.succeeded:
      state.lastResult = { generationId: out.generationId, resultUrl: out.resultUrl, variant: state.variant };
      if (out.freeRemaining != null && state.lead) state.lead.freeRemaining = out.freeRemaining;
      renderResult(out.resultUrl);
      break;
    case OUTCOME.signupRequired:
      renderSignup();
      break;
    case OUTCOME.postSignupLimit:
      renderState('state.limit_reached', 'state.limit_reached_body');
      break;
    case OUTCOME.outOfCredits:
      renderState('state.out_of_credits', 'unavailable.body');
      break;
    case OUTCOME.rateLimited:
      renderState('state.rate_limited', null);
      break;
    case OUTCOME.timeout:
      renderError('loading.timeout');
      break;
    case OUTCOME.network:
      renderError('errors.network');
      break;
    default:
      renderError('result.error');
  }
}

function renderLoading() {
  const body = el('div', { class: 'ton-center' }, [
    el('div', { class: 'ton-spinner' }),
    el('div', { class: 'ton-modal__title', text: t('loading.title') }),
    el('div', { class: 'ton-upload__hint', text: t('loading.sub') }),
  ]);
  mount(panel('modal.title', body));
}

// ---------------------------------------------------------------------------
// Result screen.
// ---------------------------------------------------------------------------
function renderResult(resultUrl) {
  const frame = el('div', { class: 'ton-result__frame' }, [
    el('img', { class: 'ton-result__img', attrs: { src: resultUrl, alt: t('result.title') } }),
  ]);

  const toast = el('div', { class: 'ton-toast', attrs: { hidden: true } });

  const actions = el('div', { class: 'ton-actions' }, [
    el('button', {
      class: 'ton-action ton-action--primary',
      attrs: { type: 'button' },
      text: t('result.add_to_cart'),
      on: { click: () => onAddToCart(toast) },
    }),
    el('button', {
      class: 'ton-action',
      attrs: { type: 'button' },
      text: t('result.regenerate'),
      on: { click: onRegenerate },
    }),
    el('button', {
      class: 'ton-action',
      attrs: { type: 'button' },
      text: t('result.change_photo'),
      on: { click: onChangePhoto },
    }),
    el('button', {
      class: 'ton-action',
      attrs: { type: 'button' },
      text: t('result.back'),
      on: { click: close },
    }),
  ]);

  const body = el('div', {}, [
    el('div', { class: 'ton-modal__title', text: t('result.title') }),
    frame,
    actions,
    toast,
  ]);
  mount(panel('modal.title', body));
}

async function onAddToCart(toast) {
  const ok = await cart.add(
    state.lastResult.variant,
    state.config.selectors,
    state.anonToken,
    state.lastResult.generationId,
  );
  toast.textContent = ok ? t('cart.added') : t('cart.error');
  toast.removeAttribute('hidden');
}

function onRegenerate() {
  // A NEW intent: new client_request_id, same inputs.
  newIntent();
  renderLoading();
  onSubmitDirect();
}

async function onSubmitDirect() {
  const out = await submit({ photo: draft.photo, height: asksHeight() ? Number(draft.height) : null, extra: draft.extra });
  if (out.outcome === OUTCOME.succeeded) {
    state.lastResult = { generationId: out.generationId, resultUrl: out.resultUrl, variant: state.variant };
    renderResult(out.resultUrl);
  } else if (out.outcome === OUTCOME.outOfCredits) {
    renderState('state.out_of_credits', 'unavailable.body');
  } else if (out.outcome === OUTCOME.postSignupLimit || out.outcome === OUTCOME.signupRequired) {
    // We JUST registered — never re-show the signup form (that is the infinite loop).
    // A wall here means the post-signup cap is reached: show a terminal message.
    renderState('state.limit_reached', 'state.limit_reached_body');
  } else {
    renderError('result.error');
  }
}

function onChangePhoto() {
  // A NEW intent: keep height/details, drop the photo, reopen the form.
  newIntent();
  draft.photo = null;
  draft.consent = false;
  renderForm();
}

// ---------------------------------------------------------------------------
// Signup (re-opens the lead gate) + generic typed states.
// ---------------------------------------------------------------------------
function renderSignup() {
  const errorBox = el('div', { class: 'ton-error', attrs: { hidden: true } });

  const name = textField('signup.name', 'text');
  const email = textField('signup.email', 'email');
  const phone = textField('signup.phone_optional', 'tel');

  const cta = el('button', {
    class: 'ton-cta',
    attrs: { type: 'button' },
    text: t('signup.submit'),
    on: { click: () => onSignup(name, email, phone, cta, errorBox) },
  });

  const body = el('div', {}, [
    el('div', { class: 'ton-modal__title', text: t('tries.exhausted_title') }),
    el('div', { class: 'ton-upload__hint', text: t('signup.why') }),
    name.field,
    email.field,
    phone.field,
    cta,
    errorBox,
  ]);
  mount(panel('signup.title', body));
}

function textField(labelKey, type) {
  const field = el('div', { class: 'ton-field' });
  field.appendChild(el('label', { class: 'ton-label', text: t(labelKey) }));
  const input = el('input', { class: 'ton-input', attrs: { type } });
  field.appendChild(input);
  return { field, input };
}

async function onSignup(name, email, phone, cta, errorBox) {
  hideError(errorBox);
  cta.disabled = true;

  const res = await api.createLead({
    fullName: name.input.value.trim(),
    email: email.input.value.trim(),
    phone: phone.input.value.trim(),
    anonToken: state.anonToken,
  });

  if (res.ok && res.data?.ok) {
    state.lead = {
      registered: true,
      freeRemaining: res.data.allowance?.free_remaining ?? 0,
      signupRequired: res.data.allowance?.signup_required ?? false,
    };
    // Resume the pending try-on with the re-opened gate.
    newIntent();
    renderLoading();
    onSubmitDirect();
  } else {
    cta.disabled = false;
    showError(errorBox, t('signup.errors.network'));
  }
}

function renderState(titleKey, bodyKey) {
  const body = el('div', { class: 'ton-center' }, [
    el('div', { class: 'ton-modal__title', text: t(titleKey) }),
    bodyKey ? el('div', { class: 'ton-upload__hint', text: t(bodyKey) }) : null,
    el('button', {
      class: 'ton-action',
      attrs: { type: 'button' },
      text: t('modal.close'),
      on: { click: close },
    }),
  ]);
  mount(panel('modal.title', body));
}

function renderError(messageKey) {
  const body = el('div', { class: 'ton-center' }, [
    el('div', { class: 'ton-modal__title', text: t('result.error') }),
    el('div', { class: 'ton-upload__hint', text: t(messageKey) }),
    el('button', {
      class: 'ton-action ton-action--primary',
      attrs: { type: 'button' },
      text: t('result.regenerate'),
      on: { click: onChangePhoto },
    }),
  ]);
  mount(panel('modal.title', body));
}

// ---------------------------------------------------------------------------
// Small helpers.
// ---------------------------------------------------------------------------
function showError(box, message) {
  box.textContent = message;
  box.removeAttribute('hidden');
}

function hideError(box) {
  box.textContent = '';
  box.setAttribute('hidden', '');
}

function revokePreview() {
  if (objectUrl) {
    try {
      URL.revokeObjectURL(objectUrl);
    } catch {
      warn('failed to revoke object url');
    }
    objectUrl = null;
  }
}
