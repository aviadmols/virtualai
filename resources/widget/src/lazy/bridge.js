// === CONSTANTS ===
// The lazy chunk's window onto the CORE.
//
// A chunk is its own IIFE bundle, so anything it imported from ../state.js or ../i18n.js would be
// a SECOND COPY: a second state object nobody writes to, a second i18n table with no locale, a
// second api client with no site_key. Every stateful singleton therefore comes from the kernel,
// by reference. Only genuinely stateless modules (constants, dom) are imported directly — a
// duplicated pure function costs a few bytes in a chunk nobody pays for until they engage.

import { NAMESPACE, KERNEL_KEY } from '../constants.js';

const k = (window[NAMESPACE] || {})[KERNEL_KEY] || {};

export const state = k.state;
export const newIntent = k.newIntent;
export const api = k.api;
export const shell = k.shell;
export const panel = k.panel;
export const button = k.button;
export const hud = k.hud;
export const pending = k.pending;
export const track = k.track;
export const gen = k.gen;
export const chunks = k.chunks;
export const siteKey = k.siteKey;

export const t = (key, replacements) => k.i18n.t(key, replacements);
export const tries = (count) => k.i18n.tries(count);
export const isRtl = () => k.i18n.isRtl();
export const extendMessages = (messages) => k.i18n.extend(messages);
