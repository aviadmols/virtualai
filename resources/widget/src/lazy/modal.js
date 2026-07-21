// === CONSTANTS ===
// The modal — the whole shopper-facing flow, lazily fetched on the first interaction:
// setup (photo -> height -> optional details -> CONSENT) -> loading -> result
// (regenerate / Save Look / Share / Add to Cart) -> and the typed states the server can return.
//
// Three rules this file will not bend on:
//  1. CONSENT. The mock has no consent box and no height field. That was never an option: we
//     process a photo of the shopper's BODY. The CTA is disabled until the box is checked
//     (TS-PRIVACY-001), and the copy names the photo, the purpose and the product.
//  2. A THUMB CLICK ONLY VIEWS. In the mock, clicking a thumbnail starts a generation. Here the
//     strip is the shopper's past looks, and a tap that silently spends a free try or a merchant
//     credit — with no consent in that path — is a money-safety AND a consent violation.
//  3. ONE THING AT A TIME. While a look is generating the strip and the action row are disabled.
//     A new result must never yank the view out from under a shopper browsing an old one.
//
// The modal never renders a balance, a cost, a markup or a model id — only the TYPED states the
// server returns.

import {
  HEIGHT_MIN_CM,
  HEIGHT_MAX_CM,
  APPEARANCE,
  GALLERY_LIMIT,
  CART_OUTCOME,
  TOAST_MS,
  HUD,
} from '../constants.js';
import { el, warn } from '../dom.js';
import { prepare, ImageError } from '../image.js';
import { state, newIntent, panel, button, hud, pending, gen, t, tries } from './bridge.js';
import { ICON_UPLOAD, ICON_SAVE, ICON_SAVED, ICON_SHARE, ICON_REGEN } from './icons.js';
import * as cart from './cart.js';
import { share, SHARE_OUTCOME } from './share.js';
import { api } from './bridge.js';

let isOpen = false;
let galleryItems = []; // the shopper's past looks (fetched once per open)
let activeThumb = null; // the generation id currently being viewed from the strip

// The working draft for the current intent (kept across step changes / retries).
let draft = { photo: null, height: '', extra: {}, consent: false, styleId: null };

/** Does this site's popup ask for the shopper's height? Off by default. */
function asksHeight() {
  return state.config?.appearance?.[APPEARANCE.askHeight] === true;
}

/** Does this site's popup show the use-my-photo checkbox? Off by default. */
function asksConsent() {
  return state.config?.appearance?.[APPEARANCE.askConsent] === true;
}

// ---------------------------------------------------------------------------
// Open / close.
// ---------------------------------------------------------------------------

/** Open the modal on whatever the shopper's situation actually is. */
export function open() {
  isOpen = true;
  hud.suppress(); // two spinners in two places is noise

  // A look is still being made -> re-attach to its progress view, never a fresh empty form.
  if (state.submitting) {
    renderLoading();
    return;
  }

  // A look finished while the modal was closed -> go straight to it.
  if (state.lastResult && state.lastResult.generationId) {
    pending.markViewed();
    void reopenToResult();
    return;
  }

  newIntent();
  // Consent is auto-granted when the merchant hid the checkbox; the API still needs consent=true.
  draft = { photo: null, height: '', extra: {}, consent: !asksConsent() };
  renderSetup();
}

export function close() {
  panel.dismiss();
  isOpen = false;
  activeThumb = null;

  // Keep polling in the BACKGROUND when a look is still in flight — the shopper can close the
  // modal and keep shopping; the HUD carries it. A non-generating close drops the poll.
  if (!state.submitting) gen.cancelPoll();

  hud.restore();

  if (state.submitting) {
    hud.show(HUD.thinking, { onClick: open });
  } else if (state.looksCount > 0) {
    // The re-entry affordance — and ONLY for a shopper who actually has looks to re-enter.
    hud.show(HUD.idle, { count: state.looksCount, onClick: open });
  } else {
    hud.clear();
  }
}

function mount(body, chip) {
  panel.mount(panel.panel(body, { onClose: close, chip }), close);
}

