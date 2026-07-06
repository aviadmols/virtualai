// === CONSTANTS ===
// The entry script. Does ZERO synchronous work on the host's main thread at load: it reads
// the data-site-key, derives the API base from its OWN script origin, schedules boot on
// idle, and exits cleanly on any problem (bad key / non-allowed origin / non-PDP) — never
// throwing into the host page. The modal/result code is lazy-imported on first button click
// so the entry bundle stays under budget. The only window pollution is one namespaced
// object (window.__TrayOn).

import {
  NAMESPACE,
  SITE_KEY_ATTR,
  STORAGE_ANON_TOKEN,
  BOOT_IDLE_TIMEOUT_MS,
} from './constants.js';
import { warn, uuid, onIdle } from './dom.js';
import { setLocale } from './i18n.js';
import { state } from './state.js';
import * as api from './api.js';
import { isProductPage } from './pdp.js';
import * as shell from './shell.js';
import * as mount from './mount.js';
import * as modal from './modal.js';
import * as pending from './pending.js';
import * as resume from './resume.js';
import * as track from './track.js';

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

  // The API base = the origin that served widget.js (so the widget always talks home).
  const apiBase = scriptOrigin(script);
  api.configure(apiBase, siteKey);

  // Scope the cross-page/cross-tab persistence to THIS site_key (two Tray On sites on one
  // origin never collide). Cheap + synchronous — just sets the key/channel names.
  pending.configure(siteKey);

  ns.booted = true;
  ns.state = state; // exposed for the verification harness (read-only inspection)

  // No synchronous work now — schedule the real boot on idle.
  onIdle(run, BOOT_IDLE_TIMEOUT_MS);
})();

async function run() {
  const anonToken = ensureAnonToken();
  state.anonToken = anonToken;

  const res = await api.getBootstrap(location.href, anonToken);

  // Fail soft: a bad key (401) / non-allowed origin (403) / any non-OK -> quietly do nothing.
  if (!res.ok || !res.data || res.data.ok !== true) {
    if (res.status === 401 || res.status === 403) {
      warn('widget not authorized for this origin/site; disabled');
    }
    return;
  }

  const data = res.data;

  // Locale is known even on a non-PDP page — the cross-page notification uses it.
  setLocale(data.site?.locale || 'en');

  const onPdp = isProductPage(data);

  // Per-site tracking flag (privacy/consent). Absent => on, per the ingest contract.
  const trackingEnabled = data.site?.tracking_enabled;

  if (onPdp) {
    // Stash the boot config.
    state.config = {
      appearance: data.site.appearance,
      selectors: data.site.selectors,
      locale: data.site.locale,
      privacy: data.site.privacy,
      gallery: data.site.gallery,
    };
    state.product = data.product;
    state.lead = data.lead || null;

    // Build the Shadow shell, then start the self-healing mount engine.
    shell.create(state.config.appearance);
    mount.start(openModal);

    // Behavioral tracking (page_view + product_view + variant/open/cart interactions), on the
    // same idle tick — never a sync hook. Needs the shell's overlay mount for the variant event.
    track.init({ trackingEnabled, hasProduct: true });

    // Expose teardown so a host SPA (or our own future route watcher) can clean up.
    window[NAMESPACE].teardown = combinedTeardown;
  } else {
    // Non-PDP page: no button. But if the shopper has a try-on generating (started elsewhere),
    // keep the minimal appearance around so the resumer can theme the notification.
    if (data.site?.appearance) state.config = { appearance: data.site.appearance };

    // A non-PDP page still records a single page_view (no product / interactions to bind).
    track.init({ trackingEnabled, hasProduct: false });

    window[NAMESPACE].teardown = nonPdpTeardown;
  }

  // Cross-page / cross-tab resume: runs on EVERY authorized page load (PDP or not). Reconnects
  // the shopper to a try-on they started elsewhere and shows the "ready" popup here on finish.
  await resume.resumeOnLoad(onPdp);
}

/** Teardown the mount engine, tracking, and the cross-tab channel (SPA nav away from a PDP). */
function combinedTeardown() {
  safeTeardown(track.teardown);
  safeTeardown(resume.teardown);
  mount.teardown();
}

/** Non-PDP teardown: flush + drop tracking, then the resume channel. */
function nonPdpTeardown() {
  safeTeardown(track.teardown);
  resume.teardown();
}

/** Run one teardown step without letting a failure abort the rest / leak into the host. */
function safeTeardown(fn) {
  try {
    fn();
  } catch {
    warn('teardown step failed');
  }
}

/** Open the modal on button click. (The modal is bundled in the single IIFE; the panel
 *  DOM + the result/loading screens are only constructed on demand, so first paint stays
 *  cheap and the host main thread is never touched until the shopper clicks.) */
function openModal() {
  track.trackOpen(); // meaningful interaction: the shopper opened the Tray On flow
  try {
    modal.open();
  } catch {
    warn('failed to open the try-on modal');
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

/** The origin (scheme://host:port) that served the widget.js bundle. */
function scriptOrigin(script) {
  try {
    return new URL(script.src, location.href).origin;
  } catch {
    return location.origin;
  }
}
