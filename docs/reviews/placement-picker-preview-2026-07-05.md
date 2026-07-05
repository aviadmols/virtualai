## 2026-07-05 - Placement picker: preview scanned product + soft-design - VERDICT: GREEN

Reviewer: code-review-gatekeeper
Commits: f978ec0 (Placement picker: preview the SCANNED product + fix 500) . 84431e6 (Design: soften the whole system)
Scope (code): app/Domain/Scan/Preview/{PreviewSnapshotStore,PreviewFetcher,PreviewSanitizer}.php .
  app/Domain/Scan/Represent/{PageRepresentation,RepresentationBuilder}.php . app/Domain/Scan/ScanProductJob.php .
  app/Filament/Merchant/Pages/{WidgetAppearanceSettings,ReviewProduct}.php .
  resources/views/filament/merchant/pages/{widget-appearance-settings,review-product}.blade.php .
  resources/css/filament/shared/components/place-visually.css . lang/{en,he}/{appearance,scan}.php .
  tests/Feature/{Scan/ScanProductJobTest,Filament/Merchant/WidgetPlacementPickerTest}.php .
  tests/Unit/Scan/Preview/PreviewSanitizerTest.php
Scope (design, token-only): resources/css/to/tokens.css . resources/css/filament/shared/components/buttons.css .
  resources/widget/styles/widget.css (+ before/after PNGs, tests/visual/soft-shots.mjs)

Sweeps run (changed surface):
  withoutGlobalScope (clean - only doc-comments describing the correct pattern) .
  DB::table/statement/select on tenant tables (clean) .
  Blade::render (clean - only doc-comments in unrelated files reaffirming strtr) .
  inline style= in the two changed blades (clean) . dd/dump/ray (clean) . TODO/FIXME (clean) .
  secrets openrouter/sk-or-/widget_secret/api_key in changed surface (clean)
Tests: not re-run (suite already green per orchestrator: 572 pass, widget gates + budget green).
  Read the four picker/scan tests + the isolation deep-link test; all meaningful (assert real state, not theatre).

=== 1. TENANT-SAFETY (release blocker) - PASS ===
- Product uses BelongsToAccount (app/Models/Product.php:5,27); account_id + site_id in fillable. Global scope
  fails closed (BelongsToAccount.php:84-94 sentinel 0 = no rows when unbound). No withoutGlobalScopes anywhere
  in the changed surface.
- PreviewSnapshotStore path() (PreviewSnapshotStore.php:86-96) derives the key ONLY from the product OWN
  (int) account_id / (int) site_id / source_url_hash. No request-supplied account/site/path component. A Product
  is only ever obtained via BelongsToAccount-scoped queries (WidgetAppearanceSettings latestScannedProduct
  :441-449 plain Product::query() under the bound account; ScanProductJob under TenantAwareJob Tenant::run).
  So the path can only ever address the caller OWN tenant space - no cross-tenant read possible.
- Disk write is PRIVATE: put() passes visibility=private (PreviewSnapshotStore.php:40); reads served via
  MEDIA_CDN_URL egress control (config/filesystems.php:58-68).
- Merchant panel binds the ACCOUNT as tenant for the whole request (BindMerchantAccount.php:35-44), account read
  from AUTH user / active shop only, never request body; fails closed. Preview cache key namespaced by the
  merchant OWN site id (WidgetAppearanceSettings.php:432-435).
- Proven by test: WidgetPlacementPickerTest test_a_foreign_site_deeplink_cannot_bind_another_account (:156-172)
  a ?site= deep-link to another account site is scoped out - hasSite=false, never binds/reads it. Meaningful:
  it would go red if mount() find() bypassed the scope.

