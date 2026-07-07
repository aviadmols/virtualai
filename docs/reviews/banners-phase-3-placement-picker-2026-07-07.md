
## 2026-07-07T00:00Z — Banners Phase 3 (Visual Placement Picker) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Branch: feat/banners (uncommitted)
Scope: app/Domain/Banners/BannerPlacements.php (NEW), app/Domain/Banners/BannerService.php (updatePlacements),
  app/Domain/Banners/InvalidBannerException.php (REASON_INVALID_PLACEMENTS),
  app/Filament/Merchant/Pages/BannerPlacements.php (NEW),
  resources/views/filament/merchant/pages/banner-placements.blade.php (NEW),
  app/Filament/Merchant/Resources/BannerResource.php (overlay-section visibility fix + plural label),
  app/Filament/Merchant/Resources/BannerResource/Pages/EditBanner.php (Placements header action),
  lang/{en,he}/banners.php (placements block),
  tests/Feature/Banners/BannerPlacementsTest.php, tests/Feature/Filament/Merchant/BannerPlacementsPageTest.php
Sweeps run: withoutGlobalScopes (clean) · style=/arbitrary-tailwind (clean) · eval/Blade::render/DB:: (clean) ·
  hardcoded-model-id (clean) · en/he key parity 102=102 (no missing/extra)
Tests run: php artisan test --filter=BannerPlacements -> 10 passed (26 assertions)
Rail parity: BannerPlacements page is a faithful clone of the already-GREEN ClubSettings rail
  (PreviewFetcher/PreviewSnapshotStore + SelectorTester resolves-to-one + picker.js ZONE mode +
  sandbox="allow-scripts" only + site-namespaced cache key). Reused CSS component classes
  (to-place-*/to-zone__*/to-select/to-field__control/to-btn) all present. picker.js setMode/setZones/pick
  ZONE contract matches the blade Alpine wiring.
Tenant-safety: PASS — mount() + banner() load the banner scoped by where('site_id', siteId) on top of the
  BelongsToAccount global scope; preview cache key namespaced 'banner_preview:{siteId}:{token}';
  latestScannedProduct + rate-limiter key both site-scoped. Foreign-account banner does not bind (tested).
Selector-safety: PASS — pick verified SERVER-SIDE (SelectorTester resolves-to-one over ScanDom, count===1),
  allow-list SELECTOR_PATTERN + length(500) + MAX(8) enforced at the single writer (BannerPlacements::sanitize);
  selector only ever COUNTED as a DOM query, never eval'd; displayed via escaped {{ }}; echoed into a
  sandboxed (no same-origin) iframe. Nothing unsafe can reach storage — fail-closed at the writer.
Sandbox: PASS — iframe sandbox="allow-scripts" ONLY, referrerpolicy=no-referrer, srcdoc from sanitized cache.
Filament correctness: PASS — save() routes through BannerService::updatePlacements (single validated writer),
  InvalidBannerException surfaced as a soft danger notification (never a 500); page shouldRegisterNavigation=false.
Blocking: (none)
Suggestions:
  #1 tests/Feature/Filament/Merchant/BannerPlacementsPageTest.php:129 — the isolation test uses a foreign
     ACCOUNT (proves the release-blocker account boundary). Add a same-account / second-Site banner case so the
     test isolates that the explicit where('site_id') guard (not only the account scope) rejects the bind.
Nits:
  #2 previewSource 'snapshot'/'live' and pickVerdict reason tokens are string literals (map to i18n keys) rather
     than named consts — identical to the GREEN ClubSettings pattern, noted for parity only.
Re-review: not required (no blockers). Owner (laravel-backend) may action #1 at discretion.
Recurring -> archivist: none new.
