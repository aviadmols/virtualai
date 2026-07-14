# Tray On — Shopify app workspace

The Shopify CLI workspace: the app config (`shopify.app.toml`) and the **theme app
extension** that puts the Tray On button on a Shopify PDP without the merchant editing
theme code.

The Laravel app is the backend: OAuth, webhooks, product sync and image push all live in
`app/Domain/Shopify/*` and `routes/shopify.php`. Nothing here holds a secret.

## What the merchant experiences

1. Connects the store from the Tray On merchant panel (**Shopify** screen) → Shopify's
   grant screen → back to Tray On, connected.
2. In their theme editor, turns on the **Tray On** app embed and pastes the site key
   (Sites → Embed). No `<script>` tag, no theme file edit.
3. The widget appears under *Add to cart* on every product page.

## Why an app embed block (and not a script tag)

The block stamps the **explicit product context** into the loader tag straight from Liquid:

```
data-product-id  data-product-handle  data-variant-id  data-platform="shopify"
```

URL parsing then becomes a *fallback only*, which immunises product resolution against
locales, alternate domains, collection URLs, preview themes, renamed handles and
page-builder PDPs. `assets/trayon-shopify-context.js` keeps `data-variant-id` truthful as
the shopper switches variants, so a try-on (and the add-to-cart that follows) can never
land on the wrong variant.

## Before this can be deployed

1. Create the app in the **Partner Dashboard** → copy `client_id` into `shopify.app.toml`.
2. Put the client secret in `SHOPIFY_CLIENT_SECRET` (or the Super-Admin → Settings page —
   it is stored encrypted and wins over the env var). It is also the webhook HMAC key.
3. The app host in `shopify.app.toml` is `go.vsio.app` — it must match the deployed
   `APP_URL`. If the domain ever changes, update every URL in that file together.
4. `shopify app deploy` from this directory, then install on a **development store**.

Scopes, API version and the webhook topics are pinned in `config/shopify.php` and
`docs/shopify/DECISIONS.md` — change them there first, then mirror here.
