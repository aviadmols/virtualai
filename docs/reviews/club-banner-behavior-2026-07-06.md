## 2026-07-06T00:00Z — Customer-Club banner behavior + timing — VERDICT: GREEN

Reviewer: adversarial multi-dimension review (5 independent reviewers → per-finding skeptic verify)
Scope: the merchant-configurable JOIN-BANNER behavior/timing feature — 5 new ClubConfig fields
(banner_trigger, banner_delay_seconds, banner_scroll_percent, banner_position, banner_dismiss_days)
threaded through: app/Domain/Sites/ClubConfig.php · app/Http/Controllers/Widget/BootstrapController.php ·
app/Filament/Merchant/Pages/ClubSettings.php + club-settings.blade.php · resources/widget/src/{club,constants}.js ·
resources/widget/styles/widget.css · lang/{en,he}/club.php · tests (ClubConfigTest, ClubSettingsPageTest,
WidgetClubBootstrapTest, tests/widget/verify.mjs).

What shipped:
- WHEN the banner appears — `immediate` (default) | `delay` (0–60s) | `scroll` (1–100% page depth).
- WHERE it sits — 4 corners as LOGICAL sides (bottom/top × start/end) → CSS `inset-block`/`inset-inline`
  modifier classes so the chosen corner mirrors correctly in RTL.
- PERSISTENT DISMISSAL — the × now writes an epoch-ms expiry to a site-scoped localStorage key
  (`trayon.club.dismissed.<site_key>`); a live (unexpired) dismissal keeps the banner hidden ACROSS
  reloads for banner_dismiss_days (default 7). 0 days = session-only (no persistence). Expired entries self-clean.

Tests run (all green):
- php artisan test --filter=Club → 45 passed (172 assertions); Bootstrap|SiteSettings|WidgetAppearance → 38 passed.
- node tests/widget/verify.mjs → ALL WIDGET GATES PASSED, incl. the new behavior gate: dismissal persists across
  a reload; delay trigger is NOT immediate then appears; scroll trigger appears only past the depth; the
  position modifier class is applied. Bundle gz = 22,829 B (budget 25,600) — under budget with headroom. CLS = 0.0000.

FOCUS-AREA FINDINGS:
1. TENANT-SAFETY — PASS. The banner fields live on club_config (cast JSON on the account-scoped Site);
   ClubSettings binds Filament::getTenant() through BelongsToAccount (no manual where(account_id), no
   withoutGlobalScopes). BootstrapController reads the already-tenant-bound site. No cross-tenant read.
2. MONEY-SAFETY — PASS. Behavior/timing is pure display: no field enters any charge, checkout, cart, discount
   code, or the credit ledger. The discount stays DISPLAY-ONLY (unchanged by this diff).
3. PRIVACY/CONSENT — PASS. Dismissal state is an anonymous epoch-ms integer in localStorage — no new PII, no
   server round-trip, no consent surface. Club stays OFF by default; nothing flips consent.
4. CONVENTIONS — PASS. CONST-at-top upheld (PHP consts for keys/enums/ranges/defaults; widget constants.js
   enums + club.js MS_PER_SECOND/MS_PER_DAY named consts). ZERO inline CSS — merchant form reuses to-field/
   to-select token classes; the widget banner is class-only in its shadow DOM; the 4 position modifiers use
   logical insets only (RTL-safe). i18n en/he 1:1 for the new `behavior` block (position labels are physical
   per the store's direction). No secrets.
5. WIDGET PERF/CORRECTNESS — PASS. Scroll listener is passive + removed after firing and on teardown (no leak);
   the delay timer is cleared on show/teardown; a teardown-cancelled pending trigger does NOT persist a
   dismissal; every path is fail-soft (never throws into the host); position/trigger read defensively (bad
   value → default). resolve() is lenient — a corrupted stored enum / out-of-range int falls back to the locked
   default and never reaches the widget.

Findings: 4 raised → 1 CONFIRMED (SUGGESTION), 3 REFUTED (taste/NIT).
- CONFIRMED + APPLIED: the real bootstrap HTTP seam did not assert the 5 banner_* keys are emitted
  (WidgetClubBootstrapTest hit only enabled/discount/zones/member) — a dropped key in clubPayload() would have
  shipped green. FIX: extended test_bootstrap_returns_the_resolved_club_config to store + assert all five
  banner fields end-to-end. Re-run: 4 passed.
- REFUTED (correctly): reused entrance keyframe animates from-below for top-* corners (self-declared NIT, taste);
  no coverage for the dismiss_days=0 session-only branch (speculative mutation-style, code is correct);
  defaults test asserts int-type not the literal 3/25 (test-taste NIT). None violate the locked contract.

Recurring → archivist: none new.
GATE: GREEN — 0 blocking across tenant-safety, money-safety, privacy/consent, conventions, widget perf. The
feature is display-only, tenant-isolated, consent-neutral, fail-soft, under the gzip budget, and RTL-correct.
