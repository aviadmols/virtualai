// === CONSTANTS ===
// Submit + idempotency + status polling. The client_request_id is generated ONCE per intent
// and reused verbatim on every retry of that intent so a double-click / retry collapses to
// ONE server generation+charge (the server's idempotency key includes it). A new intent
// (regenerate / change photo) gets a new id. We disable submit on first click (UI guard);
// the server is the real guard. We render only the TYPED server states — never any balance,
// cost, markup, or model id (none of which the server sends to the browser anyway).

import {
  GEN_STATUS,
  GATE_REASON,
  POLL_INTERVAL_MS,
  MAX_POLLS,
} from './constants.js';
import { state } from './state.js';
import { uuid } from './dom.js';
import * as api from './api.js';
import * as pending from './pending.js';

const SLEEP = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Result codes the modal/result screens render (never expose server internals). */
export const OUTCOME = {
  succeeded: 'succeeded',
  failed: 'failed',
  timeout: 'timeout',
  signupRequired: 'signup_required',
  postSignupLimit: 'post_signup_limit', // registered, but out of post-signup tries — NOT a signup loop
  outOfCredits: 'out_of_credits',
  rateLimited: 'rate_limited',
  network: 'network',
  invalid: 'invalid',
};

let activePoll = { cancelled: false };

/**
 * Submit a try-on. Returns a typed outcome object the caller renders.
 * @param {{ photo, height, extra }} inputs
 * @returns {Promise<{outcome, resultUrl?, generationId?, retryAfter?, freeRemaining?}>}
 */
export async function submit(inputs) {
  if (state.submitting) return { outcome: OUTCOME.invalid };
  state.submitting = true;

  // Generate the per-intent id ONCE; reuse on retry of the SAME intent.
  state.clientRequestId = state.clientRequestId || uuid();

  const res = await api.createGeneration({
    photo: inputs.photo,
    height: inputs.height,
    productId: state.product.id,
    // A single-SKU product has no variant (state.variant is null) — send 0; the server
    // treats it as "no variant" and generates against the product's main image.
    variantId: state.variant ? state.variant.id : 0,
    clientRequestId: state.clientRequestId,
    consent: true,
    anonToken: state.anonToken,
    extra: inputs.extra || {},
  });

  // --- Typed, non-success outcomes (never a charge, never a 500) ---
  if (res.networkError) {
    state.submitting = false;
    return { outcome: OUTCOME.network };
  }

  // A gate denial: { ok:false, blocked:true, reason, retry_after?, ... }
  if (res.data && res.data.blocked) {
    state.submitting = false;
    return mapGate(res.data, res.status);
  }

  // A typed error envelope (validation / not found).
  if (!res.ok || !res.data || res.data.ok !== true) {
    state.submitting = false;
    return { outcome: OUTCOME.failed };
  }

  const generationId = res.data.generation?.id;
  const freeRemaining = res.data.free_remaining;

  // Persist the pending generation NOW (id known) so the shopper can close the popup, keep
  // browsing (other pages / tabs), and still be notified when it finishes. Handles only — no
  // photo/PII beyond what's already client-side (the result_url is added on completion).
  pending.start({ generationId, anonToken: state.anonToken, productId: state.product?.id });

  // Poll the status machine to a terminal state.
  const polled = await poll(generationId);
  state.submitting = false;

  // Reflect the terminal state into the persisted entry + broadcast to other tabs.
  if (polled.outcome === OUTCOME.succeeded) {
    pending.markDone(generationId, polled.resultUrl);
  } else if (polled.outcome === OUTCOME.failed || polled.outcome === OUTCOME.timeout) {
    pending.markFailed(generationId);
  }

  return { ...polled, generationId, freeRemaining };
}

/** Poll GET /generations/{id} until a terminal state or the bounded timeout. */
async function poll(generationId) {
  activePoll = { cancelled: false };
  const ticket = activePoll;

  for (let attempt = 0; attempt < MAX_POLLS; attempt++) {
    if (ticket.cancelled) return { outcome: OUTCOME.failed };

    const res = await api.getGeneration(generationId, state.anonToken);

    if (res.ok && res.data?.ok && res.data.generation) {
      const g = res.data.generation;

      if (g.status === GEN_STATUS.succeeded && g.result_url) {
        return { outcome: OUTCOME.succeeded, resultUrl: g.result_url };
      }
      if (g.status === GEN_STATUS.failed || g.status === GEN_STATUS.cancelled) {
        return { outcome: OUTCOME.failed };
      }
    }

    await SLEEP(POLL_INTERVAL_MS);
  }

  return { outcome: OUTCOME.timeout };
}

/** Cancel an in-flight poll (modal closed / new intent). */
export function cancelPoll() {
  activePoll.cancelled = true;
}

// ---------------------------------------------------------------------------
// Cross-page resume poll — reuses the same signed status endpoint (site_key + Origin +
// the persisted anonToken). Independent of the modal's activePoll so a page-load resume
// and an open modal never fight. Returns a RAW terminal shape the resumer maps to a popup.
// ---------------------------------------------------------------------------
let resumePoll = { cancelled: false };

/**
 * Poll GET /generations/{id} to a terminal state for a RESUMED (cross-page/cross-tab) try-on.
 * @returns {Promise<{status, resultUrl?}>}
 */
export async function pollOnce(generationId, anonToken) {
  resumePoll = { cancelled: false };
  const ticket = resumePoll;

  for (let attempt = 0; attempt < MAX_POLLS; attempt++) {
    if (ticket.cancelled) return { status: GEN_STATUS.cancelled };

    const res = await api.getGeneration(generationId, anonToken);

    if (res.ok && res.data?.ok && res.data.generation) {
      const g = res.data.generation;
      if (g.status === GEN_STATUS.succeeded && g.result_url) {
        return { status: GEN_STATUS.succeeded, resultUrl: g.result_url };
      }
      if (g.status === GEN_STATUS.failed || g.status === GEN_STATUS.cancelled) {
        return { status: GEN_STATUS.failed };
      }
    }

    await SLEEP(POLL_INTERVAL_MS);
  }

  return { status: GEN_STATUS.failed }; // timeout → treat as "didn't finish"
}

/** Cancel an in-flight resume poll (another tab already resolved it). */
export function cancelResumePoll() {
  resumePoll.cancelled = true;
}

/** Map a typed gate denial to a render outcome. */
function mapGate(data, status) {
  switch (data.reason) {
    case GATE_REASON.signupRequired:
      return { outcome: OUTCOME.signupRequired };
    // A REGISTERED user who hit the post-signup cap: a distinct terminal state, never the
    // signup form again (that would loop — the user already signed up).
    case GATE_REASON.postSignupLimit:
      return { outcome: OUTCOME.postSignupLimit };
    case GATE_REASON.insufficientCredits:
    case GATE_REASON.accountInactive:
      return { outcome: OUTCOME.outOfCredits };
    case GATE_REASON.rateLimited:
      return { outcome: OUTCOME.rateLimited, retryAfter: data.retry_after };
    default:
      return { outcome: OUTCOME.failed };
  }
}
