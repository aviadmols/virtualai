// === CONSTANTS ===
// The merchant-banner runtime. Injects each active banner's image at the merchant-picked host-page
// spots, gated by CLIENT-SIDE rules (audience / page context / frequency / locale — the schedule was
// already enforced server-side). Each spot is a host-DOM wrapper with its OWN shadow root (like the
// Vsio button) so there is ZERO host-CSS bleed either way; the <img> carries width/height so the
// box is reserved (CLS-safe). ONE impression per banner per page-load (session-capped by the
// frequency rule); a click beacons then lets the link navigate. Idempotent + self-healing (a
// per-banner sentinel + a debounced MutationObserver). Rides the idle path — never a sync hook,
// never throws into the host page.

import bannerCss from '../../styles/banner.css';
import {
  BANNER_CONFIG,
  BANNER_COMPOSITION,
  BANNER_OVERLAY,
  BANNER_PLACEMENT,
  BANNER_PLACE,
  BANNER_RULE,
  BANNER_PAGE_KEY,
  BANNER_FREQ_KEY,
  BANNER_AUDIENCE,
  BANNER_PAGE,
  BANNER_KIND,
  BANNER_SENTINEL_ATTR,
  STORAGE_SEEN_PREFIX,
  SESSION_BANNER_IMPR_PREFIX,
  BANNER_CART_URL_RE,
  APPEARANCE,
} from '../constants.js';
import { state, api, shell } from './bridge.js';
import { el, safeQuery, warn } from '../dom.js';


// Debounce for the self-heal re-mount after a host DOM mutation (SPA re-render).
const OBSERVER_DEBOUNCE_MS = 400;

let siteKey = '';
let banners = []; // the active banners from the bootstrap
let observer = null; // self-heal observer
let observerTimer = null; // debounce handle
let visitorIsNew = false; // computed ONCE at init (new-vs-returning targeting)
const impressed = new Set(); // banner ids that already logged an impression THIS page load

/** Configure the site-scoped storage keys (called before init, like club.configure). */
export function configure(key) {
  siteKey = key || '';
}

/**
 * Initialise the banner runtime on the idle path. A no-op unless the bootstrap shipped banners.
 * Computes the new-vs-returning flag once, mounts every eligible banner, and arms the self-heal.
 */
export function init(list) {
  banners = Array.isArray(list) ? list.filter(Boolean) : [];
  if (! banners.length) return;

  visitorIsNew = computeNewVisitor(); // read + set the "seen" flag exactly once
  mountAll();
  armObserver();
}

/** Mount every rule-eligible banner at each of its resolvable spots (idempotent). */
function mountAll() {
  for (const banner of banners) {
    if (! passesRules(banner)) continue;

    let present = false;
    const placements = banner[BANNER_CONFIG.placements] || [];
    for (const placement of placements) {
      if (mountOne(banner, placement)) present = true;
    }

    // One impression per banner per page-load, only if it actually appeared somewhere.
    if (present) recordImpression(banner);
  }
}

/** Inject ONE banner at ONE spot. Returns true when the banner is present at that spot afterward. */
function mountOne(banner, placement) {
  const target = safeQuery(placement && placement[BANNER_PLACEMENT.selector]);
  if (! target) return false; // the picked spot isn't on this page — skip (fail-soft)

  if (alreadyAt(target, banner)) return true; // idempotent — never double-inject

  let wrapper;
  try {
    wrapper = buildWrapper(banner);
  } catch {
    return false;
  }

  return placeAt(wrapper, target, placement[BANNER_PLACEMENT.position] || BANNER_PLACE.after);
}

/** True when this banner's wrapper already sits adjacent-to / inside the target. */
function alreadyAt(target, banner) {
  const id = String(banner[BANNER_CONFIG.id]);
  const near = [
    target.previousElementSibling,
    target.nextElementSibling,
    target.firstElementChild,
    target.lastElementChild,
  ];

  return near.some((n) => n && n.getAttribute && n.getAttribute(BANNER_SENTINEL_ATTR) === id);
}

/**
 * A host-DOM wrapper (carrying the sentinel) with its OWN shadow root holding the banner. Lives in
 * the host DOM so the merchant placement is honored, but style-isolated both ways (its own shadow +
 * all:initial), exactly like the Vsio button.
 */
