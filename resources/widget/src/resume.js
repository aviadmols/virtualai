// === CONSTANTS ===
// The cross-page / cross-tab RESUMER. Runs on EVERY page load — including NON-PDP pages where
// no button mounts — to reconnect the shopper to a try-on they started elsewhere. On init it
// checks localStorage for a FRESH pending generation; if present it ensures a minimal shell +
// notification mount exist and RESUMES polling in the background (reusing generation.poll), then
// shows the existing on-page "ready" popup and clears the entry. It also joins the cross-tab
// channel so a completion detected by ANY tab notifies the others and stops their redundant poll.
// Fully lazy/async: never blocks page render, never throws on a page with no product.

import { GEN_STATUS, PENDING_PHASE, PENDING_MSG } from './constants.js';
import { state } from './state.js';
import { warn } from './dom.js';
import { pollOnce, cancelResumePoll } from './generation.js';
import * as pending from './pending.js';
import * as shell from './shell.js';
import * as modal from './modal.js';

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

  // Make sure the anon token is available to the poll + a later reopen (the loader sets it on a
  // PDP; on a non-PDP page we take it from the persisted handle).
  if (!state.anonToken) state.anonToken = entry.anonToken || null;

  // Ensure a shell exists so the notification has a mount. On a PDP the loader already built it;
  // on a non-PDP page we build a MINIMAL one (default appearance) that only hosts the popup.
  if (!booted) ensureMinimalShell();

  // Already finished (this tab left mid-generation and the poll completed elsewhere, or another
  // tab wrote the result): show the popup straight away — do NOT re-poll.
  if (entry.phase === PENDING_PHASE.done) {
    showReady(entry);
    return;
  }
  if (entry.phase === PENDING_PHASE.failed) {
    modal.notifyOutcomeFromResume(false, entry.generationId);
    return;
  }

  // Still active: resume polling in the background.
  void backgroundPoll(entry);
}

/** Build a minimal shell (default appearance) used only to host the cross-page notification. */
function ensureMinimalShell() {
  try {
    shell.create(state.config?.appearance || {});
  } catch {
    warn('failed to build the minimal notification shell');
  }
}

/** Poll the status endpoint to a terminal state, then persist + notify. Bounded by poll(). */
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
    modal.notifyOutcomeFromResume(false, entry.generationId);
  }
}

/** Show the on-page "ready" popup for a completed entry (seeds lastResult so a click reopens it). */
function showReady(entry) {
  state.lastResult = {
    generationId: entry.generationId,
    resultUrl: entry.resultUrl || null,
    variant: null, // a cross-page resume has no live variant; add-to-cart is offered on the PDP only
  };
  modal.notifyOutcomeFromResume(true, entry.generationId);
}

/** React to a message from ANOTHER tab (done/failed → notify + stop our poll; viewed/dismissed → clear). */
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
    const entry = pending.read();
    if (entry) modal.notifyOutcomeFromResume(false, entry.generationId);
    return;
  }
  if (type === PENDING_MSG.viewed || type === PENDING_MSG.dismissed) {
    // The shopper saw/dismissed it in another tab — clear this tab's popup, no zombie notices.
    modal.clearNotificationFromResume();
  }
}

/** Teardown (SPA navigation away): close the cross-tab channel. */
export function teardown() {
  pending.disconnect();
}
