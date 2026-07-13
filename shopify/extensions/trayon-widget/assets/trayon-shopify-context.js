// === CONSTANTS ===
// Keeps the Tray On script tag's data-variant-id truthful as the shopper switches variants.
//
// Liquid can only stamp the variant that was selected when the page RENDERED. From then on the
// theme swaps variants client-side, so without this the widget would generate a try-on for — and
// add to cart — the wrong variant. Three signals cover every theme generation:
//
//   1. the variant input the product form submits (`[name="id"]`) — OS 2.0 themes rewrite it,
//   2. the `?variant=` search param — themes push it on every swatch click,
//   3. Shopify's own `variant:change` / `product:variant-change` events, where the theme fires them.
//
// Whatever moves first wins; the attribute is the single source of truth the widget reads, and a
// namespaced CustomEvent lets the widget react without polling. No dependencies, no globals, and
// nothing runs unless the Tray On tag is actually on the page.

(function () {
  'use strict';

  var SITE_KEY_ATTR = 'data-site-key';
  var VARIANT_ATTR = 'data-variant-id';
  var PRODUCT_ATTR = 'data-product-id';
  var VARIANT_PARAM = 'variant';
  var VARIANT_INPUT = 'form[action*="/cart/add"] [name="id"]';
  var THEME_EVENTS = ['variant:change', 'product:variant-change'];
  var CHANGE_EVENT = 'trayon:variant-change';

  var tag = document.querySelector('script[' + SITE_KEY_ATTR + ']');

  if (!tag || !tag.getAttribute(PRODUCT_ATTR)) return; // not a Tray On product page

  function publish(variantId) {
    var id = String(variantId || '').trim();

    if (!id || id === tag.getAttribute(VARIANT_ATTR)) return;

    tag.setAttribute(VARIANT_ATTR, id);
    document.dispatchEvent(new CustomEvent(CHANGE_EVENT, { detail: { variantId: id } }));
  }

  function fromUrl() {
    try {
      return new URL(window.location.href).searchParams.get(VARIANT_PARAM);
    } catch (e) {
      return null;
    }
  }

  function fromForm() {
    var input = document.querySelector(VARIANT_INPUT);

    return input ? input.value : null;
  }

  function sync() {
    publish(fromUrl() || fromForm());
  }

  // The theme rewrites the form input and/or pushes ?variant= — both are observed, so a swatch
  // click is picked up whether or not the theme emits an event.
  document.addEventListener('change', function (event) {
    if (event.target && event.target.matches && event.target.matches(VARIANT_INPUT)) sync();
  });

  THEME_EVENTS.forEach(function (name) {
    document.addEventListener(name, sync);
  });

  window.addEventListener('popstate', sync);

  // Some themes replaceState() without firing an event: one observer on the form input closes it.
  var input = document.querySelector(VARIANT_INPUT);

  if (input && window.MutationObserver) {
    new MutationObserver(sync).observe(input, { attributes: true, attributeFilter: ['value'] });
  }

  sync();
})();