function buildWrapper(banner) {
  const wrapper = el('span', { attrs: { [BANNER_SENTINEL_ATTR]: String(banner[BANNER_CONFIG.id]) } });
  wrapper.style.setProperty('all', 'initial');
  wrapper.style.setProperty('display', 'block');

  const shadow = wrapper.attachShadow({ mode: 'open' });

  const style = document.createElement('style');
  style.textContent = shell.getCoreCss() + bannerCss;
  shadow.appendChild(style);

  const root = el('div', { class: 'ton-root' });
  root.setAttribute('dir', (state.locale === 'he') ? 'rtl' : 'ltr');
  // Let the overlay CTA pick up the site accent (dynamic per-site value, not a hardcoded literal).
  const accent = state.config && state.config.appearance && state.config.appearance[APPEARANCE.popupAccent];
  if (accent) root.style.setProperty('--ton-accent', accent);

  root.appendChild(bannerEl(banner));
  shadow.appendChild(root);

  return wrapper;
}

/** The banner link (or plain block when no target): the image, plus the text overlay in overlay mode. */
function bannerEl(banner) {
  const href = banner[BANNER_CONFIG.targetUrl] || null;
  const isOverlay = banner[BANNER_CONFIG.composition] === BANNER_COMPOSITION.overlay;

  const img = el('img', {
    class: 'ton-banner__img',
    attrs: {
      src: banner[BANNER_CONFIG.imageUrl] || '',
      alt: banner[BANNER_CONFIG.alt] || '',
      loading: 'lazy',
      decoding: 'async',
    },
  });

  // width/height reserve the box so injection causes no layout shift (CLS-safe).
  const w = banner[BANNER_CONFIG.width];
  const h = banner[BANNER_CONFIG.height];
  if (w && h) {
    img.setAttribute('width', String(w));
    img.setAttribute('height', String(h));
  }

  const children = isOverlay ? [img, overlayLayer(banner)] : [img];

  return el(
    href ? 'a' : 'div',
    {
      class: 'ton-banner' + (isOverlay ? ' ton-banner--overlay' : ''),
      attrs: href ? { href, rel: 'noopener' } : {},
      on: href ? { click: () => recordClick(banner) } : {},
    },
    children,
  );
}

/** The crisp HTML text overlay (merchant DATA rendered as text nodes — never HTML, no XSS). */
function overlayLayer(banner) {
  const o = banner[BANNER_CONFIG.overlay] || {};
  const kids = [];

  if (o[BANNER_OVERLAY.headline]) kids.push(el('span', { class: 'ton-banner__headline', text: o[BANNER_OVERLAY.headline] }));
  if (o[BANNER_OVERLAY.subtext]) kids.push(el('span', { class: 'ton-banner__subtext', text: o[BANNER_OVERLAY.subtext] }));
  if (o[BANNER_OVERLAY.ctaLabel]) kids.push(el('span', { class: 'ton-banner__cta', text: o[BANNER_OVERLAY.ctaLabel] }));

  return el('span', { class: 'ton-banner__overlay' }, kids);
}

/** Place the wrapper relative to the target (mirrors the button's placeCustom semantics). */
function placeAt(wrapper, target, position) {
  if (position === BANNER_PLACE.prepend) {
    target.insertBefore(wrapper, target.firstChild);
    return true;
  }
  if (position === BANNER_PLACE.append) {
    target.appendChild(wrapper);
    return true;
  }

  const parent = target.parentNode;
  if (! parent) return false; // before/after need a parent

  if (position === BANNER_PLACE.before) parent.insertBefore(wrapper, target);
  else parent.insertBefore(wrapper, target.nextSibling); // after (default)

  return true;
}

// ---------------------------------------------------------------------------
// Client-side rule evaluation (audience / pages / frequency / locale).
// ---------------------------------------------------------------------------
function passesRules(banner) {
  const rules = banner[BANNER_CONFIG.rules] || {};

  return audienceOk(rules[BANNER_RULE.audience])
    && pagesOk(rules[BANNER_RULE.pages])
    && frequencyOk(banner, rules[BANNER_RULE.frequency])
    && localesOk(rules[BANNER_RULE.locales]);
}

function audienceOk(audience) {
  if (! audience || audience === BANNER_AUDIENCE.any) return true;

  const isMember = !!(state.club && state.club.member && state.club.member.verified);
  const isRegistered = !!(state.lead && state.lead.registered);

  if (audience === BANNER_AUDIENCE.clubMembers) return isMember;
  if (audience === BANNER_AUDIENCE.nonMembers) return ! isMember;
  if (audience === BANNER_AUDIENCE.registered) return isRegistered;
  if (audience === BANNER_AUDIENCE.newVisitors) return visitorIsNew;
  if (audience === BANNER_AUDIENCE.returningVisitors) return ! visitorIsNew;

  return true;
}

