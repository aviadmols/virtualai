// === CONSTANTS ===
// The single module-scoped widget state object. Everything the modal/result/cart read lives
// here so the SELECTED VARIANT (and the per-intent client_request_id) are the one source of
// truth, re-read live. No host-window pollution: the lazy chunks reach this exact object
// through the kernel, never through a second copy.

export const state = {
  // Boot config (from the bootstrap response).
  config: null, // { appearance, selectors, locale, privacy, gallery }
  product: null, // the confirmed product + variants for this PDP
  anonToken: null,

  // The host-platform context the <script> tag carries (the Shopify Theme App Extension stamps
  // it, and keeps `externalVariantId` truthful across every variant switch). It is what makes
  // the add-to-cart real: `variant.id` is our internal DB key, never the store's.
  platform: null, // 'shopify' | null
  hostProduct: null, // { id, handle } as the host platform knows them
  externalVariantId: null, // the LIVE numeric platform variant id (the shopper's actual selection)

  // Live PDP signal: the currently selected variant as the shopper sees it.
  variant: null, // { id, key, label, options, sku, externalId }

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

  // How many looks this shopper produced in this session. Drives the idle HUD, which must NEVER
  // appear for a shopper who has generated nothing (a floating badge with nothing to say is
  // pollution on the merchant's page).
  looksCount: 0,
};

/** Reset only the per-intent fields (a NEW intent: open / regenerate / change photo). */
export function newIntent() {
  state.clientRequestId = null;
  state.submitting = false;
}
