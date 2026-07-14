// === CONSTANTS ===
// The entry script (the CORE bundle). It does ZERO synchronous work on the host's main thread
// at load: it reads the tag's data-* context, derives the asset base from its OWN script src,
// schedules boot on idle, and exits cleanly on any problem (bad key / non-allowed origin /
// non-PDP) — never throwing into the host page.
//
// What ships here is only what the merchant's LCP/CLS/SEO must pay for on every page view:
// PDP detect, the trigger, the floating HUD, the modal skeleton, and the cross-page resume.
// The modal (and the club) are fetched only when the shopper actually engages.
//
// The only window pollution is one namespaced object (window.__TrayOn).

import {
  NAMESPACE,
  SITE_KEY_ATTR,
  PLATFORM_ATTR,
  PRODUCT_ID_ATTR,
  PRODUCT_HANDLE_ATTR,
  VARIANT_ID_ATTR,
  STORAGE_ANON_TOKEN,
  BOOT_IDLE_TIMEOUT_MS,
  CHUNK,
  CHUNK_SHELL_DELAY_MS,
  HUD,
} from './constants.js';
import { warn, uuid, onIdle } from './dom.js';
import { setLocale } from './i18n.js';
import { state } from './state.js';
import * as api from './api.js';
import { isProductPage } from './pdp.js';
import * as shell from './shell.js';
import * as mount from './mount.js';
import * as hud from './hud.js';
import * as skeleton from './skeleton.js';
import * as chunks from './chunks.js';
import * as button from './button.js';
import * as pending from './pending.js';
import * as resume from './resume.js';
import * as track from './track.js';
import { publish } from './kernel.js';

(function boot() {
  // The namespaced global: a double-boot guard + a teardown hook for SPA navigation.
  const ns = (window[NAMESPACE] = window[NAMESPACE] || {});
  if (ns.booted) return; // loader included twice -> no double-boot

  const script = currentScript();
  const siteKey = script ? script.getAttribute(SITE_KEY_ATTR) : null;

  if (!siteKey) {
    warn('missing ' + SITE_KEY_ATTR + ' on the widget <script> tag; widget disabled');
    return;
  }

  // The asset base = the DIRECTORY that served widget.js. Everything we fetch for ourselves
  // (the lazy chunks, the self-hosted font) hangs off it, and the API talks back to that origin.
  const base = assetBase(script);
  api.configure(new URL(base, location.href).origin, siteKey);
  shell.setAssetBase(base);
  chunks.configure(base);

  // The host-platform context the Theme App Extension stamps. Without it the widget holds only
  // our internal DB variant key and cannot add the right line to the merchant's own cart.
  state.platform = script.getAttribute(PLATFORM_ATTR) || null;
  state.externalVariantId = script.getAttribute(VARIANT_ID_ATTR) || null;
  const hostProductId = script.getAttribute(PRODUCT_ID_ATTR);
  const hostHandle = script.getAttribute(PRODUCT_HANDLE_ATTR);
  if (hostProductId || hostHandle) state.hostProduct = { id: hostProductId, handle: hostHandle };

  // Scope the cross-page/cross-tab persistence to THIS site_key (two Vsio sites on one
  // origin never collide). Cheap + synchronous — just sets the key/channel names.
  pending.configure(siteKey);

  // The lazy chunks read the core's live singletons from here (never a second copy).
  publish({ siteKey, openModal });

  ns.booted = true;
  ns.state = state; // exposed for the verification harness (read-only inspection)

  // No synchronous work now — schedule the real boot on idle.
  onIdle(run, BOOT_IDLE_TIMEOUT_MS);
})();

async function run() {
  state.anonToken = ensureAnonToken();

  const res = await api.getBootstrap(location.href, state.anonToken);

  // Fail soft: a bad key (401) / non-allowed origin (403) / any non-OK -> quietly do nothing.
  if (!res.ok || !res.data || res.data.ok !== true) {
    if (res.status === 401 || res.status === 403) {
      warn('widget not authorized for this origin/site; disabled');
    }
    return;
  }

  const data = res.data;

  // The locale is known even on a non-PDP page — the HUD speaks it there too.
  const locale = data.site?.locale || 'en';
  setLocale(locale);
  state.locale = locale;

  // The club + merchant banners apply SITE-WIDE (PDP or not) and ride the lazy club chunk.
  state.club = data.club || null;
  state.banners = data.banners || null;

  const onPdp = isProductPage(data);
  const trackingEnabled = data.site?.tracking_enabled;

  state.lead = normalizeLead(data.lead);

  if (onPdp) {
    state.config = {
      appearance: data.site.appearance,
      selectors: data.site.selectors,
      locale,
      privacy: data.site.privacy,
      gallery: data.site.gallery,
    };
    state.product = data.product;

    // Build the Shadow shell, then start the self-healing mount engine.
    shell.create(state.config.appearance);
    mount.start(openModal, prefetchModal);

    track.init({ trackingEnabled, hasProduct: true });

    window[NAMESPACE].teardown = combinedTeardown;
  } else {
    // Non-PDP page: no trigger. But if the shopper has a look generating (started elsewhere),
    // keep the minimal appearance around so the resumer can theme the HUD.
    if (data.site?.appearance) state.config = { appearance: data.site.appearance };

    track.init({ trackingEnabled, hasProduct: false });

    window[NAMESPACE].teardown = nonPdpTeardown;
  }

  // Cross-page / cross-tab resume: runs on EVERY authorized page load (PDP or not). Reconnects
  // the shopper to a look they started elsewhere and shows the HUD here when it finishes.
  await resume.resumeOnLoad(onPdp);

  // The club + merchant banners: a SEPARATE chunk, fetched on IDLE (never on the first-paint
  // path). A site with neither configured never fetches it at all.
  initClubChunk(onPdp);
}

