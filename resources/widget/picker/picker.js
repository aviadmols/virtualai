/* =============================================================================
 * Tray On — in-preview element picker. Runs INSIDE the sandboxed preview iframe
 * (sandbox="allow-scripts", opaque origin — it cannot reach the admin session).
 * The merchant hovers to highlight and clicks any element; the chosen CSS
 * selector is postMessage'd to the parent admin page (which re-verifies it
 * server-side). Vanilla + self-contained (no imports) — PreviewSanitizer inlines
 * this into the srcdoc as the ONLY script the preview runs. It is NOT part of the
 * widget src bundle (resources/widget/src/*), so the storefront gzip budget is
 * unaffected.
 *
 * Three modes, one selector engine (selectorFor()):
 *  - 'placement' (default): pick WHERE the Tray On button goes — draws a ghost
 *    button at the pick and posts {mode:'element', position, selector, rect, tag}.
 *  - 'role': pick WHICH element a product detail (price/title/size/…) is taken
 *    from — persistently highlights the pick (no ghost) and posts
 *    {mode:'role', role, selector, rect, tag}. One pick at a time.
 *  - 'zone': pick MULTIPLE elements for one price zone (the Customer-Club price
 *    picker). Each click ACCUMULATES another selector: it stays highlighted and
 *    posts {mode:'zone', role, selector, rect, tag}. The parent re-verifies each
 *    pick server-side and echoes back the confirmed set with {type:'setZones',
 *    selectors:[…]}, which repaints the persistent highlights (so a rejected pick
 *    drops off and the merchant sees exactly what is stored).
 * The parent selects the mode with {source:'trayon-parent', type:'setMode',
 * mode, role}; the default stays 'placement' so the existing flow is unchanged.
 * ==========================================================================*/
