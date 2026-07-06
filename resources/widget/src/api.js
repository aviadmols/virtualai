// === CONSTANTS ===
// The signed-widget-API client. Every call sends ONLY the public site_key (X-Tray-Site-Key
// header) + the browser's Origin (set automatically by fetch). The server enforces the
// per-site domain allow-list by Origin and binds the tenant. No secret, no HMAC material,
// no cost ever flows through here in a browser-readable way.
//
// The API base is derived from the loader <script>'s OWN origin (set by the loader), so the
// widget always talks back to the same host that served widget.js.

import {
  API_PREFIX,
  ENDPOINTS,
  HEADER_SITE_KEY,
  QUERY_SITE_KEY,
  QUERY_URL,
  QUERY_ANON_TOKEN,
  QUERY_LIMIT,
  GEN_FIELD,
  LEAD_FIELD,
  CART_EVENT_FIELD,
} from './constants.js';

let apiBase = '';
let siteKey = '';

/** Configure the client once at boot with the API origin + the public site_key. */
export function configure(base, key) {
  apiBase = base.replace(/\/+$/, '') + API_PREFIX;
  siteKey = key;
}

function url(path, query = {}) {
  const u = new URL(apiBase + path, location.href);
  for (const [name, value] of Object.entries(query)) {
    if (value != null && value !== '') u.searchParams.set(name, String(value));
  }
  return u.toString();
}

function headers(extra = {}) {
  return { [HEADER_SITE_KEY]: siteKey, ...extra };
}

/** A typed fetch wrapper: returns { ok, status, data } and never throws on an HTTP error.
 *  `keepalive` lets a fire-and-forget flush survive a page unload (used by tracking). */
async function request(method, path, { query, json, headers: extra, keepalive } = {}) {
  const init = { method, headers: headers(extra), credentials: 'omit', mode: 'cors' };
  if (keepalive) init.keepalive = true;

  if (json !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(json);
  }

  let response;
  try {
    response = await fetch(url(path, query), init);
  } catch {
    // Network/CORS failure — a typed, non-throwing shape the callers render gracefully.
    return { ok: false, status: 0, data: null, networkError: true };
  }

  let data = null;
  try {
    data = await response.json();
  } catch {
    data = null;
  }

  return { ok: response.ok, status: response.status, data };
}

/** GET /bootstrap — site config, appearance, selectors, lead state, confirmed product. */
export function getBootstrap(pageUrl, anonToken) {
  return request('GET', ENDPOINTS.bootstrap, {
    query: { [QUERY_SITE_KEY]: siteKey, [QUERY_URL]: pageUrl, [QUERY_ANON_TOKEN]: anonToken },
  });
}

/** POST /generations — start a try-on. The body carries the deterministic client_request_id. */
export function createGeneration(payload) {
  return request('POST', ENDPOINTS.generations, {
    json: {
      [GEN_FIELD.photo]: payload.photo,
      [GEN_FIELD.height]: payload.height,
      [GEN_FIELD.productId]: payload.productId,
      [GEN_FIELD.variantId]: payload.variantId,
      [GEN_FIELD.clientRequestId]: payload.clientRequestId,
      [GEN_FIELD.consent]: payload.consent,
      [GEN_FIELD.anonToken]: payload.anonToken,
      [GEN_FIELD.extra]: payload.extra || {},
    },
  });
}

/** GET /generations/{id} — poll status + (succeeded only) a signed result URL. */
export function getGeneration(id, anonToken) {
  return request('GET', ENDPOINTS.generation(id), {
    query: { [QUERY_ANON_TOKEN]: anonToken },
  });
}

/** GET /gallery — this shopper's past succeeded try-ons (signed result URLs), newest first. */
export function getGallery(anonToken, limit) {
  return request('GET', ENDPOINTS.gallery, {
    query: { [QUERY_ANON_TOKEN]: anonToken, [QUERY_LIMIT]: limit },
  });
}

/** POST /leads — signup (re-opens the lead gate). */
export function createLead(payload) {
  return request('POST', ENDPOINTS.leads, {
    json: {
      [LEAD_FIELD.fullName]: payload.fullName,
      [LEAD_FIELD.email]: payload.email,
      [LEAD_FIELD.phone]: payload.phone || null,
      [LEAD_FIELD.marketingConsent]: payload.marketingConsent === true,
      [LEAD_FIELD.anonToken]: payload.anonToken,
      [LEAD_FIELD.source]: 'widget',
    },
  });
}

/** POST /events/add-to-cart — record the funnel event (the real cart add is the host's job). */
export function recordAddToCart(payload) {
  return request('POST', ENDPOINTS.addToCart, {
    json: {
      [CART_EVENT_FIELD.anonToken]: payload.anonToken,
      [CART_EVENT_FIELD.generationId]: payload.generationId || null,
      [CART_EVENT_FIELD.variantId]: payload.variantId || null,
    },
  });
}

/**
 * POST /events — fire-and-forget batch of behavioral events { anon_token, events: [...] }.
 * The response is IGNORED (tracking never gates anything). On a pagehide flush we prefer
 * navigator.sendBeacon (survives unload without keeping the page alive); sendBeacon can't set
 * a header, so the beacon path carries the public site_key on the query string (the widget
 * middleware reads ?site_key= as well as the header) and the browser still sends Origin. When
 * a beacon isn't available/possible we fall back to fetch keepalive (which CAN set the header).
 */
export function recordEvents(payload, useBeacon = false) {
  const body = {
    [QUERY_ANON_TOKEN]: payload.anonToken,
    events: Array.isArray(payload.events) ? payload.events : [],
  };

  if (useBeacon && typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
    try {
      const target = url(ENDPOINTS.events, { [QUERY_SITE_KEY]: siteKey });
      const blob = new Blob([JSON.stringify(body)], { type: 'application/json' });
      if (navigator.sendBeacon(target, blob)) return true;
    } catch {
      // fall through to fetch keepalive below
    }
  }

  // fetch keepalive: survives the unload, sets the site-key header, response ignored.
  request('POST', ENDPOINTS.events, { json: body, keepalive: true }).catch(() => {});
  return true;
}