function pagesOk(pages) {
  if (! pages) return true;

  const ctx = pages[BANNER_PAGE_KEY.context] || BANNER_PAGE.any;
  const onPdp = !! state.product;
  const onCart = BANNER_CART_URL_RE.test(location.pathname);

  let ok = true;
  if (ctx === BANNER_PAGE.pdp) ok = onPdp;
  else if (ctx === BANNER_PAGE.cart) ok = onCart;
  else if (ctx === BANNER_PAGE.catalog) ok = ! onPdp && ! onCart;
  // 'any' -> ok stays true

  const contains = pages[BANNER_PAGE_KEY.urlContains];
  if (ok && contains) ok = location.href.indexOf(contains) !== -1;

  return ok;
}

function frequencyOk(banner, frequency) {
  const max = frequency ? Number(frequency[BANNER_FREQ_KEY.max]) : 0;
  if (! max || max <= 0) return true; // unlimited

  return sessionImpressions(banner) < max;
}

function localesOk(locales) {
  if (! Array.isArray(locales) || ! locales.length) return true; // all locales

  return locales.indexOf(state.locale || 'en') !== -1;
}

// ---------------------------------------------------------------------------
// Analytics (per-banner impression + click) — fire-and-forget.
// ---------------------------------------------------------------------------
function recordImpression(banner) {
  const id = banner[BANNER_CONFIG.id];
  if (impressed.has(id)) return; // once per page load

  impressed.add(id);
  bumpSessionImpressions(banner); // the frequency-cap counter

  try {
    api.recordBannerEvent(state.anonToken, id, BANNER_KIND.impression, safePath(), false);
  } catch {
    /* analytics never gates anything */
  }
}

function recordClick(banner) {
  try {
    // A beacon so the event survives the navigation the click triggers.
    api.recordBannerEvent(state.anonToken, banner[BANNER_CONFIG.id], BANNER_KIND.click, safePath(), true);
  } catch {
    /* never block the click */
  }
}

function safePath() {
  try {
    return location.pathname;
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// Persistence: new-vs-returning (localStorage) + the per-session frequency counter (sessionStorage).
// ---------------------------------------------------------------------------
function computeNewVisitor() {
  try {
    const key = STORAGE_SEEN_PREFIX + siteKey;
    const seen = localStorage.getItem(key) === '1';
    if (! seen) localStorage.setItem(key, '1');

    return ! seen;
  } catch {
    return false; // storage disabled -> treat as returning (conservative)
  }
}

function sessionImpressions(banner) {
  try {
    const v = Number(sessionStorage.getItem(imprKey(banner)));

    return Number.isFinite(v) && v > 0 ? v : 0;
  } catch {
    return 0;
  }
}

function bumpSessionImpressions(banner) {
  try {
    sessionStorage.setItem(imprKey(banner), String(sessionImpressions(banner) + 1));
  } catch {
    /* private mode — the cap is best-effort */
  }
}

function imprKey(banner) {
  return SESSION_BANNER_IMPR_PREFIX + siteKey + ':' + banner[BANNER_CONFIG.id];
}

// ---------------------------------------------------------------------------
// Self-heal: re-mount removed banners after a host SPA re-render (debounced). Idempotent, so it
// never duplicates a banner or re-logs an impression (both are deduped).
// ---------------------------------------------------------------------------
function armObserver() {
  if (observer || typeof MutationObserver === 'undefined') return;

  observer = new MutationObserver(() => {
    if (observerTimer) return;
    observerTimer = setTimeout(() => {
      observerTimer = null;
      try {
        mountAll();
      } catch {
        warn('banner re-mount failed');
      }
    }, OBSERVER_DEBOUNCE_MS);
  });

  try {
    observer.observe(document.body, { childList: true, subtree: true });
  } catch {
    /* observe should never throw; fail-soft */
  }
}

/** Teardown (SPA navigation away): stop observing + remove every injected banner. */
export function teardown() {
  if (observer) {
    try {
      observer.disconnect();
    } catch {
      /* ignore */
    }
    observer = null;
  }
  if (observerTimer) {
    clearTimeout(observerTimer);
    observerTimer = null;
  }

  try {
    document.querySelectorAll('[' + BANNER_SENTINEL_ATTR + ']').forEach((n) => {
      if (n.parentNode) n.parentNode.removeChild(n);
    });
  } catch {
    /* ignore */
  }

  banners = [];
  impressed.clear();
}