/** Fetch the club chunk on idle, and only when this site actually uses it. */
function initClubChunk(onPdp) {
  const wantsClub = !!(state.club && state.club.enabled);
  const wantsBanners = !!(state.banners && state.banners.length);
  if (!wantsClub && !wantsBanners) return;

  onIdle(async () => {
    try {
      const club = await chunks.load(CHUNK.club);
      club.init({ onPdp });
    } catch {
      warn('the club chunk did not load; the storefront is unaffected');
    }
  }, BOOT_IDLE_TIMEOUT_MS);
}

/**
 * The trigger was tapped. §6.3, precisely:
 *   t=0        the TRIGGER becomes the spinner (feedback where the finger is, zero CLS);
 *   t<250ms    the chunk lands and the modal opens — one instant tap, no skeleton flash;
 *   t>=250ms   the wait is real, so the skeleton opens in the box the modal will land in;
 *   t>=8s/err  nothing half-drawn: the shell closes and the HUD offers a retry.
 */
async function openModal() {
  track.trackOpen(); // meaningful interaction: the shopper opened the Vsio flow

  const warm = chunks.ready(CHUNK.modal);
  if (warm) {
    safeOpen(warm);
    return;
  }

  button.setLoading(true);
  const shellTimer = setTimeout(() => skeleton.show(cancelChunkWait), CHUNK_SHELL_DELAY_MS);

  try {
    const modal = await chunks.load(CHUNK.modal);
    clearTimeout(shellTimer);
    button.setLoading(false);
    skeleton.release(); // the modal takes the overlay over; do not double-clear it
    safeOpen(modal);
  } catch {
    clearTimeout(shellTimer);
    button.setLoading(false);
    skeleton.hide();
    hud.show(HUD.unavailable, { onClick: openModal });
  }
}

/** The shopper closed the skeleton while waiting: stop pretending, give them the page back. */
function cancelChunkWait() {
  skeleton.hide();
  button.setLoading(false);
}

function safeOpen(modal) {
  try {
    modal.open();
  } catch {
    warn('failed to open the try-on modal');
    skeleton.hide();
    hud.show(HUD.unavailable, { onClick: openModal });
  }
}

/** A hint, never a promise: warm the modal chunk on hover/focus/touch so the click finds it here. */
function prefetchModal() {
  chunks.prefetch(CHUNK.modal);
}

/** The bootstrap's snake_case lead block -> the shape the widget reads everywhere. */
function normalizeLead(lead) {
  if (!lead) return null;
  return {
    registered: !!lead.registered,
    freeRemaining: lead.free_remaining ?? null,
    signupRequired: !!lead.signup_required,
  };
}

/** Teardown the mount engine, tracking, the club chunk and the cross-tab channel (SPA nav away). */
function combinedTeardown() {
  safeTeardown(track.teardown);
  teardownClub();
  safeTeardown(resume.teardown);
  mount.teardown();
}

function nonPdpTeardown() {
  safeTeardown(track.teardown);
  teardownClub();
  resume.teardown();
}

function teardownClub() {
  const club = chunks.ready(CHUNK.club);
  if (club && club.teardown) safeTeardown(club.teardown);
}

/** Run one teardown step without letting a failure abort the rest / leak into the host. */
function safeTeardown(fn) {
  try {
    fn();
  } catch {
    warn('teardown step failed');
  }
}

/** A stable, persisted anonymous token (the EndUser identity); generate if absent. */
function ensureAnonToken() {
  try {
    const existing = localStorage.getItem(STORAGE_ANON_TOKEN);
    if (existing && existing.length >= 8) return existing;
    const token = uuid();
    localStorage.setItem(STORAGE_ANON_TOKEN, token);
    return token;
  } catch {
    // Private mode / storage disabled — a per-session token still works for one page.
    return uuid();
  }
}

/** The <script> tag that loaded this bundle (document.currentScript, with a fallback). */
function currentScript() {
  if (document.currentScript) return document.currentScript;
  const scripts = document.getElementsByTagName('script');
  for (let i = scripts.length - 1; i >= 0; i--) {
    if (scripts[i].getAttribute(SITE_KEY_ATTR)) return scripts[i];
  }
  return null;
}

/** The absolute DIRECTORY that served widget.js (the chunks + the font sit beside it). */
function assetBase(script) {
  try {
    const src = new URL(script.src, location.href);
    return src.href.slice(0, src.href.lastIndexOf('/') + 1);
  } catch {
    return location.origin + '/';
  }
}
