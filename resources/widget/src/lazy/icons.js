// === CONSTANTS ===
// The MODAL chunk's icon set. Inline SVG strings — no icon font, no sprite, no third-party fetch.
// None of these is directional (an upload arrow points UP, a share graph has no handedness), so
// none of them is flipped in RTL. Only a genuinely directional glyph would take
// `.ton-glyph--directional`.

const STROKE =
  'fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';

export const ICON_UPLOAD =
  `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" ${STROKE}>` +
  '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />' +
  '<polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>';

export const ICON_SAVE =
  `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" ${STROKE}>` +
  '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />' +
  '<polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>';

export const ICON_SAVED =
  `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" ${STROKE}>` +
  '<polyline points="20 6 9 17 4 12" /></svg>';

export const ICON_SHARE =
  `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" ${STROKE}>` +
  '<circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" />' +
  '<line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />' +
  '<line x1="15.41" y1="6.51" x2="8.59" y2="10.49" /></svg>';

export const ICON_REGEN =
  `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" ${STROKE}>` +
  '<polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" />' +
  '<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>';

// A magnifier with a plus; the vertical stroke is classed so it hides (becoming a minus) while
// the result is zoomed. Not directional — never flipped in RTL.
export const ICON_ZOOM =
  `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" ${STROKE}>` +
  '<circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" />' +
  '<line x1="8" y1="11" x2="14" y2="11" />' +
  '<line class="ton-zoom__plus-v" x1="11" y1="8" x2="11" y2="14" /></svg>';