// ---------------------------------------------------------------------------
// Setup: preview/dropzone -> strip -> height -> details -> consent -> the gradient CTA.
// ---------------------------------------------------------------------------
function renderSetup() {
  const errorBox = el('div', { class: 'ton-error', attrs: { hidden: true } });

  const preview = el('div', { class: 'ton-preview' });
  const fileInput = el('input', {
    class: 'ton-upload__file',
    attrs: { type: 'file', accept: 'image/*', capture: 'user' },
  });

  const cta = el('button', {
    class: 'ton-cta',
    attrs: { type: 'button', disabled: true },
    text: t('cta.generate'),
    on: { click: () => onSubmit(cta, errorBox) },
  });

  const refresh = () => updateCta(cta);

  renderDropzone(preview, fileInput, errorBox, refresh);

  const strip = el('div', { class: 'ton-gallery' });
  const styles = buildStyles();
  const height = asksHeight() ? buildHeight(refresh) : null;
  const details = buildDetails();
  const consent = asksConsent() ? buildConsent(refresh) : null;

  const body = el('div', {}, [preview, strip, styles, height, details, consent, cta, errorBox].filter(Boolean));
  mount(body, triesChip());
  updateCta(cta);

  void loadStrip(strip, { onUpload: () => fileInput.click() });
}

/** The empty state IS the dropzone — there is no separate "no try-ons yet" panel. */
function renderDropzone(preview, fileInput, errorBox, refresh) {
  preview.innerHTML = '';

  const dropzone = el('label', { class: 'ton-upload' }, [
    el('span', { class: 'ton-upload__ring', html: ICON_UPLOAD, attrs: { 'aria-hidden': 'true' } }),
    el('span', { class: 'ton-upload__prompt', text: t('upload.prompt') }),
    el('span', { class: 'ton-upload__hint', text: t('upload.hint') }),
    fileInput,
  ]);

  fileInput.addEventListener('change', async () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    hideError(errorBox);
    try {
      draft.photo = await prepare(file); // validate + downscale BEFORE upload
      renderChosenPhoto(preview, fileInput, errorBox, refresh);
      refresh();
    } catch (e) {
      const code = e instanceof ImageError ? e.code : 'failed';
      showError(errorBox, t('upload.errors.' + code));
    }
  });

  preview.appendChild(dropzone);
}

/** The shopper's own photo. No disclaimer overlay — it is not a generated image. */
function renderChosenPhoto(preview, fileInput, errorBox, refresh) {
  preview.innerHTML = '';
  preview.appendChild(el('img', { class: 'ton-preview__img', attrs: { src: draft.photo, alt: '' } }));
  preview.appendChild(
    el('button', {
      class: 'ton-preview__replace',
      attrs: { type: 'button' },
      text: t('upload.replace'),
      on: {
        click: () => {
          draft.photo = null;
          renderDropzone(preview, fileInput, errorBox, refresh);
          refresh();
        },
      },
    }),
  );
}

function buildHeight(refresh) {
  const field = el('div', { class: 'ton-field' });
  field.appendChild(el('label', { class: 'ton-label', text: t('height.label') + ' (' + t('height.unit_cm') + ')' }));
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
  input.addEventListener('input', () => {
    draft.height = input.value;
    const valid = !draft.height || heightOk();
    input.classList.toggle('ton-input--invalid', !valid);
    refresh();
  });
  field.appendChild(input);
  return field;
}

/**
 * The optional STYLE slider — a horizontal row of look thumbnails (config.styles, shipped by the
 * bootstrap). Clicking one sets draft.styleId (click again to clear); the server applies that
 * style's prompt. Absent/empty styles render nothing (the default look is used).
 */
function buildStyles() {
  const styles = state.config?.styles || [];
  if (!styles.length) return null;

  const wrap = el('div', { class: 'ton-styles' });
  wrap.appendChild(el('div', { class: 'ton-styles__label', text: t('styles.label') }));

  const cards = [];
  const track = el('div', { class: 'ton-styles__track' });

  styles.forEach((style) => {
    const card = el('button', { class: 'ton-style', attrs: { type: 'button', title: style.label || '' } });
    // Before/After: when a reference (before_url) exists, stack it under the sample inside a frame
    // so the CSS cross-fades the two. Otherwise the single sample image shows on its own.
    if (style.before_url) {
      const frame = el('div', { class: 'ton-style__frame' }, [
        el('img', { class: 'ton-style__img ton-style__img--before', attrs: { src: style.before_url, alt: '' } }),
        el('img', { class: 'ton-style__img ton-style__img--after', attrs: { src: style.image_url, alt: style.label || '' } }),
      ]);
      card.appendChild(frame);
    } else {
      card.appendChild(el('img', { class: 'ton-style__img', attrs: { src: style.image_url, alt: style.label || '' } }));
    }
    if (style.label) card.appendChild(el('span', { class: 'ton-style__name', text: style.label }));

    card.addEventListener('click', () => {
      const wasOn = draft.styleId === style.id;
      draft.styleId = wasOn ? null : style.id;
      cards.forEach((c) => c.classList.toggle('ton-style--on', !wasOn && c === card));
    });

    cards.push(card);
    track.appendChild(card);
  });

  wrap.appendChild(track);
  return wrap;
}

