/* =============================================================================
 * Tray On — placement picker. Runs INSIDE the sandboxed preview iframe
 * (sandbox="allow-scripts", opaque origin — it cannot reach the admin session).
 * The merchant hovers to highlight and clicks any element to choose where the
 * Tray On button goes; a ghost button is drawn at the pick and the chosen CSS
 * selector is postMessage'd to the parent admin page (which re-verifies it
 * server-side). Vanilla + self-contained (no imports) — PreviewSanitizer inlines
 * this into the srcdoc as the ONLY script the preview runs.
 * ==========================================================================*/
(function () {
  'use strict';

  var SRC = 'trayon-picker'; // messages FROM the picker
  var PARENT = 'trayon-parent'; // messages FROM the admin page
  var GHOST_ID = '__trayon_ghost';
  var HILITE_ID = '__trayon_hilite';
  var MAX_DEPTH = 6;

  // Static picker chrome lives in an injected <style> (never inline styles on authored markup).
  var style = document.createElement('style');
  style.textContent =
    '#__trayon_hilite{position:absolute;z-index:2147483000;pointer-events:none;border:2px solid #2563eb;' +
    'background:rgba(37,99,235,.12);border-radius:3px;}' +
    '#__trayon_ghost{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;background:#0a0a0c;' +
    'color:#fff;font:600 13px/1 system-ui,-apple-system,sans-serif;border:0;border-radius:0;' +
    'box-shadow:0 6px 20px rgba(0,0,0,.25);letter-spacing:.06em;}' +
    '#__trayon_ghost::before{content:"\\2726";}';
  (document.head || document.documentElement).appendChild(style);

  var hilite = document.createElement('div');
  hilite.id = HILITE_ID;
  hilite.style.display = 'none';
  document.documentElement.appendChild(hilite);

  var ghost = null;
  var lastSelector = null;
  var currentLabel = 'Tray On';

  function isPickable(el) {
    return (
      el &&
      el.nodeType === 1 &&
      el.id !== HILITE_ID &&
      el.id !== GHOST_ID &&
      el !== document.documentElement &&
      el !== document.body
    );
  }

  function rectOf(el) {
    var r = el.getBoundingClientRect();
    return { top: r.top + window.scrollY, left: r.left + window.scrollX, width: r.width, height: r.height };
  }

  function showHilite(el) {
    var r = rectOf(el);
    hilite.style.display = 'block';
    hilite.style.top = r.top + 'px';
    hilite.style.left = r.left + 'px';
    hilite.style.width = r.width + 'px';
    hilite.style.height = r.height + 'px';
  }

  function cssEscape(s) {
    if (window.CSS && CSS.escape) return CSS.escape(s);
    return String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  }

  function classListOf(el) {
    return ((el.getAttribute && el.getAttribute('class')) || '').split(/\s+/).filter(Boolean);
  }

  // Prefer an id that looks author-written + is unique. Reject generated-looking ids.
  function stableId(el) {
    var id = el.getAttribute && el.getAttribute('id');
    if (!id || /^\d/.test(id) || /[0-9a-f]{8,}/i.test(id) || id.length > 40) return null;
    try {
      if (document.querySelectorAll('#' + cssEscape(id)).length === 1) return id;
    } catch (e) {}
    return null;
  }

  // A distinctive, non-stateful class (skip is-/has-/js- toggles + hashed names).
  function stableClass(el) {
    var list = classListOf(el).filter(function (c) {
      return c.length > 1 && c.length < 40 && !/[0-9a-f]{6,}/i.test(c) && !/^(is-|has-|js-)/.test(c);
    });
    return list.length ? list[0] : null;
  }

  function nthOfType(el) {
    var i = 1;
    var sib = el;
    while ((sib = sib.previousElementSibling)) {
      if (sib.tagName === el.tagName) i++;
    }
    return i;
  }

  // Build a stable selector: a unique id if we can, else a bounded nth-of-type path that we
  // stop growing as soon as it resolves to exactly one element.
  function selectorFor(el) {
    var sid = stableId(el);
    if (sid) return '#' + cssEscape(sid);

    var parts = [];
    var node = el;
    var depth = 0;
    while (isPickable(node) && depth < MAX_DEPTH) {
      var anc = stableId(node);
      if (anc) {
        parts.unshift('#' + cssEscape(anc));
        break;
      }
      var part = node.tagName.toLowerCase();
      var cl = stableClass(node);
      if (cl) part += '.' + cssEscape(cl);
      part += ':nth-of-type(' + nthOfType(node) + ')';
      parts.unshift(part);

      var sel = parts.join(' > ');
      try {
        if (document.querySelectorAll(sel).length === 1) return sel;
      } catch (e) {}

      node = node.parentElement;
      depth++;
    }
    return parts.join(' > ');
  }

  function send(type, extra) {
    var msg = { source: SRC, type: type };
    if (extra) {
      for (var k in extra) {
        if (Object.prototype.hasOwnProperty.call(extra, k)) msg[k] = extra[k];
      }
    }
    try {
      parent.postMessage(msg, '*');
    } catch (e) {}
  }

  function clearGhost() {
    if (ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost);
    ghost = null;
  }

  function drawGhost(selector, position, label) {
    clearGhost();
    var target = null;
    try {
      target = selector ? document.querySelector(selector) : null;
    } catch (e) {}
    if (!target) return;

    ghost = document.createElement('span');
    ghost.id = GHOST_ID;
    ghost.textContent = label || currentLabel;

    var parentEl = target.parentNode;
    if (position === 'before' && parentEl) parentEl.insertBefore(ghost, target);
    else if (position === 'prepend') target.insertBefore(ghost, target.firstChild);
    else if (position === 'append') target.appendChild(ghost);
    else if (parentEl) parentEl.insertBefore(ghost, target.nextSibling);

    if (ghost.scrollIntoView) ghost.scrollIntoView({ block: 'center' });
  }

  document.addEventListener(
    'mousemove',
    function (e) {
      if (isPickable(e.target)) showHilite(e.target);
    },
    true,
  );

  document.addEventListener(
    'click',
    function (e) {
      var el = e.target;
      if (!isPickable(el)) return;
      // Never let the merchant's page navigate/submit while picking.
      e.preventDefault();
      e.stopPropagation();

      lastSelector = selectorFor(el);
      send('pick', {
        mode: 'element',
        selector: lastSelector,
        position: 'after',
        rect: rectOf(el),
        tag: { name: el.tagName.toLowerCase(), id: el.id || null, classes: classListOf(el) },
      });
      drawGhost(lastSelector, 'after', currentLabel);
    },
    true,
  );

  // Defensive: block any form submit inside the preview.
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

  // Admin page → picker: move/redraw the ghost as the merchant tweaks the position, or clear it.
  window.addEventListener('message', function (e) {
    var d = e.data;
    if (!d || d.source !== PARENT) return;
    if (d.type === 'label') {
      currentLabel = d.label || currentLabel;
    } else if (d.type === 'setGhost') {
      if (d.label) currentLabel = d.label;
      drawGhost(d.selector || lastSelector, d.position || 'after', currentLabel);
    } else if (d.type === 'clear') {
      clearGhost();
    }
  });

  send('ready');
})();
