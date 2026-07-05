## 2026-07-05T00:00:00Z — Feature: Visual button-placement picker — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope (uncommitted working tree):
  - app/Domain/Sites/WidgetAppearance.php (PLACEMENT_CUSTOM + custom keys + POSITIONS + validSelector allow-list + conditional-required)
  - app/Domain/Scan/Preview/{PreviewFetcher,PreviewSanitizer,PreviewResult}.php
  - app/Filament/Merchant/Pages/WidgetAppearanceSettings.php (loadPreview/verifyPick/applyPick/useFloatingCorner, cache-backed preview, RateLimiter, Hidden fields)
  - resources/views/filament/merchant/pages/widget-appearance-settings.blade.php (overlay + sandboxed iframe + postMessage bridge)
  - resources/widget/picker/picker.js (in-iframe picker)
  - resources/widget/src/{constants,button,mount,dom}.js (custom-placement runtime + fallback)
  - resources/css/filament/shared/components/place-visually.css + merchant/theme.css import
  - lang/en/appearance.php + lang/he/appearance.php
  - tests: WidgetAppearanceCustomPlacementTest, PreviewSanitizerTest, PreviewFetcherTest, WidgetPlacementPickerTest; tests/widget/verify.mjs (custom placement gate)

Sweeps run:
  - withoutGlobalScope (clean) · Blade::render/eval on preview or selector (clean) · raw DB:: on tenant tables (clean)
  - inline style= in admin blade (clean) · selector rendered only via @js/{{ }} (escaped) · SSRF: PreviewFetcher reuses guarded PageSource -> PageFetcherManager::fetch calls UrlGuard::assertFetchable (confirmed)
  - i18n en/he key-diff: 57 == 57, zero missing either direction · RTL physical-direction props in new CSS (clean, logical only)
  - raw color literals in shared filament CSS tree: 1 hit (place-visually.css:34 overlay scrim), no --to-scrim token exists

Tests run: php artisan test --filter "PreviewSanitizer|WidgetAppearanceCustomPlacement|WidgetPlacementPicker|PreviewFetcher" -> 25 passed (55 assertions)
Widget bundle: public/widget/v1/widget.js -> 16229 bytes gz (< 20 KB budget; picker.js runs in-iframe, not in the storefront bundle)

Tenant-safety (release blocker) — PASS:
  - mount()/site() resolve Site via account-scoped BelongsToAccount (fail-closed AccountScope); merchant panel binds account via persistent BindMerchantAccount tenant middleware. A cross-account ?site= id returns null -> hasSite=false -> picker cannot run.
  - Preview cache key widget_preview:{siteId}:{sha1(url)} + limiter widget-preview:{siteId} are namespaced by the merchant's OWN account-scoped site id; no cross-tenant read possible.
  - No withoutGlobalScope in feature code (the only bypass is the audited MerchantSiteTenancy::resolveBySlug seam, unchanged by this feature).

Preview security — PASS:
  - sandbox="allow-scripts" WITHOUT allow-same-origin (opaque origin; cannot touch admin session). srcdoc emitted via Blade {{ }} (htmlspecialchars), never Blade::render.
  - PreviewSanitizer strips script/noscript/template/iframe/object/embed/applet, on*-handlers (all quote styles), javascript: URIs, meta-refresh, merchant <base>; keeps <style>/<link>. base href html-escaped (test proves quote can't break out).
  - Stored custom_anchor_selector allow-listed (SELECTOR_PATTERN rejects < { } ; / ` -> no markup/comment/declaration smuggling); <=500 chars; only ever passed to querySelector / ScanDom count.
  - postMessage bridge validates e.source === frame.contentWindow + payload source tag on both ends; opaque-origin frame makes '*' targetOrigin acceptable.

Widget runtime — PASS: placeCustom() returns false on missing/malformed anchor -> place() falls back to add-to-cart; safeQuery never throws into host; inject() retries when neither anchor resolves. verify.mjs proves picked-anchor placement AND runtime fallback.

Suggestions (non-blocking):
  - #S1 place-visually.css:34 `background: rgb(0 0 0 / 55%)` — the sole raw color literal in the shared Filament CSS tree; file header claims "no literals". No --to-scrim token exists yet; add one (admin-design-system) so the scrim is tokenised.
  - #S2 No dedicated cross-tenant test for THIS page (Account B cannot read A's preview/site). Mechanism is fail-closed by construction and covered by tenancy-core isolation tests; a targeted test would harden the release-blocker surface (laravel-backend).

Re-review: not required to advance. Suggestions routed to admin-design-system (#S1) and laravel-backend (#S2).
Recurring -> archivist: none new. Reinforces the "sandbox allow-scripts WITHOUT allow-same-origin + strtr/{{ }} never Blade::render on merchant HTML" pattern.