function buildDetails() {
  const wrap = el('div', {});

  const select = (key, options) => {
    const field = el('div', { class: 'ton-field' });
    field.appendChild(el('label', { class: 'ton-label', text: t('details.' + key) }));
    const node = el('select', { class: 'ton-select' }, [
      el('option', { attrs: { value: '' }, text: '—' }),
      ...options.map((option) => el('option', { attrs: { value: option }, text: option })),
    ]);
    node.addEventListener('change', () => {
      if (node.value) draft.extra[key] = node.value;
      else delete draft.extra[key];
    });
    field.appendChild(node);
    return field;
  };

  const fields = el('div', { attrs: { hidden: true } }, [
    select('body', ['slim', 'average', 'athletic', 'curvy', 'plus']),
    select('age', ['18-24', '25-34', '35-44', '45-54', '55+']),
    select('gender', ['female', 'male', 'unspecified']),
    select('angle', ['front', 'three-quarter', 'side']),
  ]);

  wrap.appendChild(
    el('button', {
      class: 'ton-details__toggle',
      attrs: { type: 'button' },
      text: t('details.toggle'),
      on: {
        click: () => {
          if (fields.hasAttribute('hidden')) fields.removeAttribute('hidden');
          else fields.setAttribute('hidden', '');
        },
      },
    }),
  );
  wrap.appendChild(fields);
  return wrap;
}

