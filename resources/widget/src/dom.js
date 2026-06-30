// === CONSTANTS ===
// Tiny dependency-free DOM helpers. No framework. Keeps the bundle lean and the call
// sites readable. English-only comments.

/** Create an element with structural classes + attrs + children. No inline style here. */
export function el(tag, opts = {}, children = []) {
  const node = document.createElement(tag);

  if (opts.class) node.className = opts.class;
  if (opts.text != null) node.textContent = opts.text;
  if (opts.html != null) node.innerHTML = opts.html;

  if (opts.attrs) {
    for (const [name, value] of Object.entries(opts.attrs)) {
      if (value === false || value == null) continue;
      node.setAttribute(name, value === true ? '' : String(value));
    }
  }

  if (opts.on) {
    for (const [event, handler] of Object.entries(opts.on)) {
      node.addEventListener(event, handler);
    }
  }

  for (const child of [].concat(children)) {
    if (child == null) continue;
    node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
  }

  return node;
}

/** A debounced wrapper (trailing-edge). Used for the MutationObserver callback. */
export function debounce(fn, wait) {
  let timer = null;
  return (...args) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      timer = null;
      fn(...args);
    }, wait);
  };
}

/** A namespaced, non-throwing console warning — never noise that hurts the merchant. */
export function warn(...args) {
  try {
    // eslint-disable-next-line no-console
    console.warn('[TrayOn]', ...args);
  } catch {
    /* never throw into the host page */
  }
}

/** RFC4122-ish v4 id (crypto where available) — the per-intent client_request_id + anon token. */
export function uuid() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

/** Idle-time scheduler with a timeout fallback (boot must do zero sync work on load). */
export function onIdle(fn, timeout) {
  if (typeof requestIdleCallback === 'function') {
    requestIdleCallback(fn, { timeout });
  } else {
    setTimeout(fn, 1);
  }
}

/** Read a CSS selector from the per-site config, tolerating both shapes:
 *  a flat string ("button.add-to-cart") OR the scanner's object ({ primary: "..." }). */
export function selectorString(selectorConfig, role) {
  const entry = selectorConfig?.[role];
  if (!entry) return null;
  if (typeof entry === 'string') return entry.trim() || null;
  if (typeof entry === 'object' && typeof entry.primary === 'string') {
    return entry.primary.trim() || null;
  }
  return null;
}