(function () {
  'use strict';

  var SRC = 'trayon-picker'; // messages FROM the picker
  var PARENT = 'trayon-parent'; // messages FROM the admin page
  var GHOST_ID = '__trayon_ghost';
  var HILITE_ID = '__trayon_hilite';
  var PICKED_ID = '__trayon_picked'; // the persistent highlight of a role pick
  var ZONES_ID = '__trayon_zones'; // the layer holding every accumulated zone pick
  var ZONE_MARK = '__trayon_zone_mark'; // one accumulated zone highlight
  var BANNER_PREV = '__trayon_banner_prev'; // a real banner injected at a placement (WYSIWYG)
  var MAX_DEPTH = 6;

  var MODE_PLACEMENT = 'placement';
  var MODE_ROLE = 'role';
  var MODE_ZONE = 'zone';

  // Static picker chrome lives in an injected <style> (never inline styles on authored markup).
  var style = document.createElement('style');
  style.textContent =
    '#__trayon_hilite{position:absolute;z-index:2147483000;pointer-events:none;border:2px solid #2563eb;' +
    'background:rgba(37,99,235,.12);border-radius:3px;}' +
    '#__trayon_picked{position:absolute;z-index:2147482999;pointer-events:none;border:2px solid #16a34a;' +
    'background:rgba(22,163,74,.12);border-radius:3px;}' +
    '.__trayon_zone_mark{position:absolute;z-index:2147482998;pointer-events:none;border:2px solid #16a34a;' +
    'background:rgba(22,163,74,.12);border-radius:3px;}' +
    '.__trayon_zone_mark::after{content:attr(data-n);position:absolute;inset-block-start:-9px;inset-inline-start:-9px;' +
    'min-inline-size:18px;block-size:18px;padding:0 4px;display:flex;align-items:center;justify-content:center;' +
    'background:#16a34a;color:#fff;font:600 11px/1 system-ui,-apple-system,sans-serif;border-radius:9px;}' +
    '#__trayon_ghost{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;background:#0a0a0c;' +
    'color:#fff;font:600 13px/1 system-ui,-apple-system,sans-serif;border:0;border-radius:0;' +
    'box-shadow:0 6px 20px rgba(0,0,0,.25);letter-spacing:.06em;}' +
    '#__trayon_ghost::before{content:"\\2726";}' +
    // A real banner injected at a placement — dashed frame + numbered badge so the merchant sees
    // exactly WHERE and HOW it renders. The image is constrained to its container (responsive).
    '.__trayon_banner_prev{position:relative;display:block;margin:6px 0;outline:2px dashed #2563eb;' +
    'outline-offset:2px;box-shadow:0 6px 20px rgba(0,0,0,.2);}' +
    '.__trayon_banner_prev>img{display:block;max-inline-size:100%;block-size:auto;}' +
    '.__trayon_banner_prev.__trayon_banner_prev_empty{min-block-size:64px;display:flex;' +
    'align-items:center;justify-content:center;background:rgba(37,99,235,.08);}' +
    '.__trayon_banner_prev::after{content:attr(data-n);position:absolute;inset-block-start:-9px;' +
    'inset-inline-start:-9px;min-inline-size:18px;block-size:18px;padding:0 4px;display:flex;' +
    'align-items:center;justify-content:center;background:#2563eb;color:#fff;' +
    'font:600 11px/1 system-ui,-apple-system,sans-serif;border-radius:9px;z-index:1;}';
  (document.head || document.documentElement).appendChild(style);

  var hilite = document.createElement('div');
  hilite.id = HILITE_ID;
  hilite.style.display = 'none';
  document.documentElement.appendChild(hilite);

  var picked = document.createElement('div');
  picked.id = PICKED_ID;
  picked.style.display = 'none';
  document.documentElement.appendChild(picked);

  // Zone mode paints ONE mark per accumulated pick; the parent owns the confirmed
  // list and repaints via setZones, so this layer is fully re-derived, never appended blindly.
  var zoneLayer = document.createElement('div');
  zoneLayer.id = ZONES_ID;
  document.documentElement.appendChild(zoneLayer);

  var ghost = null;
  var lastSelector = null;
  var currentLabel = 'Tray On';
  var mode = MODE_PLACEMENT; // default: the existing placement flow
  var currentRole = null; // which detail role a 'role' pick targets

  function isPickable(el) {
    return (
      el &&
      el.nodeType === 1 &&
      el.id !== HILITE_ID &&
      el.id !== PICKED_ID &&
      el.id !== GHOST_ID &&
      !(el.closest && el.closest('.' + BANNER_PREV)) && // never pick an injected banner preview
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

  // A persistent green outline of a chosen element (role mode; no ghost button).
  function showPicked(el) {
    if (!el) {
      picked.style.display = 'none';
      return;
    }
    var r = rectOf(el);
    picked.style.display = 'block';
    picked.style.top = r.top + 'px';
    picked.style.left = r.left + 'px';
    picked.style.width = r.width + 'px';
    picked.style.height = r.height + 'px';
    if (el.scrollIntoView) el.scrollIntoView({ block: 'center' });
  }

  // Repaint the zone layer from the parent's CONFIRMED selector list (server-verified).
  // Each selector that still resolves to exactly one element gets a numbered mark; the
  // list is the source of truth, so a rejected/removed pick simply disappears here.
  function paintZones(selectors) {
    zoneLayer.textContent = '';
    if (!selectors || !selectors.length) return;

    for (var i = 0; i < selectors.length; i++) {
      var el = null;
      try {
        el = document.querySelector(selectors[i]);
      } catch (e) {}
      if (!el) continue;

      var r = rectOf(el);
      var mark = document.createElement('div');
      mark.className = ZONE_MARK;
      mark.setAttribute('data-n', String(i + 1));
      mark.style.top = r.top + 'px';
      mark.style.left = r.left + 'px';
      mark.style.width = r.width + 'px';
      mark.style.height = r.height + 'px';
      zoneLayer.appendChild(mark);
    }
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

  // Insert a node relative to a target using the placement position semantics the storefront
  // widget uses (before | prepend | append | after-default). Shared by the ghost + banner preview.
  function insertAt(target, node, position) {
    var parentEl = target.parentNode;
    if (position === 'before' && parentEl) parentEl.insertBefore(node, target);
    else if (position === 'prepend') target.insertBefore(node, target.firstChild);
    else if (position === 'append') target.appendChild(node);
    else if (parentEl) parentEl.insertBefore(node, target.nextSibling);
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

    insertAt(target, ghost, position);

    if (ghost.scrollIntoView) ghost.scrollIntoView({ block: 'center' });
  }

  // Remove every injected banner preview (they live in the page flow, not an overlay layer).
  function clearBannerPreviews() {
    var nodes = document.querySelectorAll('.' + BANNER_PREV);
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].parentNode) nodes[i].parentNode.removeChild(nodes[i]);
    }
  }

  // Render the ACTUAL banner at each confirmed placement (selector + position) — a WYSIWYG preview
  // of where and how it appears on the store. An empty imageUrl (no artwork chosen yet) paints a
  // numbered placeholder block so the spot is still visible. Fully re-derived from the list each call.
  function paintBannerPreviews(imageUrl, placements) {
    clearBannerPreviews();
    if (!placements || !placements.length) return;

    var last = null;
    for (var i = 0; i < placements.length; i++) {
      var p = placements[i];
      if (!p || !p.selector) continue;

      var target = null;
      try {
        target = document.querySelector(p.selector);
      } catch (e) {}
      if (!target) continue;

      var wrap = document.createElement('div');
      wrap.className = BANNER_PREV;
      wrap.setAttribute('data-n', String(i + 1));

      if (imageUrl) {
        var img = document.createElement('img');
        img.src = imageUrl;
        img.alt = '';
        wrap.appendChild(img);
      } else {
        wrap.className += ' __trayon_banner_prev_empty';
      }

      insertAt(target, wrap, p.position);
      last = wrap;
    }

    if (last && last.scrollIntoView) last.scrollIntoView({ block: 'center' });
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
      var tag = { name: el.tagName.toLowerCase(), id: el.id || null, classes: classListOf(el) };

      if (mode === MODE_ROLE) {
        // Role mode: mark WHERE a product detail is read from. Highlight the pick
        // persistently (no ghost button) and report it for server-side verify.
        send('pick', { mode: MODE_ROLE, role: currentRole, selector: lastSelector, rect: rectOf(el), tag: tag });
        showPicked(el);
        return;
      }

      if (mode === MODE_ZONE) {
        // Zone mode: ACCUMULATE this pick into the current price zone. Just report it —
        // the parent re-verifies server-side and echoes back the confirmed set via
        // setZones, which repaints the marks. The picker never decides what's stored.
        send('pick', { mode: MODE_ZONE, role: currentRole, selector: lastSelector, rect: rectOf(el), tag: tag });
        return;
      }

      // Placement mode (default): choose where the Tray On button goes.
      send('pick', { mode: 'element', selector: lastSelector, position: 'after', rect: rectOf(el), tag: tag });
      drawGhost(lastSelector, 'after', currentLabel);
    },
    true,
  );

  // Defensive: block any form submit inside the preview.
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

  // Admin page → picker: switch mode, move/redraw the ghost, or clear.
  window.addEventListener('message', function (e) {
    var d = e.data;
    if (!d || d.source !== PARENT) return;
    if (d.type === 'setMode') {
      // Switch between placement, role, + zone picking; reset the per-mode chrome so
      // stale visuals from another mode never linger.
      if (d.mode === MODE_ROLE) mode = MODE_ROLE;
      else if (d.mode === MODE_ZONE) mode = MODE_ZONE;
      else mode = MODE_PLACEMENT;
      currentRole = d.role || null;
      clearGhost();
      showPicked(null);
      paintZones([]);
      clearBannerPreviews();
    } else if (d.type === 'setZones') {
      // The parent's confirmed, server-verified selector list for the open zone.
      paintZones(d.selectors || []);
    } else if (d.type === 'setBannerPreview') {
      // The parent's confirmed placements + the chosen banner image → render the real banner at
      // each spot (WYSIWYG). Repaints wholesale, so a removed/reordered placement is reflected.
      paintBannerPreviews(d.imageUrl || '', d.placements || []);
    } else if (d.type === 'label') {
      currentLabel = d.label || currentLabel;
    } else if (d.type === 'setGhost') {
      if (d.label) currentLabel = d.label;
      drawGhost(d.selector || lastSelector, d.position || 'after', currentLabel);
    } else if (d.type === 'clear') {
      clearGhost();
      showPicked(null);
      paintZones([]);
      clearBannerPreviews();
    }
  });

  send('ready');
})();