/** The consent block. Explicit, named, and it gates the CTA. Non-negotiable. */
function buildConsent(refresh) {
  const wrap = el('div', {});

  const box = el('input', { class: 'ton-consent__box', attrs: { type: 'checkbox' } });
  box.addEventListener('change', () => {
    draft.consent = box.checked;
    refresh();
  });

  wrap.appendChild(el('label', { class: 'ton-consent' }, [box, el('span', { text: t('consent.photo') })]));

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

function heightOk() {
  if (!asksHeight()) return true;
  const value = Number(draft.height);
  return value >= HEIGHT_MIN_CM && value <= HEIGHT_MAX_CM;
}

/** The disabled CTA always says WHAT IS MISSING. The shopper is never left guessing. */
function updateCta(cta) {
  if (!draft.photo) {
    cta.disabled = true;
    cta.textContent = t('cta.need_photo');
  } else if (!heightOk()) {
    cta.disabled = true;
    cta.textContent = t('cta.need_height');
  } else if (asksConsent() && !draft.consent) {
    cta.disabled = true;
    cta.textContent = t('cta.need_consent');
  } else {
    cta.disabled = false;
    cta.textContent = t('cta.generate');
  }
}

/** The free-tries chip. On the LAST try it turns warn and says what happens next. */
function triesChip() {
  const lead = state.lead;
  if (!lead || lead.registered) return null;
  const remaining = lead.freeRemaining;
  if (remaining == null) return null;

  return el('span', {
    class: 'ton-modal__chip' + (remaining <= 1 ? ' ton-modal__chip--warn' : ''),
    text: tries(remaining),
  });
}

// ---------------------------------------------------------------------------
// The strip: past looks + the Upload tile. A tap VIEWS. It never generates.
// ---------------------------------------------------------------------------
async function loadStrip(container, { onUpload, disabled = false, current = null } = {}) {
  const tile = el('button', {
    class: 'ton-strip__upload',
    attrs: { type: 'button', disabled },
    on: { click: () => onUpload && onUpload() },
  }, [
    el('span', { html: ICON_UPLOAD, attrs: { 'aria-hidden': 'true' } }),
    el('span', { text: t('upload.tile') }),
  ]);

  const strip = el('div', { class: 'ton-gallery__strip' }, [tile]);
  container.innerHTML = '';
  container.appendChild(strip);

  if (!galleryItems.length) {
    let res;
    try {
      res = await api.getGallery(state.anonToken, GALLERY_LIMIT);
    } catch {
      return; // a gallery failure must NEVER block a new look
    }
    const items = res && res.ok && res.data && Array.isArray(res.data.items) ? res.data.items : [];
    galleryItems = items.filter((item) => item && item.result_url);
  }

  for (const item of galleryItems) {
    const active = current != null && String(current) === String(item.id);
    strip.appendChild(
      el(
        'button',
        {
          class: 'ton-gallery__thumb' + (active ? ' ton-gallery__thumb--active' : ''),
          attrs: { type: 'button', 'aria-label': t('gallery.view'), disabled },
          on: { click: () => viewPastLook(item) },
        },
        [el('img', { class: 'ton-gallery__img', attrs: { src: item.result_url, alt: '', loading: 'lazy' } })],
      ),
    );
  }
}

/**
 * View a past look. It VIEWS — it never starts a paid generation.
 *
 * Add to Cart is disabled here: the gallery payload carries no variant, so we cannot claim this
 * look is of the option currently selected on the page, and a historical look must never add the
 * wrong SKU. (Escalated to laravel-backend: add product_id + variant_id to GenerationPayload and
 * this becomes a real, enabled add-to-cart.)
 */
function viewPastLook(item) {
  activeThumb = item.id;
  renderResult(item.result_url, { generationId: item.id, variant: null, historical: true });
}

// ---------------------------------------------------------------------------
// Submit -> loading -> the typed outcome.
// ---------------------------------------------------------------------------
async function onSubmit(cta, errorBox) {
  hideError(errorBox);
  cta.disabled = true; // UI double-submit guard; the server's client_request_id is the real one
  renderLoading();
  button.setBusy(true); // the trigger "thinks" until the look lands — even if the modal is closed

  const out = await gen.submit({
    photo: draft.photo,
    height: asksHeight() ? Number(draft.height) : null,
    styleId: draft.styleId,
    extra: draft.extra,
  });

  button.setBusy(false);
  cacheSuccess(out);

  // The shopper closed the modal while it generated -> the HUD carries it, on-page.
  if (!isOpen) {
    notifyOutcome(out);
    return;
  }

  renderOutcome(out);
}

function cacheSuccess(out) {
  if (out.outcome !== gen.OUTCOME.succeeded) return;
  state.lastResult = {
    generationId: out.generationId,
    resultUrl: out.resultUrl,
    variant: state.variant, // the variant captured when the shopper opened, carried through
  };
  state.looksCount += 1;
  galleryItems = []; // the strip is stale now — refetch on the next open
  if (out.freeRemaining != null && state.lead) state.lead.freeRemaining = out.freeRemaining;
}

function notifyOutcome(out) {
  const success = out.outcome === gen.OUTCOME.succeeded;
  hud.show(success ? HUD.ready : HUD.failed, { onClick: open });
}

function renderOutcome(out) {
  switch (out.outcome) {
    case gen.OUTCOME.succeeded:
      renderResult(state.lastResult.resultUrl, state.lastResult);
      break;
    case gen.OUTCOME.signupRequired:
      renderSignup({ title: 'tries.exhausted_title', why: 'tries.exhausted_body', onDone: resubmit });
      break;
    case gen.OUTCOME.postSignupLimit:
      renderState('state.limit_reached', 'state.limit_reached_body');
      break;
    case gen.OUTCOME.outOfCredits:
      // The MERCHANT is out — not the shopper. They did nothing wrong, there is nothing they can
      // do, and they are told nothing about credits, balances, money, or the merchant.
      renderState('state.out_of_credits', 'unavailable.body');
      break;
    case gen.OUTCOME.rateLimited:
      renderState('state.rate_limited', null);
      break;
    case gen.OUTCOME.photoRejected:
      renderPhotoRejected();
      break;
    case gen.OUTCOME.timeout:
      renderError('loading.timeout');
      break;
    case gen.OUTCOME.network:
      renderError('errors.network');
      break;
    default:
      renderError('result.error_body');
  }
}

/**
 * The preflight rejected the shopper's photo (Slice E). Not a failure and not a charge — the
 * shopper simply needs a clearer photo, so the ONLY affordance is "change photo" (a re-run of the
 * same photo would be rejected again). Never blamed, never billed.
 */
function renderPhotoRejected() {
  mount(
    el('div', { class: 'ton-center' }, [
      el('div', { class: 'ton-center__title', text: t('result.photo_rejected_title') }),
      el('div', { class: 'ton-upload__hint', text: t('result.photo_rejected_body') }),
      el('button', {
        class: 'ton-cta',
        attrs: { type: 'button' },
        text: t('upload.replace'),
        on: { click: onChangePhoto },
      }),
    ]),
  );
}

/** The shopper's own photo, blurred + breathing, under the loader. The strip + actions are gone. */
function renderLoading() {
  const layers = [];

  if (draft.photo) {
    layers.push(el('img', { class: 'ton-loading__img', attrs: { src: draft.photo, alt: '' } }));
    layers.push(el('div', { class: 'ton-loading__shimmer' }));
  }

  layers.push(
    el('div', { class: 'ton-loading__label' }, [
      el('div', { class: 'ton-spinner' }),
      el('div', { class: 'ton-loading__title', text: t('loading.title') }),
      el('div', { class: 'ton-upload__hint', text: t('loading.sub') }),
    ]),
  );

  mount(el('div', { class: 'ton-preview ton-loading__frame' }, layers));
}

// ---------------------------------------------------------------------------
// The result.
// ---------------------------------------------------------------------------
function renderResult(resultUrl, look) {
  const generationId = look && look.generationId;
  const variant = look && look.variant;
  const historical = !!(look && look.historical);

  const img = el('img', { class: 'ton-result__img', attrs: { src: resultUrl, alt: t('result.title') } });
  const hint = el('div', { class: 'ton-result__zoom-hint', text: t('result.zoom_hint') });

  // The disclaimer is a SIBLING of the image, never a child — so it survives the zoom transform.
  const disclaimer = el('div', { class: 'ton-result__disclaimer', text: t('result.disclaimer') });

  const regen = el('button', {
    class: 'ton-regen',
    attrs: { type: 'button', 'aria-label': t('result.regenerate'), title: t('result.regenerate') },
    html: ICON_REGEN,
    on: { click: onRegenerate },
  });

  const frame = el('div', { class: 'ton-preview ton-result__frame' }, [img, hint, disclaimer, regen]);
  enableZoom(frame, img, hint);

  const toast = el('div', { class: 'ton-toast', attrs: { hidden: true } });
  const strip = el('div', { class: 'ton-gallery' });
  const actions = buildActions({ generationId, resultUrl, variant, historical, toast });

  mount(el('div', {}, [frame, strip, actions, toast]));
  void loadStrip(strip, { onUpload: onChangePhoto, current: activeThumb || generationId });
}

function buildActions({ generationId, resultUrl, variant, historical, toast }) {
  const save = el('button', {
    class: 'ton-action',
    attrs: { type: 'button' },
    on: { click: () => onSave(save, toast) },
  }, [el('span', { html: ICON_SAVE, attrs: { 'aria-hidden': 'true' } }), el('span', { text: t('save.action') })]);

  const shareBtn = el('button', {
    class: 'ton-action',
    attrs: { type: 'button' },
    on: { click: () => onShare(shareBtn, toast, generationId, resultUrl) },
  }, [el('span', { html: ICON_SHARE, attrs: { 'aria-hidden': 'true' } }), el('span', { text: t('share.action') })]);

  const addLabel = el('span', { text: t('result.add_to_cart') });
  const addBtn = el('button', {
    class: 'ton-action ton-action--primary',
    attrs: { type: 'button', disabled: historical },
    on: { click: () => onAddToCart(addBtn, addLabel, toast, variant, generationId) },
  }, [addLabel]);

  // A look of a different option must never add the wrong SKU. Say so, plainly.
  if (historical) showToast(toast, t('cart.variant_changed'), 0);

  return el('div', { class: 'ton-actions' }, [save, shareBtn, addBtn]);
}

/**
 * Tap/click the result image to zoom (and again to reset). While zoomed, moving the pointer
 * (desktop) or dragging (mobile) pans a magnifier. A small move between press and release is a
 * pan, not a zoom toggle.
 */
function enableZoom(frame, img, hint) {
  let zoomed = false;
  let downX = 0;
  let downY = 0;
  let moved = false;

  const clamp = (v) => Math.max(0, Math.min(100, v));

  const setOrigin = (clientX, clientY) => {
    const rect = frame.getBoundingClientRect();
    img.style.transformOrigin =
      clamp(((clientX - rect.left) / rect.width) * 100) + '% ' +
      clamp(((clientY - rect.top) / rect.height) * 100) + '%';
  };

  const setZoom = (on, clientX, clientY) => {
    zoomed = on;
    frame.classList.toggle('ton-result__frame--zoomed', on);
    img.classList.toggle('ton-result__img--zoomed', on);
    if (hint) hint.hidden = on;
    if (on && clientX != null) setOrigin(clientX, clientY);
  };

  frame.addEventListener('pointerdown', (e) => {
    downX = e.clientX;
    downY = e.clientY;
    moved = false;
  });

  frame.addEventListener('pointermove', (e) => {
    if (Math.abs(e.clientX - downX) > 6 || Math.abs(e.clientY - downY) > 6) moved = true;
    if (zoomed) setOrigin(e.clientX, e.clientY);
  });

  frame.addEventListener('click', (e) => {
    // The regenerate button lives inside the frame — a tap on it is not a zoom.
    if (e.target.closest && e.target.closest('.ton-regen')) return;
    if (moved) {
      moved = false;
      return;
    }
    setZoom(!zoomed, e.clientX, e.clientY);
  });
}

// ---------------------------------------------------------------------------
// The three actions.
// ---------------------------------------------------------------------------

/** Add to cart: the EXACT variant this look was generated for. States: adding -> added / failed. */
async function onAddToCart(btn, label, toast, variant, generationId) {
  btn.disabled = true;
  btn.setAttribute('aria-busy', 'true');
  label.textContent = t('cart.adding');

  const outcome = await cart.add(variant || state.variant, generationId);

  btn.setAttribute('aria-busy', 'false');

  if (outcome === CART_OUTCOME.added || outcome === CART_OUTCOME.addedOptimistic) {
    label.textContent = t('cart.added');
    return; // stays disabled for this variant — an in-modal confirmation, no HUD needed
  }

  label.textContent = t('result.add_to_cart');
  btn.disabled = false;
  showToast(toast, outcome === CART_OUTCOME.unavailable ? t('cart.errors.unavailable') : t('cart.error'));
}

/**
 * Save Look. An anonymous shopper's looks are bound to a localStorage token that a cleared
 * browser destroys — so "save" IS the account. It opens the lead capture and creates it. For a
 * shopper who already has one, the generation is already persisted and already in their gallery:
 * we say "Saved to your looks", not "Saving…". We never claim a save the backend did not make.
 */
function onSave(btn, toast) {
  if (state.lead && state.lead.registered) {
    markSaved(btn);
    showToast(toast, t('save.already_saved'));
    return;
  }

  renderSignup({
    title: 'save.signup_title',
    why: 'save.signup_why',
    onDone: () => {
      // Back to the RESULT they were looking at — never an empty form.
      const look = state.lastResult;
      if (look && look.resultUrl) renderResult(look.resultUrl, look);
      else close();
    },
  });
}

function markSaved(btn) {
  btn.disabled = true;
  btn.innerHTML = '';
  btn.appendChild(el('span', { html: ICON_SAVED, attrs: { 'aria-hidden': 'true' } }));
  btn.appendChild(el('span', { text: t('save.saved') }));
}

async function onShare(btn, toast, generationId, resultUrl) {
  btn.disabled = true;
  btn.setAttribute('aria-busy', 'true');

  const outcome = await share(generationId, resultUrl);

  btn.disabled = false;
  btn.setAttribute('aria-busy', 'false');

  // A shared (or cancelled) OS sheet is its own feedback. Saying "shared!" after they cancelled
  // would be a lie, so we say nothing.
  if (outcome === SHARE_OUTCOME.fallback) showToast(toast, t('share.fallback_done'));
  else if (outcome === SHARE_OUTCOME.failed) showToast(toast, t('share.errors.failed'));
}

/** Regenerate: a NEW intent (new client_request_id), the same inputs. */
function onRegenerate() {
  if (!draft.photo) {
    // A past look reopened in a fresh session has no photo in memory — go back to setup.
    onChangePhoto();
    return;
  }
  newIntent();
  renderLoading();
  void resubmit();
}

/** Change photo: a NEW intent; keep height + details, drop the photo and the consent. */
function onChangePhoto() {
  newIntent();
  draft.photo = null;
  draft.consent = !asksConsent();
  activeThumb = null;
  renderSetup();
}

async function resubmit() {
  button.setBusy(true);
  const out = await gen.submit({
    photo: draft.photo,
    height: asksHeight() ? Number(draft.height) : null,
    styleId: draft.styleId,
    extra: draft.extra,
  });
  button.setBusy(false);
  cacheSuccess(out);

  if (!isOpen) {
    notifyOutcome(out);
    return;
  }

  // We JUST registered — never re-show the signup form (that is the infinite loop). A wall here
  // means the post-signup cap is reached: a terminal message, not another form.
  if (out.outcome === gen.OUTCOME.signupRequired || out.outcome === gen.OUTCOME.postSignupLimit) {
    renderState('state.limit_reached', 'state.limit_reached_body');
    return;
  }

  renderOutcome(out);
}

// ---------------------------------------------------------------------------
// Signup (the lead gate) + the typed states.
// ---------------------------------------------------------------------------
function renderSignup({ title, why, onDone }) {
  const errorBox = el('div', { class: 'ton-error', attrs: { hidden: true } });

  const name = textField('signup.name', 'text');
  const email = textField('signup.email', 'email');
  const phone = textField('signup.phone_optional', 'tel');

  const cta = el('button', {
    class: 'ton-cta',
    attrs: { type: 'button' },
    text: t('signup.submit'),
    on: { click: () => onSignup({ name, email, phone, cta, errorBox, onDone }) },
  });

  mount(
    el('div', {}, [
      el('div', { class: 'ton-center__title', text: t(title) }),
      el('div', { class: 'ton-upload__hint', text: t(why) }),
      name.field,
      email.field,
      phone.field,
      el('div', { class: 'ton-consent' }, [el('span', { text: t('signup.consent') })]),
      cta,
      errorBox,
    ]),
  );
}

function textField(labelKey, type) {
  const field = el('div', { class: 'ton-field' });
  field.appendChild(el('label', { class: 'ton-label', text: t(labelKey) }));
  const input = el('input', { class: 'ton-input', attrs: { type } });
  field.appendChild(input);
  return { field, input };
}

async function onSignup({ name, email, phone, cta, errorBox, onDone }) {
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
    newIntent();
    onDone();
    return;
  }

  cta.disabled = false;
  showError(errorBox, t('signup.errors.network'));
}

