// === CONSTANTS ===
// The CLUB chunk's entry (widget.club.js): the customer club (join banner + email OTP login +
// display-only member pricing) and the merchant banners.
//
// It is fetched on IDLE, not on the first paint, and ONLY on a site that actually has a club or a
// banner configured. Neither surface is on the LCP path — but both are site-wide, so they cannot
// wait for a click either. Idle is exactly the right moment: the merchant's page has painted, and
// the shopper has not been made to pay for a feature they may never see.
//
// A self-contained IIFE that registers itself with the core through the ONE namespaced global.

import { NAMESPACE, CHUNK, CHUNK_READY_FN } from '../constants.js';
import { state, shell, siteKey, extendMessages } from './bridge.js';
import { CLUB_MESSAGES } from './i18n-club.js';
import * as club from './club.js';
import * as banners from './banners.js';
import { warn } from '../dom.js';

extendMessages(CLUB_MESSAGES);

/**
 * Wire both runtimes. `onPdp` tells us whether the loader already built the Shadow shell (the club
 * banner + login need one; the merchant banners bring their own per-spot shadow roots).
 */
function init({ onPdp }) {
  club.configure(siteKey); // site-scoped member flag + dismissal (like the anon token)
  banners.configure(siteKey); // site-scoped seen flag + per-session impression counters

  if (state.club && state.club.enabled) {
    if (!onPdp) {
      try {
        shell.create(state.config?.appearance || {});
      } catch {
        warn('failed to build the minimal club shell');
      }
    }
    try {
      club.init(state.club);
    } catch {
      warn('failed to initialise the customer club');
    }
  }

  if (state.banners && state.banners.length) {
    try {
      banners.init(state.banners);
    } catch {
      warn('failed to initialise merchant banners');
    }
  }
}

function teardown() {
  try {
    club.teardown();
  } catch {
    warn('club teardown failed');
  }
  try {
    banners.teardown();
  } catch {
    warn('banner teardown failed');
  }
}

window[NAMESPACE][CHUNK_READY_FN](CHUNK.club, { init, teardown });
