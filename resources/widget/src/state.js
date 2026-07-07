// === CONSTANTS ===
// The single module-scoped widget state object. Everything the modal/result/cart read
// lives here so the SELECTED VARIANT (and the per-intent client_request_id) are the one
// source of truth, re-read live. No host-window pollution: this never leaves the module.

export const state = {
  // Boot config (from the bootstrap response).
  config: null, // { appearance, selectors, locale, product, lead, gallerySettings, privacy }
  product: null, // the confirmed product + variants for this PDP
  anonToken: null,

  // Live PDP signal: the currently selected variant as the shopper sees it.
  variant: null, // { id, key, label, options }

  // Lead state (free tries / signup), kept fresh after a signup.
  lead: null, // { registered, freeRemaining, signupRequired }

  // Customer Club config from the bootstrap (site-wide; PDP or not).
  club: null, // { enabled, discount_percent, price_zones{pdp,catalog,cart}, member{verified} }

  // Merchant banners from the bootstrap (site-wide) + the store locale (banner locale targeting).
  banners: null, // [{ id, composition, image_url, target_url, alt, overlay, placements, rules }]
  locale: null, // the store locale ('en'|'he') — from the bootstrap

  // Per-intent generation state.
  clientRequestId: null,
  submitting: false,
  lastResult: null, // { generationId, resultUrl, variant }
};

/** Reset only the per-intent fields (a NEW intent: open / regenerate / change photo). */
export function newIntent() {
  state.clientRequestId = null;
  state.submitting = false;
}
