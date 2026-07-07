## 2026-07-07T00:00Z — Banner module PHASE 5 (widget runtime + analytics + bootstrap) — VERDICT: GREEN (PASS-WITH-SUGGESTIONS, applied N3)

Reviewer: code-review-gatekeeper
Scope: the storefront widget banner runtime + per-banner analytics + the bootstrap `banners` payload.
Units: BootstrapController::bannersPayload (+ clubPayload→resolveClubBlock refactor) · BannerEventRecorder ·
BannerEventController + BannerEventRequest + routes/widget.php · BannerResource clicks/impressions/CTR
columns (withCount) · resources/widget/src/{banners.js(NEW),constants.js,api.js,state.js,loader.js} ·
widget.css (.ton-banner*) · tests/Feature/Widget/{WidgetBannerBootstrap,WidgetBannerEvent}Test.php ·
tests/widget/verify.mjs (banner gate).

Tests: full PHP suite 737 passed (2470 assertions); `--filter=WidgetBanner` 6 passed; `node
tests/widget/verify.mjs` ALL WIDGET GATES PASSED incl. the banner gate (inject at picked spot / overlay
crisp text / club-members rule hides from a non-member / one impression per shown banner / click beacon).
Bundle 25,221 B gz (budget 25,600).

CONTRACT VERIFICATION (gatekeeper, with sweeps + proof):
- TENANT-SAFETY (RELEASE BLOCKER) — GREEN. bannersPayload queries Banner::where('site_id',…)->active() on
  top of the BelongsToAccount global scope (account bound from the resolved WidgetContext, never the body);
  BannerEventRecorder writes ONLY when the banner belongs to the bound site (account scope + site_id + key)
  — a forged foreign banner_id records nothing. Proven by site-isolation tests (boot/POST as a second shop
  → [] / count 0). No withoutGlobalScopes.
- XSS / INJECTION (CRITICAL for a storefront widget) — GREEN. Merchant overlay text is rendered via
  el('span',{text}) → textContent only; ZERO innerHTML/insertAdjacentHTML/html: in banners.js. target_url is
  server-validated http(s)-only (BannerContent, FILTER_VALIDATE_URL + scheme allow-list — no javascript:/data:).
  Placement selectors reach only safeQuery()→try/catch querySelector, never eval. Dynamic accent uses
  style.setProperty (break-out-safe).
- WIDGET ISOLATION + PERF — GREEN. Each banner mounts in its OWN shadow root on an all:initial host-DOM
  wrapper (button.js pattern) — zero host-CSS bleed either way; <img> width/height reserves the box
  (CLS-safe re: image load); everything idle-scheduled + fail-soft (never throws into the host); one
  impression per banner per page-load + a session frequency cap; MutationObserver self-heal + teardown.
- NO SECRETS — GREEN. The beacon carries only banner_id/kind/anon_token/path + the public site_key.
- CONVENTIONS — GREEN. CONST-at-top; the widget adds NO new i18n (banner text is merchant data); the three
  merchant-panel stat columns have EN/HE parity. BannerResource counts are withCount subqueries (no N+1).
- MONEY/AI — N/A (analytics move no money).

FINDINGS: 0 BLOCKING. 2 SUGGESTION + 1 NIT.
- N3 (APPLIED): removed the dead BANNER_IDLE_MS constant.
- S1 (DEFERRED, documented): the widget entry bundle is at ~98% of the 25,600-byte gz budget after banners.
  Follow-up: move the banner runtime into the lazy chunk (like modal/result) so future widget features fit.
  Handed to troubleshooting-archivist as a recurring watch-item. Not a Phase-5 defect (under budget).
- S2 (NOTED): banner injection inherently shifts subsequent host content — this is the merchant's intentional
  placement, not a CLS leak; the <img> width/height already prevents the image-load shift.

GATE: GREEN — 0 blocking. The Banners module is COMPLETE end-to-end (generate → edit → place → target →
render + measure), tenant-isolated, XSS-safe, shadow-isolated, and under the widget budget.
