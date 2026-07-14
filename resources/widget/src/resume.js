// === CONSTANTS ===
// The cross-page / cross-tab RESUMER. Runs on EVERY page load — including NON-PDP pages where no
// trigger mounts — to reconnect the shopper to a look they started elsewhere. On init it checks
// localStorage for a FRESH pending generation; if present it ensures a minimal shell exists and
// RESUMES polling in the background, then shows the floating HUD. It also joins the cross-tab
// channel so a completion detected by ANY tab notifies the others and stops their redundant poll.
//
// This is the whole reason the HUD is CORE: on a page with no product there is no trigger to
// click, and the HUD is the shopper's only tether to the look being made for them.
//
// Fully lazy/async: never blocks page render, never throws on a page with no product. Tapping the
// HUD is the ONLY thing that fetches the modal chunk — we never auto-open a modal on a stranger's
// page, and we never pay for the modal on a page the shopper is just passing through.

import { NAMESPACE, KERNEL_KEY, GEN_STATUS, PENDING_PHASE, PENDING_MSG, HUD } from './constants.js';
import { state } from './state.js';
import { warn } from './dom.js';
import { pollOnce, cancelResumePoll } from './generation.js';
import * as pending from './pending.js';
import * as shell from './shell.js';
import * as hud from './hud.js';

let resuming = false; // guards our own background poll (only one per page)

/**
 * Resume a cross-page/cross-tab pending try-on on page load. Safe to call on any page (PDP or
 * not). `booted` is true when the loader already built the PDP shell + mount (so we reuse it).
 */
export async function resumeOnLoad(booted) {
  // Always join the cross-tab channel so completions elsewhere reach this page.
  pending.connect(onRemoteMessage);

  const entry = pending.readFresh();
  if (!entry) return; // nothing pending (or already viewed / expired)

  // The anon token must be available to the poll + a later reopen (the loader sets it on a PDP;
  // on a non-PDP page we take it from the persisted handle).
  if (!state.anonToken) state.anonToken = entry.anonToken || null;

  // Ensure a shell exists so the HUD has a mount. On a PDP the loader already built it; on a
  // non-PDP page we build a MINIMAL one that only hosts the HUD. shell.create is idempotent.
  if (!booted) ensureMinimalShell();

  // Already finished (this tab left mid-generation and the poll completed elsewhere, or another
  // tab wrote the result): show the HUD straight away — do NOT re-poll.
  if (entry.phase === PENDING_PHASE.done) {
    showReady(entry);
    return;
  }
  if (entry.phase === PENDING_PHASE.failed) {
    showFailed();
    return;
  }

  // Still active: the look is being made right now. Say so, and poll in the background.
  hud.show(HUD.thinking, { onClick: openModal });
  void backgroundPoll(entry);
}

function ensureMinimalShell() {
  try {
    shell.create(state.config?.appearance || {});
  } catch {
    warn('failed to build the minimal HUD shell');
  }
}

/** Poll the status endpoint to a terminal state, then persist + surface the HUD. */
async function backgroundPoll(entry) {
  if (resuming) return;
  resuming = true;

  let outcome;
  try {
    outcome = await pollOnce(entry.generationId, entry.anonToken);
  } catch {
    outcome = { status: GEN_STATUS.failed };
  }
  resuming = false;

  // Another tab may have resolved + broadcast while we polled — re-check freshness.
  const still = pending.readFresh();
  if (!still) return;

  if (outcome.status === GEN_STATUS.succeeded && outcome.resultUrl) {
    pending.markDone(entry.generationId, outcome.resultUrl);
    showReady({ generationId: entry.generationId, resultUrl: outcome.resultUrl });
  } else {
    pending.markFailed(entry.generationId);
    showFailed();
  }
}

/**
 * The look is ready. Seed lastResult from the persisted handle so a tap can reopen it — the
 * captured signed URL may have passed its ~10-minute TTL, so the modal re-fetches a fresh one.
 * It NEVER auto-opens the modal: hijacking a stranger's page is unforgivable.
 */
function showReady(entry) {
  state.lastResult = {
    generationId: entry.generationId,
    resultUrl: entry.resultUrl || null,
    variant: null, // a cross-page resume has no live variant; add-to-cart is offered on the PDP
  };
  state.looksCount += 1;
  hud.show(HUD.ready, { onClick: openModal });
}

function showFailed() {
  hud.show(HUD.failed, { onClick: openModal });
}

/** Tapping the HUD is what fetches the modal chunk. The core never does it on its own. */
function openModal() {
  const kernel = window[NAMESPACE] && window[NAMESPACE][KERNEL_KEY];
  if (kernel && kernel.openModal) kernel.openModal();
}

/** React to a message from ANOTHER tab (done/failed → HUD + stop our poll; viewed/dismissed → clear). */
function onRemoteMessage(type) {
  if (type === PENDING_MSG.done) {
    cancelResumePoll(); // stop our redundant poll: the other tab already resolved it
    resuming = false;
    const entry = pending.read();
    if (entry && entry.phase !== PENDING_PHASE.viewed) showReady(entry);
    return;
  }
  if (type === PENDING_MSG.failed) {
    cancelResumePoll();
    resuming = false;
    showFailed();
    return;
  }
  if (type === PENDING_MSG.viewed || type === PENDING_MSG.dismissed) {
    // The shopper saw/dismissed it in another tab — clear this tab's HUD. No zombie badges.
    hud.clear();
  }
}

/** Teardown (SPA navigation away): close the cross-tab channel. */
export function teardown() {
  pending.disconnect();
}