function renderState(titleKey, bodyKey) {
  mount(
    el('div', { class: 'ton-center' }, [
      el('div', { class: 'ton-center__title', text: t(titleKey) }),
      bodyKey ? el('div', { class: 'ton-upload__hint', text: t(bodyKey) }) : null,
    ]),
  );
}

/** A failure. The shopper is never billed and never blamed, and the copy says both. */
function renderError(bodyKey) {
  mount(
    el('div', { class: 'ton-center' }, [
      el('div', { class: 'ton-center__title', text: t('result.error') }),
      el('div', { class: 'ton-upload__hint', text: t(bodyKey) }),
      el('button', {
        class: 'ton-cta',
        attrs: { type: 'button' },
        text: t('result.regenerate'),
        on: { click: onRegenerate },
      }),
    ]),
  );
}

/**
 * Reopen on a finished look. The signed URL captured at completion may have passed its ~10-minute
 * TTL, so we re-fetch a fresh one before rendering.
 */
async function reopenToResult() {
  mount(
    el('div', { class: 'ton-preview ton-loading__frame' }, [
      el('div', { class: 'ton-loading__label' }, [
        el('div', { class: 'ton-spinner' }),
        el('div', { class: 'ton-loading__title', text: t('loading.title') }),
      ]),
    ]),
  );

  const look = state.lastResult;
  const url = await freshResultUrl(look);

  if (!url) {
    renderError('errors.network');
    return;
  }

  look.resultUrl = url;
  renderResult(url, look);
}

async function freshResultUrl(look) {
  try {
    const res = await api.getGeneration(look.generationId, state.anonToken);
    const fresh = res.ok && res.data?.ok ? res.data.generation?.result_url : null;
    if (fresh) return fresh;
  } catch {
    warn('could not refresh the signed result url');
  }
  return look.resultUrl;
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

/** `ms = 0` keeps the toast up (used for the persistent "different option" notice). */
function showToast(toast, message, ms = TOAST_MS) {
  toast.textContent = message;
  toast.removeAttribute('hidden');
  if (ms > 0) setTimeout(() => toast.setAttribute('hidden', ''), ms);
}