=== 2. NO-500 GUARANTEE - PASS ===
Every Livewire entry point on WidgetAppearanceSettings is guarded to a soft message, never a 500:
  - mount() (:114-137): find()/first() are scoped reads; the ?pick=1 branch calls openPicker() which is itself
    fully try/catch-wrapped, so a bad param cannot 500 the page load.
  - openPicker() (:192-214): whole body in try{}catch(Throwable) - soft previewError + manual fallback.
  - loadPreview() (:226-265): bad-url guard returns; FetchException - merchantMessage(); Throwable - soft message.
  - verifyPick() (:302-332): ScanDom parse in try{}catch(Throwable) - soft warn verdict.
  - applyPick()/useFloatingCorner() (:335-363): pure in-memory state guards, no throw surface.
  - save() (:392-409): catch(InvalidSiteSettingsException | Throwable) - save-failed notice, no partial save.
  Root-cause of the original 500 (non-UTF-8 store HTML breaking Livewire per-render json_encode) is fixed at
  source: PreviewSanitizer toUtf8() (:53-76) transcodes then hard-drops invalid bytes so output is ALWAYS valid
  UTF-8 before it can reach a Livewire re-render.

=== 3. CONVENTIONS - PASS ===
- CONST-at-top: PreviewSnapshotStore + WidgetAppearanceSettings + ScanProductJob all open with a CONSTANTS block.
  No magic path/status strings mid-file (path segments, statuses, cache TTLs, rate limits all consts).
- No inline CSS in the two changed blades; place-visually.css is variable-backed only, logical properties for RTL
  (inset-*, border-block-end, margin-block-start, min-inline-size). Token reference block documented at file top.
- i18n via __() throughout both blades + the page. Key-diff: appearance.php en 59 / he 59 (0 missing either way);
  scan.php en 55 / he 55 (0 missing either way). New visual.* + scan.place.* keys present in both locales.
- Template safety: no Blade::render on merchant input introduced. The sanitized preview is embedded via Blade
  double-brace (HTML-escaped) into an iframe srcdoc with sandbox=allow-scripts (no allow-same-origin) +
  referrerpolicy - the correct isolation. PreviewSanitizer strips script/on-handlers/javascript:/meta-refresh/base
  and forces UTF-8.
- No secrets in the changed surface.

=== 4. SCAN CAPTURE CORRECTNESS - PASS ===
- ScanProductJob process() (:65-82) persists the Product first, THEN calls PreviewSnapshotStore put() fail-soft:
  put() catches Throwable internally and returns bool (never throws), so a snapshot write can never break a scan
  or alter scan status. The put() sits after persist() and outside the failure path; the only catch in process()
  is for FetchException. Confirmed by test_scan_stores_a_page_snapshot_for_the_visual_placement_picker.
- PageRepresentation.rawHtml carries the ORIGINAL fetched HTML: RepresentationBuilder build() sets rawHtml from
  fetch->html (RepresentationBuilder.php:29,53) - the pre-clean original, styles/links intact. rawHtml is NOT
  sent to the model (documented; toPromptText uses cleanedHtml only).

=== 5. DESIGN COMMIT (84431e6) - PASS ===
- Touches only .css/.png/.mjs - zero PHP/Blade (verified by name-only filter).
- Raw color/shadow/radius literals confined to tokens.css (its designated single-source role, 62 ins / 38 del).
  buttons.css: no raw literals added. widget.css: color literals added only on --ton-* declaration lines (its own
  token block), none in rule bodies. Token-only retune as described.

Blocking: NONE.
Suggestions:
  #S1 [SUGGEST] config/filesystems.php:61 - the s3 media disk has no bucket-level visibility=private default;
      privacy currently relies on each write passing visibility=private (PreviewSnapshotStore does). Consider a
      disk-level private default as defence-in-depth so a future writer that forgets the per-object flag still
      fails safe. Non-blocking: the snapshot write here is explicitly private and reads go via the CDN.
Nits: none.

GATE: GREEN - 0 blocking findings across both commits. Tenant-safety, no-500, scan-capture, conventions, and the
token-only design retune all pass with evidence; one non-blocking hardening suggestion (#S1). Phase may advance.
Re-review: not required.
Recurring to archivist: none new. Reaffirms the standing TS-TENANCY-001 pattern - jobs via TenantAwareJob, panel
via BindMerchantAccount, snapshot path derived from the row OWN account_id - worth keeping visible in the registry.
