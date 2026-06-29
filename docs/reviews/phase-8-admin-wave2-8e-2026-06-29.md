## 2026-06-29 — Phase 8 Wave 2 — 8e merchant UI (scan-review A4 + embed-code A5) — VERDICT: GREEN (PASS-WITH-SUGGESTIONS)

Reviewer: code-review-gatekeeper
Scope (8e UI, uncommitted): ReviewProduct.php (+ review-product.blade.php); SiteResource.php + ViewSite.php (+ site/view.blade.php); components/to/{embed-code,confidence-chip}.blade.php; css shared/components/{scan-review,embed-code}.css + merchant theme.css imports; lang/en|he/{scan,embed,sites}.php; ScanReviewAndEmbedTest.php + ScanReviewDemoSeeder.php.
Backend app/Domain/Scan/Review/* + app/Domain/Sites/* spot-checked for correct UI binding only (gated previously).

Sweeps run (scoped surface):
- inline style= → CLEAN · Tailwind arbitrary [..] → CLEAN · raw hex/px/rgb in the 2 new component CSS → CLEAN (token-only)
- physical-direction CSS (margin/padding/text-align left|right) → CLEAN · logical properties throughout
- Blade::render / withoutGlobalScope / where account_id in scoped PHP → CLEAN (only doc-comments naming the rule)
- widget_secret / openrouter / sk-or- in embed + site view + ViewSite → CLEAN (only doc-comments; nothing rendered/logged)
- i18n flatten+diff both directions: scan 52/52, embed 14/14, sites 35/35 → PARITY OK, 0 drift, no English-leak, real Hebrew

Tests run: php artisan test → 410 passed (1313 assertions). ScanReviewAndEmbedTest → 6 passed (15 assertions).
Boot: about OK (Laravel 11.54, PHP 8.4.15). Login pages register: merchant/login + platform/login both present.

### Tenant-safety (RELEASE BLOCKER) — PASS
- ReviewProduct holds SCALAR ids in Livewire state (siteId/productId int); Site/Product resolve on demand via Model::query()->findOrFail through the BelongsToAccount global scope. No serialized Eloquent model, NO manual where account_id, NO withoutGlobalScopes. (ReviewProduct.php:50-51, :96-107)
- Foreign-product isolation test mounts account B ids while bound to account A and expects ModelNotFoundException — goes red if the scope is removed. Meaningful, not theatre. (ScanReviewAndEmbedTest.php:130-144)
- Embed/regenerate account-scoped: ViewSite record-bound via the resource query; products() filters site_id on an account-scoped relation; SiteKeyRegenerator runs against the bound site. (ViewSite.php:61-67, :96-101)

### No-auto-approve (scan release rule) — PASS
- Confirm delegates to ConfirmScanAction::confirm(), which re-loads in tenant scope and calls assertGateOpen()->ConfirmGate::evaluate() SERVER-SIDE, throwing ScanConfirmBlockedException when a low/not_detected row is unreviewed. UI @disabled is not the only gate. (ReviewProduct.php:211-235; ConfirmScanAction.php:36-67)
- Tests prove it: blocked confirm() is a no-op leaving STATUS_DRAFT; confirm flips to STATUS_CONFIRMED only after every blockingKey is acknowledged. Red if the server-side gate is removed. (ScanReviewAndEmbedTest.php:96-128)

### Secrets — PASS
- Embed renders PUBLIC site_key only; widget_secret never passed/rendered. Snippet built with e() on scriptSrc + siteKey. (view.blade.php:12-17; embed-code.blade.php:29)
- Test asserts encrypted widget_secret (getRawOriginal) absent from output. (ScanReviewAndEmbedTest.php:153)
- SiteKeyRegenerator rotates PUBLIC key only via forceFill([site_key]); details:[] (key never logged); secret untouched (test confirms unchanged). Site model: widget_secret EncryptedString + in hidden. (SiteKeyRegenerator.php:33-49; Site.php:64-72)

### Template safety — PASS
- No Blade::render on any merchant value. Detected selector via auto-escaped {{ }}; snippet via e(); manual selector is a wire:model field, never compiled. (embed-code.blade.php:29; review-product.blade.php:156-158)

### Design system — PASS
- Zero inline style, zero arbitrary Tailwind, logical properties only, no hex/px/rgb in component CSS (vars only). CONST-at-top on every PHP file + token-reference header on every Blade/CSS.
- Confidence chip tones key off the §5 scan scale (to-conf--{level}) from ConfidenceLevel->level/->i18nKey(); not invented, not collapsed into StatusBadge. Derived non-machine states (setup_state ready/pending; product draft/confirmed/failed tone) documented as presentational. (confidence-chip.blade.php; SiteResource.php:100-105; view.blade.php:54-59)

### i18n 1:1 — PASS
- scan/embed/sites en↔he exact parity (0 drift both directions), real Hebrew, no English-leak. Confidence key verified: LEVEL_I18N_KEY[not_detected]=scan.confidence.none, present in both files.

### All states — PASS
- Scan rows: high/medium/low/not_detected + needs-review/reviewed + testing(spinner) + matched/multiple/not_found/error. Form: blocked(chips+disabled)/ready/confirming(wire:loading)/error(test line).
- Embed: default/copied(2s)/regenerate-confirm/regenerating/error. (view.blade.php:18-44; embed-code.blade.php:44-61)

Blocking: NONE.

Suggestions (recorded, do not gate):
- SUGGEST #1 (ViewSite.php:35,:51) widget loader src uses config(app.url) convention; real loader URL owned by widget-embed (Phase 9). Acceptable-for-now (documented, display-only, escalate-note present). MUST be repointed at the real loader origin before the widget ships. Forward-looking.
- SUGGEST #2 (review-product.blade.php:86-94) price row read-only because the writable column is price_minor and the form edits only WRITABLE_PRODUCT_COLUMNS. Acceptable-for-now (avoids malformed minor-unit write). Follow-up: editable price input that writes price_minor. Forward-looking.
- SUGGEST #3 (review-product.blade.php:174-183) Pick-on-page is a trigger+hint, not a working picker (needs the widget bridge, Phase 9). Acceptable-for-now (manual entry + test cover it; no false promise). Forward-looking.

Nits:
- NIT #1 (review-product.blade.php:227) open-gate-but-draft reuses scan.fields_sub as the green ready reason; reads oddly. Consider a dedicated scan.ready key. Cosmetic.

Re-review: not required (no blocking findings). Gate may flip green.
Recurring → archivist: none new. Register the good pattern: scalar-ids-in-Livewire + findOrFail-through-global-scope + a foreign-id-404 test is the reference tenancy shape for merchant Filament pages.
